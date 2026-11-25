<?php

namespace App\Http\Controllers\Attendance;

use App\Actions\Leave\WeeklyOffLeaveRefund;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShiftRequest;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Shift;
use App\Support\Attendance\RebuildMarks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance > Shifts. Every route is behind can:attendance.edit (Admin, HR). The model audits each change
 * (area attendance) inside the same transaction as the change.
 *
 * Changing a shift's rules flags today's and later pending days of its people for a rebuild (RebuildMarks); closed
 * days are not flagged, though something else (a late punch, a holiday change) may still recalculate them later.
 * A shift recorded on any attendance day cannot be deleted, so those days keep naming the shift they were worked with.
 *
 * Leave: when the weekly offs its people work change (a shift's weekly offs, or another default shift for people
 * without one), approved leave days that are now weekly offs are refunded from each person's first unclosed day
 * (WeeklyOffLeaveRefund, same transaction); the flash lists who, and the employees are mailed after the commit.
 */
class ShiftController extends Controller
{
    /** The columns whose change alters how a day is worked out (the name does not). */
    private const RULE_FIELDS = ['start_time', 'end_time', 'grace_minutes', 'half_day_hours', 'weekly_offs'];

    public function index(): Response
    {
        $withoutShift = Employee::query()->whereNull('shift_id')->count();

        $shifts = Shift::query()
            ->withCount('employees')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Shift $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'start_time' => $s->start_time,
                'end_time' => $s->end_time,
                'grace_minutes' => $s->grace_minutes,
                'half_day_hours' => (float) $s->half_day_hours,
                'weekly_offs' => $s->weekly_offs,
                'is_default' => $s->is_default,
                // People with no shift of their own work the default shift.
                'employees_count' => (int) $s->employees_count + ($s->is_default ? $withoutShift : 0),
            ])
            ->values()
            ->all();

        return Inertia::render('attendance/shifts', [
            'shifts' => $shifts,
            'weekdays' => config('hrm.attendance.weekdays'),
        ]);
    }

    public function store(ShiftRequest $request): RedirectResponse
    {
        try {
            DB::transaction(fn () => Shift::create([...$request->shiftAttributes(), 'is_default' => false]));
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => 'A shift with this name already exists.']);
        }

        return back()->with('success', 'Shift added.');
    }

    public function update(ShiftRequest $request, Shift $shift, WeeklyOffLeaveRefund $refund): RedirectResponse
    {
        try {
            // Retries: the leave refund locks employee and request rows, so it can deadlock with a leave decision.
            $changed = DB::transaction(function () use ($request, $shift, $refund): array {
                // Read again under a lock: a deadlock retry starts from the stored row (the bound model may already
                // hold the new values after a rolled-back attempt, so saving it would change nothing).
                $current = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
                $current->fill($request->shiftAttributes())->save();

                if ($current->wasChanged(self::RULE_FIELDS)) {
                    RebuildMarks::forShift($current);
                }

                // Approved leave on days that are now weekly offs is refunded from the first unclosed day.
                return $current->wasChanged('weekly_offs') ? $refund->forShift($current, $request->user()) : [];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => 'A shift with this name already exists.']);
        }

        $refund->mail($changed);

        return back()->with('success', 'Shift updated.'.WeeklyOffLeaveRefund::note($changed));
    }

    public function makeDefault(Request $request, Shift $shift, WeeklyOffLeaveRefund $refund): RedirectResponse
    {
        if ($shift->is_default) {
            return back()->with('info', "{$shift->name} is already the default shift.");
        }

        $changed = DB::transaction(function () use ($request, $shift, $refund): array {
            $before = Shift::default();
            Shift::makeDefault($shift);
            // People with no shift of their own now work this one.
            RebuildMarks::forEmployeesWithoutShift();

            // Their weekly offs changed with it: approved leave on days that are now offs is refunded.
            $offs = fn (Shift $s): array => collect((array) $s->weekly_offs)->map(fn ($d): string => strtolower((string) $d))->sort()->values()->all();

            return $offs($before) !== $offs($shift->fresh() ?? $shift) ? $refund->forEmployeesWithoutShift($request->user()) : [];
        }, 3);

        $refund->mail($changed);

        return back()->with('success', "{$shift->name} is now the default shift.".WeeklyOffLeaveRefund::note($changed));
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        // Asked inside the transaction, just before the delete. The employees' foreign key is null-on-delete (it is no
        // backstop), so the question is kept as close to the delete as possible.
        $reason = DB::transaction(function () use ($shift): ?string {
            $locked = Shift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();

            if ($reason = $this->deleteBlocker($locked)) {
                return $reason;
            }

            $locked->delete();

            return null;
        });

        return $reason !== null
            ? back()->with('error', $reason)
            : back()->with('success', 'Shift deleted.');
    }

    /** Why this shift cannot be deleted right now, or null when it can. */
    private function deleteBlocker(Shift $shift): ?string
    {
        if ($shift->is_default) {
            return 'The default shift cannot be deleted. Make another shift the default first.';
        }

        if ($shift->employees()->exists()) {
            return "Move this shift's employees first.";
        }

        // Deleting would null attendances.shift_id (null-on-delete) and lose which shift those days were worked out with.
        if (Attendance::query()->where('shift_id', $shift->id)->exists()) {
            return 'This shift is recorded on past attendance days and cannot be deleted.';
        }

        return null;
    }
}
