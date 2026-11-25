<?php

namespace App\Http\Controllers\Attendance;

use App\Actions\Attendance\ApplyManualCorrection;
use App\Http\Controllers\Controller;
use App\Http\Requests\ManualCorrectionRequest;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Attendance\Leavers;
use App\Support\Audit\Audit;
use App\Support\EmployeeAccess;
use App\Support\EmployeeFiles;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Attendance > Daily: one day's attendance for the people the viewer may see, and the manual correction of a day.
 *
 *   GET    /attendance/daily?date=Y-m-d          attendance.view-all or attendance.view-team, scoped by EmployeeAccess
 *   PUT    /attendance/daily/{employee}/{date}    attendance.edit (+ scope): ApplyManualCorrection
 *   DELETE /attendance/daily/{employee}/{date}    attendance.edit (+ scope): clear the manual flag, rebuild from punches
 *
 * Who is listed: everyone in scope who has a row that day, plus, once attendance has started (attendance.start_date set
 * and not after the day), everyone employed that day who should have one (joined, not past the last working day, not
 * inactive / resigned / terminated without a last working day).  * A listed person with no row is "Not in yet" on the open
 * day (AttendanceClock::attendanceToday()) and "No record" on a past day (the nightly close has not run); both match
 * the "not_in" filter. On an open day that is a holiday, or a weekly off of the person's shift, a person with no row
 * shows Holiday / Weekend instead and is not counted as missing; nor is an attendance-exempt person with no row on the
 * open day (shown as Exempt, as the dashboard leaves them out of "expected"). A day before the attendance start date
 * lists the rows it has (punch days) with before_start = true and no Edit action. A future date is refused.
 *
 * Who may correct whom (update, reset, and each row's can_edit): attendance.edit, the scope, ApplyManualCorrection::
 * mayCorrect (not your own record unless Admin; an Admin / HR login only by an Admin) and a day the person can be
 * corrected on (ApplyManualCorrection::employmentRefusal: not before max(joining date, attendance start date), not
 * after the last working day, not someone who left without a last working day).
 *
 * The page reads two queries for the people and their rows (plus the fixed lookups: departments, the default shift),
 * never one per row. The summary counts the whole day in scope; the filters only narrow the table.
 */
class DailyController extends Controller
{
    /** Statuses counted together under one chip. */
    private const OFF_DAY = ['holiday', 'weekend'];

    /** Values of the status filter besides the stored statuses. */
    private const EXTRA_FILTERS = ['pending', 'not_in', 'holiday_weekend'];

    public function __construct(
        private readonly AttendanceClock $clock,
        private readonly ApplyManualCorrection $correct,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(['attendance.view-all', 'attendance.view-team']), 403);

        $today = $this->clock->attendanceToday();
        $asked = $request->query('date');
        $date = is_string($asked) && $asked !== '' ? $this->validDate($asked) : $today;

        if ($date === null) {
            return redirect()->route('attendance.daily')->with('error', 'That is not a valid date.');
        }

        if ($date > $today) {
            return redirect()->route('attendance.daily')->with('error', 'Attendance for a future day cannot be shown.');
        }

        $access = new EmployeeAccess($user);
        $isOpen = $date === $today;

        // Query 1: the day's rows of the people in scope.
        $rows = Attendance::query()
            ->onDate($date)
            ->whereIn('employee_id', $access->scope(Employee::query())->select('id'))
            ->get()
            ->keyBy('employee_id');

        // Query 2: the people (with their department and own shift, eager-loaded in two fixed queries).
        $start = $this->startDate();
        $employees = $access->scope(Employee::query())
            ->with(['department:id,name', 'designation:id,name', 'shift:id,name,weekly_offs'])
            ->where(function (Builder $q) use ($rows, $date, $start): void {
                $q->whereIn('id', $rows->keys()->all());

                if ($start !== null && $date >= $start) {
                    $q->orWhere(fn (Builder $w) => $this->shouldHaveRecord($w, $date));
                }
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->get();

        $defaultShift = $employees->contains(fn (Employee $e) => ! $e->getRelation('shift') instanceof Shift)
            ? Shift::default()
            : null;

        // The open day's off-day status for people with no row: a holiday for everyone, else their shift's weekly off.
        $isHoliday = $isOpen && Holiday::query()->onDate($date)->exists();

        $canEdit = $user->can('attendance.edit');
        // Employees whose login is Admin or HR: one query, only when the viewer edits and is not an Admin.
        $privileged = $canEdit && ! $user->hasRole('admin')
            ? array_flip(User::query()->role(['admin', 'hr'])->whereNotNull('employee_id')->pluck('employee_id')->all())
            : [];

        // A day before the attendance start date can be listed (a punch day) but not corrected or reset.
        $beforeStart = $start !== null && $date < $start;

        $list = $employees->map(fn (Employee $e) => $this->row(
            $e,
            $rows->get($e->id),
            $date,
            $isOpen,
            $isHoliday,
            $defaultShift,
            $canEdit
                && ! $beforeStart
                && ApplyManualCorrection::mayCorrect($user, $e, isset($privileged[$e->id]))
                && $this->correct->employmentRefusal($e, $date) === null,
            $beforeStart,
        ))->values();

        $filters = $this->filters($request);

        return Inertia::render('attendance/daily', [
            'date' => $date,
            'today' => $today,
            'isOpen' => $isOpen,
            'previousDate' => $this->shiftDate($date, -1),
            'nextDate' => $date < $today ? $this->shiftDate($date, 1) : null,
            'summary' => $this->summary($list),
            'rows' => $this->applyFilters($list, $filters)->values()->all(),
            'filters' => $filters,
            // A team-scoped viewer only sees the departments of the people in their scope.
            'departments' => Department::query()
                ->when(! $user->can('attendance.view-all'), fn (Builder $q) => $q->whereIn(
                    'id',
                    $access->scope(Employee::query())->whereNotNull('department_id')->select('department_id'),
                ))
                ->orderBy('name')->orderBy('id')->get(['id', 'name'])->all(),
            'statuses' => config('hrm.attendance.statuses'),
            'overrideStatuses' => ApplyManualCorrection::OVERRIDE_STATUSES,
            'can' => ['edit' => $canEdit],
        ]);
    }

    public function update(ManualCorrectionRequest $request, Employee $employee, string $date): RedirectResponse
    {
        $date = $this->authorizeDay($request, $employee, $date);

        /** @var User $user */
        $user = $request->user();

        $this->correct->handle(
            $employee,
            $date,
            $request->validated('clock_in'),
            $request->validated('clock_out'),
            $request->validated('status'),
            (string) $request->validated('reason'),
            $user,
        );

        return back()->with('success', "Attendance for {$employee->full_name} saved.");
    }

    public function reset(Request $request, AttendanceRebuilder $rebuilder, Employee $employee, string $date): RedirectResponse
    {
        $date = $this->authorizeDay($request, $employee, $date);

        $refusal = $this->correct->employmentRefusal($employee, $date);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['date' => $refusal]);
        }

        /** @var User $user */
        $user = $request->user();

        // One transaction: clear the manual flag, rebuild from the punches straight away (so the screen shows the
        // punch-based values), then audit the values before and the values that resulted. If the rebuild fails the
        // row stays marked needs_rebuild and the scheduled rebuild picks it up; the audit then shows that state.
        $reset = DB::transaction(function () use ($employee, $date, $user, $rebuilder): bool {
            $row = Attendance::query()->where('employee_id', $employee->id)->onDate($date)->lockForUpdate()->first();

            if ($row === null || ! $row->is_manual) {
                return false;
            }

            $old = $this->correct->audited($row);
            $row->forceFill(['is_manual' => false, 'status_overridden' => false, 'needs_rebuild' => true, 'note' => null])->save();

            try {
                $rebuilder->rebuild($employee, $date);
            } catch (Throwable $e) {
                report($e);
            }

            $after = Attendance::query()->where('employee_id', $employee->id)->onDate($date)->first();

            Audit::record('attendance', 'Attendance correction removed', $employee, [
                'date' => $date,
                'old' => $old,
                'attributes' => $after !== null ? $this->correct->audited($after) : $this->correct->auditedMissing(),
            ], $user);

            return true;
        });

        if (! $reset) {
            return back()->with('info', 'This day has no manual correction.');
        }

        return back()->with('success', "The manual correction for {$employee->full_name} was removed.");
    }

    /**
     * The checks every write shares: a real, non-future date, an employee in the viewer's scope (403 otherwise, so
     * nothing about people outside it is revealed) and one the viewer may correct (not their own record unless Admin,
     * not an Admin / HR login unless Admin: 403). Returns the date as "Y-m-d".
     */
    private function authorizeDay(Request $request, Employee $employee, string $date): string
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless((new EmployeeAccess($user))->canView($employee), 403);
        abort_unless(ApplyManualCorrection::mayCorrect($user, $employee), 403);

        $valid = $this->validDate($date);
        abort_if($valid === null, 404);
        abort_if($valid > $this->clock->attendanceToday(), 403);

        return $valid;
    }

    /** @param  Builder<Employee>  $q */
    private function shouldHaveRecord(Builder $q, string $date): void
    {
        $q->where('joining_date', '<=', $date)
            ->where(fn (Builder $w) => $w->whereNull('last_working_day')->orWhere('last_working_day', '>=', $date))
            ->where(fn (Builder $w) => Leavers::exceptWithoutLastDay($w));
    }

    /** @return array<string, mixed> */
    private function row(Employee $e, ?Attendance $a, string $date, bool $isOpen, bool $isHoliday, ?Shift $defaultShift, bool $canEdit, bool $beforeStart = false): array
    {
        $raw = $a?->getAttributes() ?? [];
        $in = isset($raw['clock_in']) ? (string) $raw['clock_in'] : null;
        $out = isset($raw['clock_out']) ? (string) $raw['clock_out'] : null;
        $shift = $e->getRelation('shift');
        $shift = $shift instanceof Shift ? $shift : $defaultShift;

        // No row on the open day: Holiday / Weekend when the day is one for this person, otherwise "Not in yet".
        $offDay = $a === null && $isOpen ? $this->offDay($date, $isHoliday, $shift) : null;
        // An attendance-exempt person with no row on the open day is not missing (the close records them present).
        $exemptOpen = $a === null && $isOpen && $offDay === null && (bool) $e->attendance_exempt;

        return [
            'employee' => [
                'id' => $e->id,
                'name' => $e->full_name,
                'code' => $e->employee_code,
                'photo_url' => EmployeeFiles::photoUrl($e),
                'department' => $e->department?->name,
                'department_id' => $e->department_id,
                'designation' => $e->designation?->name,
            ],
            'shift' => ($a !== null ? $this->snapshotName($a) : null) ?? $shift?->name,
            'has_record' => $a !== null,
            // No row and not an off day: "Not in yet" on the open day, "No record" on a past day. Never an exempt
            // person on the open day (shown as Exempt instead).
            'not_in' => $a === null && $offDay === null && ! $exemptOpen,
            'exempt' => (bool) $e->attendance_exempt,
            // A day before the attendance start date: listed (a punch day), never corrected; the page says so.
            'before_start' => $beforeStart,
            'clock_in' => $in !== null ? substr($in, 11, 5) : null,
            'clock_out' => $out !== null ? substr($out, 11, 5) : null,
            // A clock-out after midnight (before the day cutoff) is on the next calendar day.
            'clock_out_next_day' => $out !== null && $in !== null ? substr($out, 0, 10) > substr($in, 0, 10)
                : ($out !== null && $a !== null && substr($out, 0, 10) > substr((string) $raw['date'], 0, 10)),
            'worked_minutes' => (int) ($a->worked_minutes ?? 0),
            'late_minutes' => (int) ($a->late_minutes ?? 0),
            'early_minutes' => (int) ($a->early_minutes ?? 0),
            'overtime_minutes' => (int) ($a->overtime_minutes ?? 0),
            'status' => $a->status ?? $offDay,
            // Half-day leave ('am' first half, 'pm' second half): the status describes the worked half. A manual row
            // ignores leave, so a half left on one is not shown.
            'leave_half' => $a !== null && ! $a->is_manual && in_array($a->leave_half, ['am', 'pm'], true) ? $a->leave_half : null,
            'is_manual' => (bool) ($a->is_manual ?? false),
            'status_overridden' => (bool) ($a->status_overridden ?? false),
            'needs_attention' => (bool) ($a->needs_attention ?? false),
            'note' => $a?->note,
            'can_edit' => $canEdit,
        ];
    }

    /** "holiday" or "weekend" when $date is one for a person on $shift, otherwise null. */
    private function offDay(string $date, bool $isHoliday, ?Shift $shift): ?string
    {
        if ($isHoliday) {
            return 'holiday';
        }

        $weekday = strtolower((new DateTimeImmutable($date.' 00:00:00', new \DateTimeZone('UTC')))->format('l'));
        $offs = array_map(fn (string $d): string => strtolower(trim($d)), $shift->weekly_offs ?? []);

        return in_array($weekday, $offs, true) ? 'weekend' : null;
    }

    /** The shift name stored in the row's shift-rule snapshot (read raw: the JSON may be old or hand-edited). */
    private function snapshotName(Attendance $a): ?string
    {
        $raw = $a->getAttributes()['shift_rules'] ?? null;
        $rules = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $name = is_array($rules) ? ($rules['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Counts for the whole day in scope. Pending (null) and "not in yet" are never counted as present.
     *
     * @param  Collection<int, array<string, mixed>>  $list
     * @return array<string, int>
     */
    private function summary(Collection $list): array
    {
        $count = fn (callable $test) => $list->filter($test)->count();

        return [
            'total' => $list->count(),
            'present' => $count(fn ($r) => $r['status'] === 'present'),
            'late' => $count(fn ($r) => $r['status'] === 'late'),
            'half_day' => $count(fn ($r) => $r['status'] === 'half_day'),
            'absent' => $count(fn ($r) => $r['status'] === 'absent'),
            'on_leave' => $count(fn ($r) => $r['status'] === 'on_leave'),
            'holiday_weekend' => $count(fn ($r) => in_array($r['status'], self::OFF_DAY, true)),
            'pending' => $count(fn ($r) => $r['has_record'] && $r['status'] === null),
            'not_in' => $count(fn ($r) => $r['not_in']),
            'needs_attention' => $count(fn ($r) => $r['needs_attention']),
        ];
    }

    /** @return array{search: string, department: int|null, status: string|null, attention: bool} */
    private function filters(Request $request): array
    {
        $search = $request->query('search');
        $department = $request->query('department');
        $status = $request->query('status');
        $allowed = [...(array) config('hrm.attendance.statuses'), ...self::EXTRA_FILTERS];

        return [
            'search' => is_string($search) ? mb_substr(trim($search), 0, 100) : '',
            'department' => is_string($department) && ctype_digit($department) ? (int) $department : null,
            'status' => is_string($status) && in_array($status, $allowed, true) ? $status : null,
            'attention' => in_array($request->query('attention'), ['1', 'true'], true),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $list
     * @param  array{search: string, department: int|null, status: string|null, attention: bool}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $list, array $filters): Collection
    {
        $term = mb_strtolower($filters['search']);

        return $list->filter(function (array $r) use ($filters, $term): bool {
            if ($term !== '' && ! str_contains(mb_strtolower($r['employee']['name'].' '.$r['employee']['code']), $term)) {
                return false;
            }

            if ($filters['department'] !== null && $r['employee']['department_id'] !== $filters['department']) {
                return false;
            }

            if ($filters['attention'] && ! $r['needs_attention']) {
                return false;
            }

            return match ($filters['status']) {
                null => true,
                'pending' => $r['has_record'] && $r['status'] === null,
                'not_in' => $r['not_in'],
                'holiday_weekend' => in_array($r['status'], self::OFF_DAY, true),
                default => $r['status'] === $filters['status'],
            };
        });
    }

    private function startDate(): ?string
    {
        return AttendanceClock::startDate();
    }

    private function validDate(string $value): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $parsed !== false && $parsed->format('Y-m-d') === trim($value) ? $parsed->format('Y-m-d') : null;
    }

    private function shiftDate(string $date, int $days): string
    {
        return (new DateTimeImmutable($date.' 00:00:00', new \DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '').$days.' day')->format('Y-m-d');
    }
}
