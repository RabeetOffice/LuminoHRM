<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShiftRequest;
use App\Models\Employee;
use App\Models\Shift;
use App\Support\Attendance\RebuildMarks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance > Shifts. Every route is behind can:attendance.edit (Admin, HR). The model audits each change
 * (area attendance) inside the same transaction as the change.
 *
 * Changing a shift's rules flags today's and later pending days of its people for a rebuild (RebuildMarks); closed
 * days keep the values they were closed with.
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

    public function update(ShiftRequest $request, Shift $shift): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $shift): void {
                $shift->fill($request->shiftAttributes())->save();

                if ($shift->wasChanged(self::RULE_FIELDS)) {
                    RebuildMarks::forShift($shift);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => 'A shift with this name already exists.']);
        }

        return back()->with('success', 'Shift updated.');
    }

    public function makeDefault(Shift $shift): RedirectResponse
    {
        if ($shift->is_default) {
            return back()->with('info', "{$shift->name} is already the default shift.");
        }

        DB::transaction(function () use ($shift): void {
            Shift::makeDefault($shift);
            // People with no shift of their own now work this one.
            RebuildMarks::forEmployeesWithoutShift();
        });

        return back()->with('success', "{$shift->name} is now the default shift.");
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

        return null;
    }
}
