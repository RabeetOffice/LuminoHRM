<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveTypeRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\Attendance\AttendanceClock;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveDays;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leave > Leave Types. Every route is behind can:settings.attendance-leave (Admin, HR). The model audits each change
 * (area leave, "Leave type created / updated / deleted") inside the same transaction as the change.
 *
 * Adding a paid type creates no balance rows: they appear on demand and through the allocation job. Making a type
 * inactive keeps its history; inactive types are not offered when applying. A type with any request or balance row
 * cannot be deleted, only made inactive. The system Unpaid type is never deleted, never made paid and never made
 * inactive (it is the fallback staff are pointed to), and a type's paid flag is fixed once it has leave history (any
 * request or balance row). Every write goes through the model instance (fill/save, delete) so the model's guards and
 * audit always run.
 *
 * Carried days are derived when read, never frozen, so changing a type's carry-forward flag or cap changes the carried
 * days of every year, past ones included (plan open decision 6; owner default); nothing is rewritten here. Lowering the
 * cap or turning carry off warns first (owner decision 6, final fix round): when it would leave anyone's current or next
 * year overdrawn, the save is refused on `confirm_overdrawn` with "N people would be overdrawn — save anyway?" until the
 * dialog sends the confirmation.
 */
class LeaveTypeController extends Controller
{
    public const HISTORY_MESSAGE = 'This type has leave history. Make it inactive instead.';

    public const SYSTEM_DELETE_MESSAGE = 'The Unpaid leave type cannot be deleted: it is the type staff use when their paid leave runs out.';

    public const SYSTEM_PAID_MESSAGE = 'The Unpaid leave type is always unpaid.';

    public const SYSTEM_ACTIVE_MESSAGE = 'The Unpaid leave type is always active: it is the type staff use when their paid leave runs out.';

    public const PAID_LOCKED_MESSAGE = 'This type has leave history, so it cannot change between paid and unpaid.';

    public function index(): Response
    {
        $withRequests = LeaveRequest::query()->distinct()->pluck('leave_type_id')->flip();
        $withBalances = LeaveBalance::query()->distinct()->pluck('leave_type_id')->flip();

        $types = LeaveType::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (LeaveType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'yearly_quota' => $t->yearly_quota,
                'is_paid' => $t->is_paid,
                'carry_forward' => $t->carry_forward,
                'carry_forward_cap' => $t->carry_forward_cap,
                'is_active' => $t->is_active,
                'is_system' => $t->is_system,
                'colour' => $t->colour,
                'has_requests' => $withRequests->has($t->id),
                'has_history' => $withRequests->has($t->id) || $withBalances->has($t->id),
            ])
            ->values()
            ->all();

        return Inertia::render('leave/types', [
            'types' => $types,
            'colours' => array_values((array) config('hrm.leave.colours')),
        ]);
    }

    public function store(LeaveTypeRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request): void {
                $next = (int) LeaveType::query()->max('sort_order') + 1;

                // is_system is never taken from a request: only the seeded Unpaid type has it (column default false).
                LeaveType::create([...$request->typeAttributes(), 'sort_order' => $next]);
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => 'A leave type with this name already exists.']);
        }

        return back()->with('success', 'Leave type added.');
    }

    public function update(LeaveTypeRequest $request, LeaveType $leaveType, LeaveBalances $balances): RedirectResponse
    {
        $attributes = $request->typeAttributes();
        $confirmed = $request->boolean('confirm_overdrawn');

        try {
            $refusal = DB::transaction(function () use ($leaveType, $attributes, $confirmed, $balances): ?string {
                $locked = LeaveType::query()->whereKey($leaveType->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->is_system && $attributes['is_paid']) {
                    return self::SYSTEM_PAID_MESSAGE;
                }

                if ($locked->is_system && ! $attributes['is_active']) {
                    return self::SYSTEM_ACTIVE_MESSAGE;
                }

                if ($locked->is_paid !== $attributes['is_paid'] && $this->hasHistory($locked)) {
                    return self::PAID_LOCKED_MESSAGE;
                }

                // Lowering the cap or turning carry off lowers carried days (derived): people may end overdrawn this
                // year or next. Counted before and after the change (rolled back unless confirmed; decision 6).
                $lowersCarry = $locked->is_paid && $locked->carry_forward
                    && (! $attributes['carry_forward'] || LeaveDays::halves($attributes['carry_forward_cap']) < LeaveDays::halves($locked->carry_forward_cap));
                $before = $lowersCarry && ! $confirmed ? $this->remainingByPerson($locked, $balances) : null;

                $locked->fill($attributes)->save();

                if ($before !== null) {
                    $people = $this->newlyOverdrawn($before, $this->remainingByPerson($locked, $balances));

                    if ($people > 0) {
                        throw ValidationException::withMessages([
                            'confirm_overdrawn' => ($people === 1 ? '1 person' : "{$people} people").' would be overdrawn — save anyway?',
                        ]);
                    }
                }

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => 'A leave type with this name already exists.']);
        }

        if ($refusal !== null) {
            return back()->withErrors([$refusal === self::SYSTEM_ACTIVE_MESSAGE ? 'is_active' : 'is_paid' => $refusal]);
        }

        return back()->with('success', 'Leave type updated.');
    }

    /**
     * Remaining days of this type per person for the current and next leave year (employee id => year => remaining),
     * for everyone with a balance row of the type from last year to next year. Fixed queries (LeaveBalances::forEmployees).
     *
     * @return array<int, array<int, float>>
     */
    private function remainingByPerson(LeaveType $type, LeaveBalances $balances): array
    {
        $current = (int) substr(app(AttendanceClock::class)->attendanceToday(), 0, 4);
        $employees = Employee::query()
            ->whereIn('id', LeaveBalance::query()->select('employee_id')->where('leave_type_id', $type->id)->whereBetween('year', [$current - 1, $current + 1]))
            ->get();

        if ($employees->isEmpty()) {
            return [];
        }

        $remaining = [];

        foreach ([$current, $current + 1] as $year) {
            foreach ($balances->forEmployees($employees, $year) as $id => $lines) {
                if (isset($lines[$type->id])) {
                    $remaining[$id][$year] = $lines[$type->id]->remaining;
                }
            }
        }

        return $remaining;
    }

    /**
     * People whose current or next year ends overdrawn after the change and lower than before.
     *
     * @param  array<int, array<int, float>>  $before
     * @param  array<int, array<int, float>>  $after
     */
    private function newlyOverdrawn(array $before, array $after): int
    {
        $count = 0;

        foreach ($after as $id => $years) {
            foreach ($years as $year => $remaining) {
                if ($remaining < 0 && $remaining < ($before[$id][$year] ?? 0.0)) {
                    $count++;

                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Make a type active or inactive, touching nothing else (the "Make inactive" action on the list: it must not send
     * back a possibly stale copy of every other field). The system Unpaid type is never made inactive.
     */
    public function setActive(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        $refusal = DB::transaction(function () use ($leaveType, $active): ?string {
            $locked = LeaveType::query()->whereKey($leaveType->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->is_system && ! $active) {
                return self::SYSTEM_ACTIVE_MESSAGE;
            }

            $locked->fill(['is_active' => $active])->save();

            return null;
        });

        if ($refusal !== null) {
            return back()->withErrors(['is_active' => $refusal]);
        }

        return back()->with('success', $active ? 'Leave type made active.' : 'Leave type made inactive.');
    }

    public function destroy(LeaveType $leaveType): RedirectResponse
    {
        // Asked inside the transaction, just before the delete. The foreign keys restrict the delete as a backstop: a
        // request or balance row written between the check and the delete makes it fail with an integrity violation,
        // which is answered with the same history message instead of an error page.
        try {
            $reason = DB::transaction(function () use ($leaveType): ?string {
                $locked = LeaveType::query()->whereKey($leaveType->getKey())->lockForUpdate()->firstOrFail();

                if ($reason = $this->deleteBlocker($locked)) {
                    return $reason;
                }

                $locked->delete();

                return null;
            });
        } catch (QueryException $e) {
            if (! self::isIntegrityViolation($e)) {
                throw $e;
            }

            $reason = self::HISTORY_MESSAGE;
        }

        return $reason !== null
            ? back()->with('error', $reason)
            : back()->with('success', 'Leave type deleted.');
    }

    /** Why this type cannot be deleted right now, or null when it can. */
    private function deleteBlocker(LeaveType $type): ?string
    {
        if ($type->is_system) {
            return self::SYSTEM_DELETE_MESSAGE;
        }

        if ($this->hasHistory($type)) {
            return self::HISTORY_MESSAGE;
        }

        return null;
    }

    /** Any request (any status) or balance row of this type. */
    private function hasHistory(LeaveType $type): bool
    {
        return $type->requests()->exists() || $type->balances()->exists();
    }

    /** SQLSTATE class 23 (integrity constraint violation), e.g. a foreign key still referencing the row. */
    private static function isIntegrityViolation(QueryException $e): bool
    {
        $state = $e->errorInfo[0] ?? (string) $e->getCode();

        return str_starts_with((string) $state, '23');
    }
}
