<?php

namespace App\Http\Controllers;

use App\Http\Requests\HolidayRequest;
use App\Models\Holiday;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\RebuildMarks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leave > Holidays: the company holiday calendar, one year at a time. Every route is behind
 * can:settings.attendance-leave (Admin, HR). The model audits each change (area attendance).
 *
 * Adding, moving or removing a holiday flags that date's non-manual attendance days for a rebuild (both dates on a
 * move), closed days included: a holiday changes what the day was.
 */
class HolidayController extends Controller
{
    private const MIN_YEAR = 2000;

    private const MAX_YEAR = 2100;

    public function index(Request $request, AttendanceClock $clock): Response
    {
        $thisYear = (int) $clock->today()->format('Y');
        $year = $this->year($request->query('year'), $thisYear);

        $holidays = Holiday::query()
            ->whereBetween('date', ["{$year}-01-01", "{$year}-12-31"])
            ->orderBy('date')
            ->get()
            ->map(fn (Holiday $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'date' => $h->date->format('Y-m-d'),
                'weekday' => $h->date->format('l'),
            ])
            ->values()
            ->all();

        // Years that have holidays, plus last, this and next year (so next year's calendar can be started).
        $years = Holiday::query()->toBase()->pluck('date')
            ->map(fn ($date) => (int) substr((string) $date, 0, 4))
            ->merge([$thisYear - 1, $thisYear, $thisYear + 1, $year])
            ->unique()
            ->sort()
            ->values()
            ->all();

        return Inertia::render('holidays/index', [
            'holidays' => $holidays,
            'years' => $years,
            'filters' => ['year' => $year],
        ]);
    }

    public function store(HolidayRequest $request): RedirectResponse
    {
        $date = (string) $request->validated('date');

        try {
            DB::transaction(function () use ($request, $date): void {
                Holiday::create(['name' => $request->validated('name'), 'date' => $date]);
                RebuildMarks::forDate($date);
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['date' => 'There is already a holiday on this date.']);
        }

        return back()->with('success', 'Holiday added.');
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $oldDate = $holiday->date->format('Y-m-d');
        $newDate = (string) $request->validated('date');

        try {
            DB::transaction(function () use ($request, $holiday, $oldDate, $newDate): void {
                $holiday->update(['name' => $request->validated('name'), 'date' => $newDate]);

                if ($oldDate !== $newDate) {
                    RebuildMarks::forDate($oldDate);
                    RebuildMarks::forDate($newDate);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['date' => 'There is already a holiday on this date.']);
        }

        return back()->with('success', 'Holiday updated.');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $date = $holiday->date->format('Y-m-d');

        DB::transaction(function () use ($holiday, $date): void {
            $holiday->delete();
            RebuildMarks::forDate($date);
        });

        return back()->with('success', 'Holiday deleted.');
    }

    /** The year asked for, or this year when it is missing or unusable. */
    private function year(mixed $value, int $fallback): int
    {
        if (! is_string($value) || ! ctype_digit($value) || strlen($value) !== 4) {
            return $fallback;
        }

        $year = (int) $value;

        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR ? $year : $fallback;
    }
}
