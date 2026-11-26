<?php

namespace App\Http\Controllers;

use App\Actions\Leave\HolidayLeaveRefund;
use App\Actions\Leave\LeaveDayRemoval;
use App\Http\Requests\HolidayRequest;
use App\Mail\LeaveRequestDecided;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\RebuildMarks;
use App\Support\EmployeeAccess;
use App\Support\Leave\LeaveDays;
use App\Support\PayrollLock;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
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
 * (HolidayLeaveRefund, in the same transaction; an open request whose every day became a holiday is cancelled) and
 * mails each employee after the commit. Removing a holiday adds nothing back; the list sends, per holiday, the people
 * with approved leave around its date (`affected_leave`, people this user may see) for the delete confirmation.
 *
 * Payroll lock: a holiday cannot be added on, moved into or out of, renamed on or deleted from a date in a month whose
 * payroll is approved or paid ([date] PayrollLock::message()). The check is the first statement of each transaction
 * (shared lock on payroll_runs before the holiday, attendance and leave row locks).
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
            $changed = DB::transaction(function () use ($request, $refund, $date, $name): array {
                self::refuseLocked([$date]);

                Holiday::create(['name' => $name, 'date' => $date]);
                RebuildMarks::forDate($date);

                return $refund->forDate($date, $name, $request->user());
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['date' => 'There is already a holiday on this date.']);
        }

        $this->mailChanges($changed, $name, $date);

        return $this->toYearOf($date)->with('success', 'Holiday added.'.$this->refundNote($changed));
    }

    public function update(HolidayRequest $request, Holiday $holiday, HolidayLeaveRefund $refund): RedirectResponse
    {
        $newDate = (string) $request->validated('date');
        $name = (string) $request->validated('name');
        $boundDate = $holiday->date->format('Y-m-d');

        try {
            $changed = DB::transaction(function () use ($request, $holiday, $refund, $newDate, $name, $boundDate): array {
                // The payroll lock first (both dates, one query), then the row.
                self::refuseLocked([$boundDate, $newDate]);

                // Read and lock the row inside the transaction: a deadlock retry runs this again from the stored row
                // (the bound model may already hold the new values after a rolled-back attempt, so updating it would
                // write nothing).
                $current = Holiday::query()->whereKey($holiday->id)->lockForUpdate()->firstOrFail();
                $oldDate = $current->date->format('Y-m-d');

                // Moved by someone else since the page was read: its stored date is checked too.
                if ($oldDate !== $boundDate && $oldDate !== $newDate) {
                    self::refuseLocked([$oldDate]);
                }
                $current->update(['name' => $name, 'date' => $newDate]);

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

        $this->mailChanges($changed, $name, $newDate);

        return $this->toYearOf($newDate)->with('success', 'Holiday updated.'.$this->refundNote($changed));
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $date = $holiday->date->format('Y-m-d');

        DB::transaction(function () use ($holiday, $date): void {
            self::refuseLocked([$date]);

            $holiday->delete();
            RebuildMarks::forDate($date);
        });

        return back()->with('success', 'Holiday deleted.');
    }

    /**
     * Refuses when any of these dates ("Y-m-d") is in a locked payroll month: ValidationException [date] with
     * PayrollLock::message(). One query.
     *
     * @param  list<string>  $dates
     *
     * @throws ValidationException
     */
    private static function refuseLocked(array $dates): void
    {
        $locked = PayrollLock::firstLocked($dates);

        if ($locked !== null) {
            throw ValidationException::withMessages(['date' => PayrollLock::message($locked)]);
        }
    }

    /**
     * Per holiday of the year: the people (this user may see) with approved leave around its date, for the delete
     * confirmation. A request counts when its range covers the date and it was not cancelled from that date or earlier
     * (a partly cancelled request whose remaining days end before the holiday is not around it). Each person once per
     * holiday. One query, limited to ranges covering one of the holiday dates.
     *
     * (Leave days on the date itself are no guide: a holiday date holds no leave day, since counting skips holidays and
     * adding a holiday refunds them.)
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
            ->where(function ($q) use ($dates): void {
                foreach ($dates as $date) {
                    $q->orWhere(fn ($q) => $q->where('from_date', '<=', $date)->where('to_date', '>=', $date));
                }
            })
            ->whereIn('employee_id', (new EmployeeAccess($request->user()))->scope(Employee::query()->select('id')))
            ->with('employee')
            ->orderBy('id')
            ->get(['id', 'employee_id', 'from_date', 'to_date', 'cancelled_from']);

        $byDate = [];

        foreach ($dates as $date) {
            $rows = [];

            foreach ($requests as $r) {
                $cancelledFrom = $r->cancelled_from?->format('Y-m-d');

                if ($r->from_date->format('Y-m-d') > $date || $r->to_date->format('Y-m-d') < $date
                    || ($cancelledFrom !== null && $cancelledFrom <= $date) || isset($rows[$r->employee_id])) {
                    continue;
                }

                $rows[$r->employee_id] = ['request_id' => $r->id, 'employee' => (string) $r->employee?->full_name];
            }

            $byDate[$date] = array_values(collect($rows)
                ->sortBy(fn (array $row): string => mb_strtolower($row['employee']).sprintf('%010d', $row['request_id']))
                ->all());
        }

        return $byDate;
    }

    /**
     * After the commit, to each employee's active login (no login, or an inactive one: no mail): `refunded` (days of
     * approved leave went back), `cancelled` (approved leave lost every day) or `withdrawn` (an open request whose every
     * day became a holiday).
     *
     * @param  list<array{request: LeaveRequest, outcome: string, refunded: float}>  $changed
     */
    private function mailChanges(array $changed, string $holiday, string $date): void
    {
        if ($changed === []) {
            return;
        }

        $on = "{$holiday} on ".(new DateTimeImmutable($date))->format('D j M Y');
        $users = User::query()
            ->whereIn('employee_id', array_map(fn (array $c): int => (int) $c['request']->employee_id, $changed))
            ->where('is_active', true)
            ->get()
            ->keyBy('employee_id');

        foreach ($changed as ['request' => $request, 'outcome' => $outcome]) {
            $user = $users->get($request->employee_id);

            if ($user === null) {
                continue;
            }

            $note = $outcome === 'refunded' ? $on : "{$on}. ".HolidayLeaveRefund::CANCEL_REASON;

            try {
                Mail::to($user)->queue(new LeaveRequestDecided($request, $user->name, $outcome, $note));
            } catch (Throwable $e) {
                report($e); // a mail that cannot be queued never undoes the holiday
            }
        }
    }

    /**
     * " 2 people · 1.5 days went back to their balance." People and days of APPROVED leave refunded; an open request
     * cancelled here held nothing.
     *
     * @param  list<array{request: LeaveRequest, outcome: string, refunded: float, manual?: list<string>}>  $changed
     */
    private function refundNote(array $changed): string
    {
        $people = [];
        $halves = 0;
        $manual = '';

        foreach ($changed as $c) {
            if ($c['refunded'] > 0) {
                $people[(int) $c['request']->employee_id] = true;
                $halves += LeaveDays::halves($c['refunded']);
            }

            // A hand-corrected attendance day keeps its leave status: HR is told to check it.
            $manual .= LeaveDayRemoval::manualNote($c['manual'] ?? [], $c['request']->employee?->full_name);
        }

        if ($halves === 0) {
            return $manual;
        }

        $count = count($people);
        $days = rtrim(rtrim(number_format(LeaveDays::fromHalves($halves), 1, '.', ''), '0'), '.');

        return sprintf(' %d %s · %s %s went back to their balance.',
            $count, $count === 1 ? 'person' : 'people', $days, $halves === 2 ? 'day' : 'days').$manual;
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
