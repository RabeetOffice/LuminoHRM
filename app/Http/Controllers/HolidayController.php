<?php

namespace App\Http\Controllers;

use App\Actions\Leave\HolidayLeaveRefund;
use App\Http\Requests\HolidayRequest;
use App\Mail\LeaveRequestDecided;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\RebuildMarks;
use App\Support\EmployeeAccess;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Leave > Holidays: the company holiday calendar, one year at a time. Every route is behind
 * can:settings.attendance-leave (Admin, HR). The model audits each change (area attendance).
 *
 * Adding, moving or removing a holiday flags that date's non-manual attendance days for a rebuild (both dates on a
 * move), closed days included: a holiday changes what the day was.
 *
 * Leave (Task 8): a holiday added on, or moved onto, a date with approved leave days refunds those days
 * (HolidayLeaveRefund, in the same transaction) and mails each employee after the commit. Removing a holiday adds
 * nothing back; the list sends, per holiday, the approved requests covering its date (`affected_leave`, people this
 * user may see) for the delete confirmation.
 */
class HolidayController extends Controller
{
    private const MIN_YEAR = 2000;

    private const MAX_YEAR = 2100;

    public function index(Request $request, AttendanceClock $clock): Response
    {
        $thisYear = (int) $clock->today()->format('Y');
        $year = $this->year($request->query('year'), $thisYear);

        $rows = Holiday::query()
            ->whereBetween('date', ["{$year}-01-01", "{$year}-12-31"])
            ->orderBy('date')
            ->get();

        $affected = $this->affectedLeave($request, array_values($rows->map(fn (Holiday $h): string => $h->date->format('Y-m-d'))->all()));

        $holidays = $rows
            ->map(fn (Holiday $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'date' => $h->date->format('Y-m-d'),
                'weekday' => $h->date->format('l'),
                'affected_leave' => $affected[$h->date->format('Y-m-d')] ?? [],
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

    public function store(HolidayRequest $request, HolidayLeaveRefund $refund): RedirectResponse
    {
        $date = (string) $request->validated('date');
        $name = (string) $request->validated('name');

        try {
            // Retries: the refund locks employee rows after leave rows, so it can deadlock with a leave approval.
            $touched = DB::transaction(function () use ($request, $refund, $date, $name): array {
                Holiday::create(['name' => $name, 'date' => $date]);
                RebuildMarks::forDate($date);

                return $refund->forDate($date, $name, $request->user());
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['date' => 'There is already a holiday on this date.']);
        }

        $this->mailRefunds($touched, $name, $date);

        return $this->toYearOf($date)->with('success', 'Holiday added.'.$this->refundNote($touched));
    }

    public function update(HolidayRequest $request, Holiday $holiday, HolidayLeaveRefund $refund): RedirectResponse
    {
        $oldDate = $holiday->date->format('Y-m-d');
        $newDate = (string) $request->validated('date');
        $name = (string) $request->validated('name');

        try {
            $touched = DB::transaction(function () use ($request, $holiday, $refund, $oldDate, $newDate, $name): array {
                $holiday->update(['name' => $name, 'date' => $newDate]);

                if ($oldDate === $newDate) {
                    return [];
                }

                RebuildMarks::forDate($oldDate);
                RebuildMarks::forDate($newDate);

                // Leave on the new date is refunded; the old date gets nothing back (as on delete).
                return $refund->forDate($newDate, $name, $request->user());
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['date' => 'There is already a holiday on this date.']);
        }

        $this->mailRefunds($touched, $name, $newDate);

        return $this->toYearOf($newDate)->with('success', 'Holiday updated.'.$this->refundNote($touched));
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

    /**
     * Per holiday of the year: the approved requests (of people this user may see) whose range covers its date, for the
     * delete confirmation. One query for the year's requests.
     *
     * @param  list<string>  $dates  the holidays' "Y-m-d" dates
     * @return array<string, list<array{request_id: int, employee: string}>> by date
     */
    private function affectedLeave(Request $request, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $requests = LeaveRequest::query()
            ->where('status', 'approved')
            ->where('from_date', '<=', max($dates))
            ->where('to_date', '>=', min($dates))
            ->whereIn('employee_id', (new EmployeeAccess($request->user()))->scope(Employee::query()->select('id')))
            ->with('employee')
            ->get(['id', 'employee_id', 'from_date', 'to_date']);

        $byDate = [];

        foreach ($dates as $date) {
            $byDate[$date] = array_values($requests
                ->filter(fn (LeaveRequest $r): bool => $r->from_date->format('Y-m-d') <= $date && $r->to_date->format('Y-m-d') >= $date)
                ->map(fn (LeaveRequest $r): array => ['request_id' => $r->id, 'employee' => (string) $r->employee?->full_name])
                ->sortBy(fn (array $row): string => mb_strtolower($row['employee']).sprintf('%010d', $row['request_id']))
                ->all());
        }

        return $byDate;
    }

    /** @param  list<LeaveRequest>  $touched */
    private function mailRefunds(array $touched, string $holiday, string $date): void
    {
        if ($touched === []) {
            return;
        }

        $label = (new DateTimeImmutable($date))->format('D j M Y');
        $users = User::query()
            ->whereIn('employee_id', array_map(fn (LeaveRequest $r): int => (int) $r->employee_id, $touched))
            ->where('is_active', true)
            ->get()
            ->keyBy('employee_id');

        foreach ($touched as $request) {
            $user = $users->get($request->employee_id);

            if ($user === null) {
                continue; // no active login: no mail (the usual rule)
            }

            try {
                Mail::to($user)->queue(new LeaveRequestDecided($request, $user->name, 'refunded', "{$holiday} on {$label}"));
            } catch (Throwable $e) {
                report($e); // a mail that cannot be queued never undoes the holiday
            }
        }
    }

    /** @param  list<LeaveRequest>  $touched */
    private function refundNote(array $touched): string
    {
        $count = count($touched);

        return match (true) {
            $count === 0 => '',
            $count === 1 => ' 1 approved leave day on that date went back to its balance; the employee was told.',
            default => " Approved leave on that date went back to {$count} balances; each employee was told.",
        };
    }

    /** The list for the year of a saved date, so a holiday saved into another year does not seem to vanish. */
    private function toYearOf(string $date): RedirectResponse
    {
        return to_route('holidays.index', ['year' => (int) substr($date, 0, 4)]);
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
