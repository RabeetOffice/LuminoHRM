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
 * used, with a reason. In ONE transaction: the row is locked (lockForUpdate), created when missing
 * (LeaveBalances::ensureRow) only if a figure actually changes, saved, and audited "Leave balance adjusted" (subject the
 * employee; `old` / `attributes` of the three fields, the year, the type and the reason). Nothing is written or audited
 * when no figure changes. The dialog sends the figures it was opened with: if the locked row differs, STALE.
 *
 * Overdrawn (plan open decision 17, final fix round M2): when the new available (remaining − pending, LeaveBalances::line,
 * the one formula: what applying and approving check) is below zero and the caller did not confirm, a ValidationException
 * on `confirm_overdrawn` rolls the save back. The same for every later year up to LeaveBalances::lastYearInUse() of a
 * carry-forward type with leave taken or pending whose available falls below zero (and lower than before) through the
 * carry; that message names the year. A confirmed save skips these checks entirely (the import confirms every row; it
 * flags overdrawn rows in its preview).
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

    public const STALE = 'This balance changed since you opened it. Reopen it.';

    private const FIELDS = ['allocated', 'adjustment', 'opening_used'];

    private ?LeaveRouting $routing = null;

    private bool $changed = false;

    public function __construct(private readonly LeaveBalances $balances) {}

    /**
     * @throws AuthorizationException the user may not administer this employee's leave
     * @throws ValidationException the result is overdrawn and $confirmOverdrawn is false
     * @throws InvalidArgumentException an unpaid type
     */
    /**
     * $expected: the figures the form was opened with (the projection when there was no row). When the row now holds
     * anything else (someone saved in between), a ValidationException on `balance` (STALE) and nothing is saved.
     *
     * @param  array{allocated: float|int|string, adjustment: float|int|string, opening_used: float|int|string}|null  $expected
     */
    public function handle(Employee $employee, LeaveType $type, int $year, float $allocated, float $adjustment, float $openingUsed, string $reason, bool $confirmOverdrawn, User $by, ?array $expected = null): LeaveBalance
    {
        return $this->apply($employee, $type, $year, fn (LeaveBalance $row): array => [
            'allocated' => $allocated,
            'adjustment' => $adjustment,
            'opening_used' => $openingUsed,
        ], $reason, $confirmOverdrawn, $by, $expected);
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
     * @param  array{allocated: float|int|string, adjustment: float|int|string, opening_used: float|int|string}|null  $expected
     */
    private function apply(Employee $employee, LeaveType $type, int $year, \Closure $values, string $reason, bool $confirmOverdrawn, User $by, ?array $expected = null): LeaveBalance
    {
        if ($type->isUnpaid()) {
            throw new InvalidArgumentException("The unpaid leave type [{$type->name}] has no balance rows.");
        }

        $this->routing ??= new LeaveRouting;

        if (! $this->routing->mayAdminister($by, $employee)) {
            throw new AuthorizationException('You cannot change this person\'s balance.');
        }

        $this->changed = false;

        return DB::transaction(function () use ($employee, $type, $year, $values, $reason, $confirmOverdrawn, $by, $expected): LeaveBalance {
            $this->changed = false;

            // First statement of the transaction (before any plain read): the employee row lock serialises this with
            // applying / approving for the same person, and on MariaDB starts the snapshot after their commit.
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first(['id']);

            $row = $this->lockedRow($employee, $type, $year);

            // No row yet: compare against what ensureRow would create, and create it only when a figure changes (a
            // projected cell saved as it is writes nothing).
            $current = $row ?? (new LeaveBalance)->forceFill([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'year' => $year,
                'allocated' => $this->balances->allocationFor($employee, $type, $year),
                'adjustment' => 0,
                'opening_used' => 0,
            ]);

            $old = self::figures($current);

            if ($expected !== null && self::figures($expected) !== $old) {
                throw ValidationException::withMessages(['balance' => self::STALE]);
            }

            $new = array_map(fn ($v): float => LeaveDays::fromHalves(LeaveDays::halves($v)), $values($current));

            if ($old === $new) {
                return $current;
            }

            // Later years' carry (plan ruling B, final fix round M2): lowering this year's figures of a carry-forward type
            // can overdraw any later year in use through the carry.
            $laterBefore = ! $confirmOverdrawn ? $this->laterAvailable($employee, $type, $year) : [];

            if ($row === null) {
                $this->balances->ensureRow($employee, $type, $year);
                $row = $this->lockedRow($employee, $type, $year) ?? throw new \LogicException('The balance row was not created.');
                $old = self::figures($row);
            }

            $row->forceFill($new)->save();

            if (! $confirmOverdrawn) {
                $line = $this->balances->line($employee, $type, $year);

                if ($line->available < 0) {
                    throw ValidationException::withMessages([
                        'confirm_overdrawn' => self::overdrawnMessage($employee, $line->available),
                    ]);
                }

                foreach ($laterBefore as $later => $before) {
                    $after = $this->balances->line($employee, $type, $later)->available;

                    if ($after < 0 && $after < $before) {
                        throw ValidationException::withMessages([
                            'confirm_overdrawn' => self::overdrawnMessage($employee, $after, $later),
                        ]);
                    }
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

    /**
     * "This leaves Sara overdrawn by 1.5 days. Tick the box to confirm." With $year (the following year, through the
     * carry): "This leaves Sara overdrawn by 1.5 days in 2027. Tick the box to confirm."
     */
    public static function overdrawnMessage(Employee $employee, float $remaining, ?int $year = null): string
    {
        $days = BalanceLine::format(abs($remaining));
        $noun = $days === '1' ? 'day' : 'days';
        $in = $year !== null ? " in {$year}" : '';

        return "This leaves {$employee->first_name} overdrawn by {$days} {$noun}{$in}. Tick the box to confirm.";
    }

    private function lockedRow(Employee $employee, LeaveType $type, int $year): ?LeaveBalance
    {
        return LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The available days of every later year this edit can reach through the carry (a carry-forward type; years after
     * $year up to LeaveBalances::lastYearInUse() with leave taken or pending), before the edit: year => available.
     *
     * @return array<int, float>
     */
    private function laterAvailable(Employee $employee, LeaveType $type, int $year): array
    {
        if (! $type->carry_forward) {
            return [];
        }

        $last = $this->balances->lastYearInUse($employee, $type) ?? $year;
        $available = [];

        for ($later = $year + 1; $later <= $last; $later++) {
            $line = $this->balances->line($employee, $type, $later);

            // Only a year with leave taken or pending can end overdrawn by it (a bare row HR set short is HR's call).
            if ($line->used > 0 || $line->pending > 0) {
                $available[$later] = $line->available;
            }
        }

        return $available;
    }

    /**
     * The three figures in whole half days turned back into floats, comparable with ===.
     *
     * @param  LeaveBalance|array<string, mixed>  $source
     * @return array{allocated: float, adjustment: float, opening_used: float}
     */
    private static function figures(LeaveBalance|array $source): array
    {
        $figures = [];

        foreach (self::FIELDS as $field) {
            $value = $source instanceof LeaveBalance ? $source->{$field} : ($source[$field] ?? 0);
            $figures[$field] = LeaveDays::fromHalves(LeaveDays::halves($value));
        }

        /** @var array{allocated: float, adjustment: float, opening_used: float} $figures */
        return $figures;
    }
}
