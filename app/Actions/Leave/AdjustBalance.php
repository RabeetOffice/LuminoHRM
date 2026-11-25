<?php

namespace App\Actions\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Leave\BalanceLine;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveDays;
use App\Support\Leave\LeaveRouting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * HR's edit of one balance row (Leave > Balances, and the opening-balance import): allocated, adjustment and opening
 * used, with a reason. In ONE transaction: the row is created when missing (LeaveBalances::ensureRow), then locked
 * (lockForUpdate), saved, and audited "Leave balance adjusted" (subject the employee; `old` / `attributes` of the three
 * fields, the year, the type and the reason). Nothing is written or audited when no figure changes.
 *
 * Overdrawn (plan open decision 17): when the new remaining (LeaveBalances::line, the one formula) is below zero and the
 * caller did not confirm, a ValidationException on `confirm_overdrawn` rolls the save back. A confirmed save skips that
 * check entirely (the import confirms every row; it flags overdrawn rows in its preview).
 *
 * Who may (plan open decision 18): LeaveRouting::mayAdminister (leave.approve-all; never one's own balance unless Admin;
 * an Admin's or HR person's balance only by an Admin), checked again here so no caller can skip it. Paid types only:
 * unpaid types have no balance rows.
 *
 * One instance per request or import run (it keeps one LeaveRouting, whose privileged-login map is one query).
 */
class AdjustBalance
{
    public const OPENING_IMPORT_REASON = 'Opening balance import';

    private const FIELDS = ['allocated', 'adjustment', 'opening_used'];

    private ?LeaveRouting $routing = null;

    private bool $changed = false;

    public function __construct(private readonly LeaveBalances $balances) {}

    /**
     * @throws AuthorizationException the user may not administer this employee's leave
     * @throws ValidationException the result is overdrawn and $confirmOverdrawn is false
     * @throws InvalidArgumentException an unpaid type
     */
    public function handle(Employee $employee, LeaveType $type, int $year, float $allocated, float $adjustment, float $openingUsed, string $reason, bool $confirmOverdrawn, User $by): LeaveBalance
    {
        return $this->apply($employee, $type, $year, fn (LeaveBalance $row): array => [
            'allocated' => $allocated,
            'adjustment' => $adjustment,
            'opening_used' => $openingUsed,
        ], $reason, $confirmOverdrawn, $by);
    }

    /**
     * Sets only opening used (the import: "days used so far this year"), keeping the row's allocated and adjustment as
     * they are under the lock. Setting, never adding, so importing the same file twice changes nothing the second time.
     */
    public function setOpeningUsed(Employee $employee, LeaveType $type, int $year, float $openingUsed, string $reason, bool $confirmOverdrawn, User $by): LeaveBalance
    {
        return $this->apply($employee, $type, $year, fn (LeaveBalance $row): array => [
            'allocated' => (float) $row->allocated,
            'adjustment' => (float) $row->adjustment,
            'opening_used' => $openingUsed,
        ], $reason, $confirmOverdrawn, $by);
    }

    /** Whether the last call changed a figure (false: the row already held these values; nothing written). */
    public function changed(): bool
    {
        return $this->changed;
    }

    /**
     * @param  \Closure(LeaveBalance): array{allocated: float, adjustment: float, opening_used: float}  $values
     */
    private function apply(Employee $employee, LeaveType $type, int $year, \Closure $values, string $reason, bool $confirmOverdrawn, User $by): LeaveBalance
    {
        if ($type->isUnpaid()) {
            throw new InvalidArgumentException("The unpaid leave type [{$type->name}] has no balance rows.");
        }

        $this->routing ??= new LeaveRouting;

        if (! $this->routing->mayAdminister($by, $employee)) {
            throw new AuthorizationException('You cannot change this person\'s balance.');
        }

        $this->changed = false;

        return DB::transaction(function () use ($employee, $type, $year, $values, $reason, $confirmOverdrawn, $by): LeaveBalance {
            $this->changed = false;

            // First statement of the transaction (before any plain read): the employee row lock serialises this with
            // applying / approving for the same person, and on MariaDB starts the snapshot after their commit.
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first(['id']);

            $this->balances->ensureRow($employee, $type, $year);

            $row = LeaveBalance::query()
                ->where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            $new = array_map(fn ($v): float => LeaveDays::fromHalves(LeaveDays::halves($v)), $values($row));
            $old = [];

            foreach (self::FIELDS as $field) {
                $old[$field] = LeaveDays::fromHalves(LeaveDays::halves($row->{$field}));
            }

            if ($old === $new) {
                return $row;
            }

            $row->forceFill($new)->save();

            if (! $confirmOverdrawn) {
                $line = $this->balances->line($employee, $type, $year);

                if ($line->remaining < 0) {
                    throw ValidationException::withMessages([
                        'confirm_overdrawn' => self::overdrawnMessage($employee, $line->remaining),
                    ]);
                }
            }

            Audit::record('leave', 'Leave balance adjusted', $employee, [
                'year' => $year,
                'type' => $type->name,
                'type_id' => $type->id,
                'reason' => Audit::clean($reason),
                'old' => $old,
                'attributes' => $new,
            ], $by);

            $this->changed = true;

            return $row;
        });
    }

    /** "This leaves Sara overdrawn by 1.5 days. Tick the box to confirm." */
    public static function overdrawnMessage(Employee $employee, float $remaining): string
    {
        $days = BalanceLine::format(abs($remaining));
        $noun = $days === '1' ? 'day' : 'days';

        return "This leaves {$employee->first_name} overdrawn by {$days} {$noun}. Tick the box to confirm.";
    }
}
