<?php

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Device;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Kpi;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Loan;
use App\Models\PayrollRun;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\ReviewScore;
use App\Models\SalaryProfile;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature and Unit tests extend the application's base test case so that
| Pest tests and the kit's existing class-based tests share one bootstrap.
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * Seeds roles and permissions (idempotent) and returns an active, verified user holding $role.
 */
function userWithRole(string $role): User
{
    test()->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Creates an employee record for $user (same email and name) and links the account to it.
 *
 * @param  array<string, mixed>  $attrs  Overrides for the employee row
 */
function employeeFor(User $user, array $attrs = []): Employee
{
    $name = explode(' ', trim($user->name).' ', 2);

    $employee = Employee::factory()->create(array_merge([
        'first_name' => $name[0] !== '' ? $name[0] : 'Test',
        'last_name' => trim($name[1]) !== '' ? trim($name[1]) : 'User',
        'email' => $user->email,
    ], $attrs));

    $user->forceFill(['employee_id' => $employee->id])->save();

    return $employee;
}

/**
 * A complete valid payload for PUT /hrm-settings/attendance, merged with $overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validAttendance(array $overrides = []): array
{
    return array_merge([
        'shift_start' => '09:00',
        'shift_end' => '18:00',
        'grace_minutes' => 15,
        'weekly_offs' => ['saturday', 'sunday'],
        'late_rule_count' => 3,
        'half_day_hours' => 4,
        'overtime_enabled' => false,
        'overtime_min_minutes' => 30,
        'start_date' => null,
        'duplicate_punch_minutes' => 5,
        'day_cutoff_time' => '04:00',
    ], $overrides);
}

/**
 * A complete valid payload for PUT /hrm-settings/email, merged with $overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validEmail(array $overrides = []): array
{
    return array_merge([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'mailer',
        'password' => '',
        'encryption' => 'tls',
        'from_address' => 'hr@example.com',
        'from_name' => 'Lumino HR',
    ], $overrides);
}

/**
 * A new (non-default) shift with $attrs over the factory values (09:00-18:00, 15 min grace, 4 h half day, Sat/Sun off),
 * assigned to $employee when one is given.
 *
 * @param  array<string, mixed>  $attrs
 */
function shiftFor(?Employee $employee = null, array $attrs = []): Shift
{
    $shift = Shift::factory()->create($attrs);

    if ($employee !== null) {
        $employee->forceFill(['shift_id' => $shift->id])->save();
    }

    return $shift;
}

/**
 * A leave type by name: one of the four seeded defaults (Annual, Sick, Casual, Unpaid) when it exists, else a new
 * paid type with that name. $attrs are applied in both cases (an existing type is updated).
 *
 * @param  array<string, mixed>  $attrs
 */
function leaveType(string $name = 'Annual', array $attrs = []): LeaveType
{
    $type = LeaveType::query()->where('name', $name)->first();

    if ($type === null) {
        return LeaveType::factory()->create(['name' => $name, ...$attrs]);
    }

    if ($attrs !== []) {
        $type->update($attrs);
    }

    return $type;
}

/**
 * A request of $employee written directly (no routing, no leave days): by default a pending one-day Annual request
 * for 2026-12-01. A half day defaults to 0.5 days.
 *
 * @param  array<string, mixed>  $attrs
 */
function leaveRequest(Employee $employee, array $attrs = []): LeaveRequest
{
    $halfDay = (bool) ($attrs['is_half_day'] ?? false);

    return LeaveRequest::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => leaveType()->id,
        'days' => $halfDay ? 0.5 : 1,
        ...$attrs,
    ]);
}

/**
 * An approved request of $employee and its leave days, written directly (no routing, no day counting, no balance
 * check, no rebuild marks): for attendance and balance tests. One full leave day for EVERY calendar date from $from to
 * $to (default $from) inclusive, weekends included, so pass the dates that should count; with $half ("am" / "pm")
 * a single half day on $from. $type is a leave type name (see leaveType()).
 */
function approvedLeave(Employee $employee, string $from, ?string $to = null, ?string $half = null, string $type = 'Annual'): LeaveRequest
{
    $leaveType = leaveType($type);
    $to = $half !== null ? $from : ($to ?? $from);

    $dates = [];
    for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
        $dates[] = $d->format('Y-m-d');
    }

    $amount = $half !== null ? 0.5 : 1.0;

    $request = LeaveRequest::factory()->create([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'from_date' => $from,
        'to_date' => $to,
        'is_half_day' => $half !== null,
        'half' => $half,
        'days' => $amount * count($dates),
        'status' => 'approved',
        'hr_decided_at' => now(),
    ]);

    foreach ($dates as $date) {
        LeaveDay::query()->create([
            'leave_request_id' => $request->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'date' => $date,
            'amount' => $amount,
            'half' => $half ?? 'full',
        ]);
    }

    return $request;
}

/**
 * Stores one punch as the machine would: $who is an employee (matched by their device_user_id, given one if they have
 * none) or a bare machine user ID (linked to the employee holding it, if any). $at is the naive "Y-m-d H:i[:s]" time.
 * $device defaults to the first device, created when there is none.
 */
function punch(Employee|string $who, string $at, ?Device $device = null): DevicePunch
{
    if ($who instanceof Employee) {
        if ($who->device_user_id === null || $who->device_user_id === '') {
            // The first number from 1000 + id up that no employee holds and no stored punch uses (collision-free).
            $candidate = 1000 + $who->id;

            while (Employee::query()->where('device_user_id', (string) $candidate)->exists()
                || DevicePunch::query()->where('device_user_id', (string) $candidate)->exists()) {
                $candidate++;
            }

            $who->forceFill(['device_user_id' => (string) $candidate])->save();
        }

        $employee = $who;
        $userId = (string) $who->device_user_id;
    } else {
        $userId = $who;
        $employee = Employee::query()->where('device_user_id', $who)->first();
    }

    $device ??= Device::query()->orderBy('id')->first() ?? Device::factory()->create();

    return DevicePunch::query()->create([
        'device_id' => $device->id,
        'device_user_id' => $userId,
        'employee_id' => $employee?->id,
        'punch_time' => $at,
        'verify_type' => '1',
        'status_code' => '0',
        'source' => 'device',
    ]);
}

/**
 * A salary profile of $employee in force from $from, written directly (no action, no audit). Amounts are whole rupees,
 * stored in paisa. $allowances: [name, rupees, taxable]; $deductions: [name, rupees]. $taxValue is stored as given:
 * basis points for percentage (500 = 5%), paisa for fixed. Defaults: the plan's profile P (basic 60,000, House rent
 * 24,000 taxable, Medical 6,000 not taxable, Provident fund 3,000, tax 5.00% of gross taxable).
 *
 * @param  list<array{0: string, 1: int, 2: bool}>  $allowances
 * @param  list<array{0: string, 1: int}>  $deductions
 */
function salaryProfile(
    Employee $e,
    int $basicRupees = 60000,
    array $allowances = [['House rent', 24000, true], ['Medical', 6000, false]],
    array $deductions = [['Provident fund', 3000]],
    string $from = '2026-01-01',
    string $taxMode = 'percentage',
    int $taxValue = 500,
    ?string $taxBase = 'gross_taxable',
): SalaryProfile {
    $profile = SalaryProfile::query()->create([
        'employee_id' => $e->id,
        'effective_from' => $from,
        'basic' => $basicRupees * 100,
        'tax_mode' => $taxMode,
        'tax_value' => $taxValue,
        'tax_base' => $taxMode === 'percentage' ? $taxBase : null,
    ]);

    $order = 0;
    foreach ($allowances as [$name, $rupees, $taxable]) {
        $profile->components()->create(['name' => $name, 'type' => 'allowance', 'amount' => $rupees * 100, 'is_taxable' => $taxable, 'sort_order' => $order++]);
    }
    foreach ($deductions as [$name, $rupees]) {
        $profile->components()->create(['name' => $name, 'type' => 'deduction', 'amount' => $rupees * 100, 'is_taxable' => false, 'sort_order' => $order++]);
    }

    return $profile->load('components');
}

/**
 * A payroll run of $month ("Y-m") in $status (draft | approved | paid) with no lines, written directly. An approved or
 * paid run locks the month (App\Support\PayrollLock) and keeps its figures, so give them in $attrs.
 */
function payrollRun(string $month, string $status = 'draft', array $attrs = []): PayrollRun
{
    return PayrollRun::query()->create([
        ...$attrs,
        'month' => $month,
        'status' => $status,
        'generated_at' => now(),
        'approved_at' => $status === 'draft' ? null : now(),
        'paid_at' => $status === 'paid' ? now() : null,
    ]);
}

/**
 * An active loan (or advance with $type 'advance') of $employee, written directly. Amounts in whole rupees, stored in
 * paisa; $openingRepaidRupees = repaid before go-live.
 */
function loanFor(Employee $e, int $amountRupees, int $installmentRupees, string $startMonth, int $openingRepaidRupees = 0, string $type = 'loan'): Loan
{
    return Loan::query()->create([
        'employee_id' => $e->id,
        'type' => $type,
        'amount' => $amountRupees * 100,
        'installment' => $installmentRupees * 100,
        'start_month' => $startMonth,
        'opening_repaid' => $openingRepaidRupees * 100,
        'status' => 'active',
    ]);
}

/**
 * The asset category called $name (one of the five seeded ones, or a new one).
 */
function assetCategory(string $name = 'Laptop'): AssetCategory
{
    return AssetCategory::query()->firstOrCreate(['name' => $name], ['sort_order' => 9]);
}

/**
 * An available asset (unique code and serial number) written directly. Pass any column to override, e.g. `['status' =>
 * 'retired']`; `category` (a name) picks the category, Laptop by default.
 *
 * @param  array<string, mixed>  $attrs
 */
function assetRow(array $attrs = []): Asset
{
    $category = $attrs['category'] ?? 'Laptop';
    unset($attrs['category']);

    return Asset::factory()->create(array_merge(['asset_category_id' => assetCategory($category)->id], $attrs));
}

/**
 * An asset handed to $employee, written directly (no action, no audit): the asset becomes `assigned` and an open
 * assignment row is added, handed over on 2026-11-20 unless `assigned_on` says otherwise. Asset columns go in `$attrs`
 * with the assignment's own columns (`assigned_on`, `returned_on`, `condition_out`, `return_condition`, `note`,
 * `return_note`); a returned row (`returned_on` set) leaves the asset `available`.
 *
 * @param  array<string, mixed>  $attrs
 */
function assignedAsset(Employee $employee, array $attrs = []): Asset
{
    $assignmentColumns = ['assigned_on', 'returned_on', 'condition_out', 'return_condition', 'note', 'return_note', 'assigned_by', 'returned_by'];
    $assignmentAttrs = array_intersect_key($attrs, array_flip($assignmentColumns));
    $assetAttrs = array_diff_key($attrs, $assignmentAttrs);
    $returned = ($assignmentAttrs['returned_on'] ?? null) !== null;

    $asset = assetRow(array_merge(['status' => $returned ? 'available' : 'assigned'], $assetAttrs));

    AssetAssignment::query()->create(array_merge([
        'asset_id' => $asset->id,
        'employee_id' => $employee->id,
        'assigned_on' => '2026-11-20',
        'condition_out' => $asset->condition,
    ], $assignmentAttrs));

    return $asset->refresh();
}

/**
 * A KPI written directly. For a designation by default (a new one; pass `designation` as a Designation to pick it), or for one
 * person with `employee` (an Employee). Any column can be overridden: `name`, `weight`, `is_active`, ...
 *
 * @param  array<string, mixed>  $attrs
 */
function kpiRow(array $attrs = []): Kpi
{
    $designation = $attrs['designation'] ?? null;
    $employee = $attrs['employee'] ?? null;
    unset($attrs['designation'], $attrs['employee']);

    if ($employee !== null) {
        $attrs = array_merge(['designation_id' => null, 'employee_id' => $employee->id], $attrs);
    } elseif ($designation !== null) {
        $attrs = array_merge(['designation_id' => $designation->id, 'employee_id' => null], $attrs);
    }

    return Kpi::factory()->create($attrs);
}

/**
 * An open monthly cycle written directly (each call takes the next month from January 2020; pass any column to override).
 *
 * @param  array<string, mixed>  $attrs
 */
function reviewCycleRow(array $attrs = []): ReviewCycle
{
    return ReviewCycle::factory()->create($attrs);
}

/**
 * A review of $employee in $cycle, written directly (no action, no audit), waiting on the manager unless `status` says
 * otherwise. Its KPI copies are made from the employee's active KPIs (their designation's first, then their own, each by name
 * then id) unless `scores` is given: a list of score-row columns to write instead (`[]` for none).
 *
 * @param  array<string, mixed>  $attrs
 */
function reviewRow(ReviewCycle $cycle, Employee $employee, array $attrs = []): Review
{
    $scores = $attrs['scores'] ?? null;
    unset($attrs['scores']);

    $review = Review::factory()->create(array_merge([
        'review_cycle_id' => $cycle->id,
        'employee_id' => $employee->id,
        'reviewer_id' => $employee->manager_id,
        'status' => 'manager_review',
    ], $attrs));

    if ($scores === null) {
        $byTarget = fn ($query) => $query->where('is_active', true)->orderBy('name')->orderBy('id')->get();
        $scores = $byTarget(Kpi::query()->where('designation_id', $employee->designation_id))
            ->concat($byTarget(Kpi::query()->where('employee_id', $employee->id)))
            ->map(fn (Kpi $kpi) => ['kpi_id' => $kpi->id, 'name' => $kpi->name, 'description' => $kpi->description, 'weight' => $kpi->weight])
            ->all();
    }

    foreach (array_values($scores) as $i => $row) {
        ReviewScore::factory()->create(array_merge(['review_id' => $review->id, 'sort_order' => $i + 1], $row));
    }

    return $review->refresh();
}
