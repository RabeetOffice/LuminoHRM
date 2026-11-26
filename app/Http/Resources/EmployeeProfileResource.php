<?php

namespace App\Http\Resources;

use App\Http\Controllers\Payroll\SalaryProfileController;
use App\Mail\LeaveRequestSubmitted;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Review;
use App\Models\SalaryProfile;
use App\Models\Shift;
use App\Models\User;
use App\Support\Assets\AssetHoldings;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\MonthGrid;
use App\Support\EmployeeAccess;
use App\Support\EmployeeFiles;
use App\Support\EmployeeRoles;
use App\Support\Leave\BalanceLine;
use App\Support\Leave\LeaveBalances;
use App\Support\Performance\ReviewAccess;
use App\Support\Performance\ReviewScoring;
use App\Support\Performance\ReviewStates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An employee as the profile page (and, read-only, My Profile) receives it. This is the ONLY way a whole employee
 * reaches a page: it asks EmployeeAccess::fieldGroups() what the viewer may see and writes explicit keys for each
 * allowed group. A group the viewer may not see is ABSENT from the result (not null, not empty), its relation is
 * never even loaded, and the matching tab is not in tabs().
 *
 *   id, code, name, photo_url            always (a viewer who can open the record may see who it is)
 *   basic      first_name, last_name, email, phone, gender
 *   identity   cnic (formatted 12345-1234567-1), dob         -> shown inside the Basic Info tab
 *   employment department, designation, manager {id|null, name}|null, joining_date, probation_end_date,
 *              employment_type, status, last_working_day, reports [{id, name, code, designation}],
 *              shift (the shift's name; the default shift's name when the employee has none) and shift_is_default,
 *              and for view-all viewers device_user_id, for employees.manage viewers role, login and attendance_exempt
 *   contact    address, emergency_name, emergency_phone
 *   banking    bank_name, account_title, account_number
 *   documents  items [{id, type, title, original_name, size_bytes, uploaded_at, expiry_date, download_url}]
 *   attendance the current month's summary {month, label, own, code, totals} (MonthGrid totals: pending never counted)
 *              for viewers allowed to see this person's attendance: the person themself, attendance.view-all, or
 *              attendance.view-team for their own direct reports. Not an EmployeeAccess field group: it has its own
 *              permissions. Absent (key and tab) for everyone else.
 *   leave      {year, own, code, lines (BalanceLine::toArray() for the current leave year), recent: the last 5 requests
 *              [{id, type, colour, from_date, to_date, half, dates, days, status, partly_cancelled}]} for the person
 *              themself, leave.approve-all, or leave.approve-team for their own direct reports (maySeeLeave). Never a
 *              reason or an attachment. Absent (key and tab) for everyone else.
 *   salary     {code, current: profile|null (in force today, else the first upcoming one), history: the last 5 profiles
 *              (newest first), loans: active loans and advances with something remaining, payslips: the last 6 lines of
 *              approved or paid runs (not excluded) [{id, month, label, net}]} for payroll.view viewers ONLY (Admin, HR:
 *              maySeeSalary). Never for a Manager or an Employee, not even on their own record or My Profile: absent
 *              (key and tab), and nothing about salary is even read.
 *
 *   assets     {holding: the open assignments [{id, asset_id, code, name, category, assigned_on, condition_out, cost?}],
 *              history: the last 20 returned [same + returned_on, return_condition], can_assign} for whoever may open the
 *              profile (the person, employees.view-all, a manager for a direct report). `cost` comes only with
 *              assets.assign (the key is absent, not null, for everyone else); `can_assign` is always sent: true with
 *              assets.assign, false for everyone else.
 *
 *   reviews    {closed: [{id, cycle, period {from, to}, final_score, label, reviewer, closed_on, url?}]} for whoever may open the profile:
 *              the person's CLOSED reviews, newest first. Never an open or skipped review, never a rating, comment, note or
 *              reason. `url` only when ReviewAccess lets the viewer open that review (the key is absent otherwise).
 *
 * Never add a field to a group without asking whether the roles that see that group may see it, and never return
 * the model or a relation as it is.
 *
 * @property Employee $resource
 */
class EmployeeProfileResource extends JsonResource
{
    /** Which tab shows which group. `identity` has no tab of its own: it sits inside Basic Info. */
    public const TABS = [
        'basic' => 'basic',
        'employment' => 'employment',
        'contact' => 'contact',
        'banking' => 'banking',
        'documents' => 'documents',
        'attendance' => 'attendance',
        'leave' => 'leave',
        'salary' => 'salary',
        'assets' => 'assets',
        'reviews' => 'reviews',
    ];

    /** @var list<string> */
    private array $groups;

    private readonly bool $viewAll;

    private readonly bool $manage;

    private readonly bool $own;

    private readonly bool $assign;

    private readonly User $viewer;

    public function __construct(Employee $resource, private readonly EmployeeAccess $access, User $viewer)
    {
        parent::__construct($resource);

        $this->own = $viewer->employee_id !== null && (int) $viewer->employee_id === (int) $resource->getKey();
        $this->groups = $access->fieldGroups($resource);

        if ($this->own || self::maySeeAttendance($viewer, $resource)) {
            $this->groups[] = 'attendance';
        }

        if ($this->own || self::maySeeLeave($viewer, $resource)) {
            $this->groups[] = 'leave';
        }

        if (self::maySeeSalary($viewer, $resource)) {
            $this->groups[] = 'salary';
        }
        // Whoever may open the profile may see what the person holds; the cost is for assets.assign only.
        $this->groups[] = 'assets';
        // Closed review history: the same people who may open the profile (what each review shows is ReviewAccess's call).
        $this->groups[] = 'reviews';
        $this->viewer = $viewer;
        $this->assign = $viewer->can('assets.assign');
        $this->viewAll = $viewer->can('employees.view-all');
        $this->manage = $access->canManage();
    }

    /**
     * The tab keys the viewer gets, in display order.
     *
     * @return list<string>
     */
    public function tabs(): array
    {
        return array_keys(array_filter(self::TABS, fn (string $group) => $this->allows($group)));
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $employee = $this->resource;

        $data = [
            'id' => $employee->id,
            'code' => $employee->employee_code,
            'name' => $employee->full_name,
            // The authorised photo route with a version, or null (initials). Anyone who may open the record may see it.
            'photo_url' => EmployeeFiles::photoUrl($employee),
        ];

        if ($this->allows('basic')) {
            $data['basic'] = [
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'gender' => $employee->gender,
            ];
        }

        if ($this->allows('identity')) {
            $data['identity'] = [
                'cnic' => self::formatCnic($employee->cnic),
                'dob' => $employee->dob?->format('Y-m-d'),
            ];
        }

        if ($this->allows('employment')) {
            $data['employment'] = $this->employment();
        }

        if ($this->allows('contact')) {
            $contact = $employee->contact()->first();

            $data['contact'] = [
                'address' => $contact?->address,
                'emergency_name' => $contact?->emergency_name,
                'emergency_phone' => $contact?->emergency_phone,
            ];
        }

        if ($this->allows('banking')) {
            $bank = $employee->bankAccount()->first();

            $data['banking'] = [
                'bank_name' => $bank?->bank_name,
                'account_title' => $bank?->account_title,
                'account_number' => $bank?->account_number,
            ];
        }

        if ($this->allows('documents')) {
            // Never the stored path: a document leaves the disk only through its download route.
            $data['documents'] = ['items' => EmployeeFiles::documentItems($employee)];
        }

        if ($this->allows('attendance')) {
            $data['attendance'] = $this->attendance();
        }

        if ($this->allows('leave')) {
            $data['leave'] = $this->leave();
        }

        if ($this->allows('salary')) {
            $data['salary'] = $this->salary();
        }

        if ($this->allows('assets')) {
            $data['assets'] = $this->assets();
        }

        if ($this->allows('reviews')) {
            $data['reviews'] = $this->reviews();
        }

        return $data;
    }

    /** 13 digits as 12345-1234567-1; anything else exactly as stored; null stays null. */
    public static function formatCnic(?string $cnic): ?string
    {
        if ($cnic === null || $cnic === '') {
            return null;
        }

        return preg_match('/^\d{13}$/', $cnic) === 1
            ? substr($cnic, 0, 5).'-'.substr($cnic, 5, 7).'-'.substr($cnic, 12, 1)
            : $cnic;
    }

    /** @return array<string, mixed> */
    private function employment(): array
    {
        $employee = $this->resource;
        $employee->loadMissing(['department:id,name', 'designation:id,name', 'manager:id,first_name,last_name,manager_id']);

        $manager = $employee->manager;

        $data = [
            'department' => $employee->department->name,
            'designation' => $employee->designation->name,
            // The id only when the viewer may open that person; otherwise a name with nothing to follow.
            'manager' => $manager === null ? null : [
                'id' => $this->access->canView($manager) ? $manager->id : null,
                'name' => $manager->full_name,
            ],
            'joining_date' => $employee->joining_date->format('Y-m-d'),
            'probation_end_date' => $employee->probation_end_date?->format('Y-m-d'),
            'employment_type' => $employee->employment_type,
            'status' => $employee->status,
            'last_working_day' => $employee->last_working_day?->format('Y-m-d'),
            'reports' => $this->reports(),
        ];

        // The shift's name only (no times or rules): every viewer of this group may see which shift someone works.
        $shift = Shift::forEmployee($employee);
        $data['shift'] = $shift->name;
        // True when the employee has no shift of their own and works the default one.
        $data['shift_is_default'] = $employee->shift_id === null || (int) $shift->getKey() !== (int) $employee->shift_id;

        // The attendance machine's ID is for people who see everyone (Admin, HR), not for a Manager, even on their own record.
        if ($this->viewAll) {
            $data['device_user_id'] = $employee->device_user_id;
        }

        // Role and login state are for the people who manage employees.
        if ($this->manage) {
            $account = $employee->user()->first(['id', 'employee_id', 'is_active']);

            $data['role'] = EmployeeRoles::currentRole($account);
            $data['login'] = match (true) {
                $account === null => EmployeeListResource::LOGIN_NONE,
                $account->is_active => EmployeeListResource::LOGIN_ACTIVE,
                default => EmployeeListResource::LOGIN_DISABLED,
            };
            $data['attendance_exempt'] = (bool) $employee->attendance_exempt;
        }

        return $data;
    }

    /**
     * Direct reports the viewer may themselves view (the same scope as the list): id, name, code and designation only.
     *
     * @return list<array{id: int, name: string, code: string, designation: string}>
     */
    private function reports(): array
    {
        $reports = $this->access->scope(Employee::query()->where('manager_id', $this->resource->getKey()))
            ->with('designation:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'designation_id']);

        return array_values($reports->map(fn (Employee $report) => [
            'id' => $report->id,
            'name' => $report->full_name,
            'code' => $report->employee_code,
            'designation' => $report->designation->name,
        ])->all());
    }

    /**
     * Whether the viewer may see this person's attendance besides their own: attendance.view-all, or
     * attendance.view-team for a direct report.
     */
    public static function maySeeAttendance(User $viewer, Employee $employee): bool
    {
        if ($viewer->can('attendance.view-all')) {
            return true;
        }

        return $viewer->employee_id !== null
            && $employee->manager_id !== null
            && (int) $employee->manager_id === (int) $viewer->employee_id
            && $viewer->can('attendance.view-team');
    }

    /**
     * The current month's attendance summary. `own` tells the page to link to My Attendance instead of the monthly
     * grid (which lists a Manager's reports, not the Manager); `code` is what the grid link searches for.
     *
     * @return array{month: string, label: string, own: bool, code: string, totals: array<string, int|float>}
     */
    private function attendance(): array
    {
        $clock = app(AttendanceClock::class);
        $month = MonthGrid::currentMonth($clock);
        $grid = MonthGrid::for($month, $clock);

        return [
            'month' => $month,
            'label' => $grid?->label() ?? $month,
            'own' => $this->own,
            'code' => $this->resource->employee_code,
            'totals' => $grid === null ? MonthGrid::emptyTotals() : $grid->rows([$this->resource])[$this->resource->id]['totals'],
        ];
    }

    /**
     * Whether the viewer may see this person's leave besides their own: leave.approve-all, or leave.approve-team for a
     * direct report.
     */
    public static function maySeeLeave(User $viewer, Employee $employee): bool
    {
        if ($viewer->can('leave.approve-all')) {
            return true;
        }

        return $viewer->employee_id !== null
            && $employee->manager_id !== null
            && (int) $employee->manager_id === (int) $viewer->employee_id
            && $viewer->can('leave.approve-team');
    }

    /**
     * The current leave year's balance lines and the last 5 requests. `own` tells the page to link to My Leaves instead
     * of Leave → Requests; `code` is what the requests link searches for. Never the reason or the attachment.
     *
     * @return array{year: int, own: bool, code: string, lines: list<array<string, mixed>>, recent: list<array<string, mixed>>}
     */
    private function leave(): array
    {
        $employee = $this->resource;
        $year = (int) substr(app(AttendanceClock::class)->attendanceToday(), 0, 4);

        $recent = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->with('type:id,name,colour')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'employee_id', 'leave_type_id', 'from_date', 'to_date', 'half', 'days', 'status', 'cancelled_from', 'created_at']);

        return [
            'year' => $year,
            'own' => $this->own,
            'code' => $employee->employee_code,
            // The resource holds the whole employee record, which the balance service needs (joining date, shift ...).
            'lines' => array_map(fn (BalanceLine $line) => $line->toArray(), app(LeaveBalances::class)->lines($employee, $year)),
            'recent' => array_values($recent->map(function (LeaveRequest $r): array {
                $from = substr((string) $r->getRawOriginal('from_date'), 0, 10);
                $to = substr((string) $r->getRawOriginal('to_date'), 0, 10);

                return [
                    'id' => $r->id,
                    'type' => $r->type->name,
                    'colour' => $r->type->colour,
                    'from_date' => $from,
                    'to_date' => $to,
                    'half' => $r->half,
                    'dates' => self::leaveDates($from, $to, $r->half),
                    'days' => (float) $r->days,
                    'status' => $r->status,
                    'partly_cancelled' => $r->status === 'approved' && $r->cancelled_from !== null,
                ];
            })->all()),
        ];
    }

    /**
     * The Assets tab: what the person holds now and the last 20 returned (newest return first). `cost` (paisa) and the
     * link to the asset page and the assign action only with assets.assign.
     *
     * @return array{holding: list<array<string, mixed>>, history: list<array<string, mixed>>, can_assign: bool}
     */
    private function assets(): array
    {
        $employee = $this->resource;

        $row = function (AssetAssignment $a): array {
            $data = [
                'id' => $a->id,
                'asset_id' => $a->asset_id,
                'code' => $a->asset->asset_code,
                'name' => $a->asset->name,
                'category' => $a->asset->category->name,
                'assigned_on' => $a->assigned_on->format('Y-m-d'),
                'condition_out' => $a->condition_out,
            ];

            if ($this->assign) {
                $data['cost'] = $a->asset->cost === null ? null : (int) $a->asset->cost;
            }

            return $data;
        };

        $returned = AssetAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->whereNotNull('returned_on')
            ->with('asset.category')
            ->orderByDesc('returned_on')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return [
            'holding' => array_values(AssetHoldings::openFor($employee)->map($row)->all()),
            'history' => array_values($returned->map(fn (AssetAssignment $a) => [
                ...$row($a),
                'returned_on' => $a->returned_on->format('Y-m-d'),
                'return_condition' => $a->return_condition,
            ])->all()),
            'can_assign' => $this->assign,
        ];
    }

    /**
     * The Reviews tab: the person's closed reviews, newest first. The link only where the viewer may open the review (one
     * ReviewAccess::scope query for all of them).
     *
     * @return array{closed: list<array<string, mixed>>}
     */
    private function reviews(): array
    {
        $closed = Review::query()
            ->join('review_cycles', 'review_cycles.id', '=', 'reviews.review_cycle_id')
            ->leftJoin('employees as reviewers', 'reviewers.id', '=', 'reviews.reviewer_id')
            ->where('reviews.employee_id', $this->resource->getKey())
            ->where('reviews.status', ReviewStates::CLOSED)
            ->orderByDesc('reviews.closed_at')
            ->orderByDesc('reviews.id')
            ->get([
                'reviews.id', 'reviews.final_score', 'reviews.closed_at',
                'review_cycles.name as cycle_name', 'review_cycles.period_start', 'review_cycles.period_end',
                'reviewers.first_name as reviewer_first', 'reviewers.last_name as reviewer_last',
            ]);

        if ($closed->isEmpty()) {
            return ['closed' => []];
        }

        $zone = app(AttendanceClock::class)->zone();
        $openable = ReviewAccess::scope(Review::query(), $this->viewer)->whereIn('reviews.id', $closed->modelKeys())
            ->pluck('reviews.id')->map(fn ($id) => (int) $id)->all();

        return ['closed' => array_values($closed->map(function (Review $review) use ($zone, $openable): array {
            $score = $review->final_score;
            $reviewer = trim($review->getAttribute('reviewer_first').' '.$review->getAttribute('reviewer_last'));

            $row = [
                'id' => (int) $review->id,
                'cycle' => (string) $review->getAttribute('cycle_name'),
                'period' => ['from' => substr((string) $review->getRawOriginal('period_start'), 0, 10), 'to' => substr((string) $review->getRawOriginal('period_end'), 0, 10)],
                'final_score' => $score === null ? null : ReviewScoring::format($score),
                'label' => $score === null ? null : ReviewScoring::label($score),
                'reviewer' => $reviewer === '' ? 'HR' : $reviewer,
                'closed_on' => $review->closed_at?->copy()->setTimezone($zone)->format('Y-m-d'),
            ];

            if (in_array((int) $review->id, $openable, true)) {
                $row['url'] = '/performance/reviews/'.$review->id;
            }

            return $row;
        })->all())];
    }

    /**
     * Whether the viewer may see this person's salary: payroll.view (Admin, HR). Never a Manager or an Employee, not
     * even for their own record (their own payslips live in My Payslips).
     */
    public static function maySeeSalary(User $viewer, Employee $employee): bool
    {
        return $viewer->can('payroll.view');
    }

    /**
     * The Salary tab: the current profile, the last 5 profiles, active loans with what remains, and the last 6
     * approved payslip lines. Money in paisa.
     *
     * @return array{code: string, current: array<string, mixed>|null, history: list<array<string, mixed>>, loans: list<array<string, mixed>>, payslips: list<array{id: int, month: string, label: string, net: int}>}
     */
    private function salary(): array
    {
        $employee = $this->resource;
        $today = app(AttendanceClock::class)->attendanceToday();

        $profiles = SalaryProfile::query()
            ->where('employee_id', $employee->id)
            ->with('components')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $current = SalaryProfileController::currentOf(array_reverse($profiles->all()), $today);

        $loans = Loan::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->withRemaining()
            ->orderBy('start_month')
            ->orderBy('id')
            ->get()
            ->filter(fn (Loan $loan) => $loan->remaining() > 0);

        $payslips = PayrollItem::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
            ->where('payroll_items.employee_id', $employee->id)
            ->where('payroll_items.excluded', false)
            ->whereIn('payroll_runs.status', (array) config('hrm.payroll.locked_statuses'))
            ->orderByDesc('payroll_runs.month')
            ->limit(6)
            ->get(['payroll_items.id', 'payroll_items.net', 'payroll_runs.month']);

        return [
            'code' => $employee->employee_code,
            'current' => $current === null ? null : [
                ...SalaryProfileController::profileRow($current),
                'upcoming' => $current->effective_from->toDateString() > $today,
            ],
            'history' => array_values($profiles->take(5)->map(fn (SalaryProfile $p) => SalaryProfileController::profileRow($p))->all()),
            'loans' => array_values($loans->map(fn (Loan $loan) => [
                'id' => $loan->id,
                'type' => $loan->type,
                'amount' => (int) $loan->amount,
                'installment' => (int) $loan->installment,
                'start_month' => (string) $loan->start_month,
                'remaining' => $loan->remaining(),
            ])->all()),
            'payslips' => array_values($payslips->map(fn (PayrollItem $item) => [
                'id' => (int) $item->id,
                'month' => (string) $item->getAttribute('month'),
                'label' => PayrollRun::monthLabel((string) $item->getAttribute('month')),
                'net' => (int) $item->net,
            ])->all()),
        ];
    }

    /**
     * "Tue 1 Dec 2026", "Tue 1 Dec 2026 – Thu 3 Dec 2026" or "Tue 1 Dec 2026, first half" (read as text, no zone): the
     * one label the leave mails use (LeaveRequestSubmitted::dates).
     */
    public static function leaveDates(string $from, string $to, ?string $half): string
    {
        return LeaveRequestSubmitted::dates($from, $to, $half);
    }

    private function allows(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }
}
