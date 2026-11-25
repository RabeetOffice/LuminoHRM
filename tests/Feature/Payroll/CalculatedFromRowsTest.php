<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Support\Payroll\PayCalculator;
use App\Support\Payroll\PayInputLoader;
use App\Support\Payroll\PayResult;
use App\Support\Payroll\PayrollRoster;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/*
 * The loader and the calculator end to end on real rows: November 2026 (30 days), "now" 2026-12-05 in Karachi,
 * attendance started on 2026-11-01, calendar-days basis, late rule 3. Profile P (salaryProfile() defaults): basic
 * 60,000, House rent 24,000 taxable, Medical 6,000, Provident fund 3,000, tax 5% of gross taxable.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01', 'late_rule_count' => 3]);
});

function cfrDay(Employee $e, string $date, ?string $status, array $attrs = []): void
{
    Attendance::factory()->create(['employee_id' => $e->id, 'date' => $date, 'status' => $status, 'closed' => true, ...$attrs]);
}

function cfrResult(Employee $e): PayResult
{
    $inputs = PayInputLoader::load('2026-11', PayrollRoster::forMonth('2026-11')->included);

    return PayCalculator::calculate($inputs[$e->id]);
}

it('nets 64,930 for design example 1 built from attendance, leave and loan rows', function () {
    $e = Employee::factory()->create(['joining_date' => '2025-01-01']);
    salaryProfile($e);
    cfrDay($e, '2026-11-02', 'present');
    cfrDay($e, '2026-11-03', 'absent');
    cfrDay($e, '2026-11-04', 'absent');
    cfrDay($e, '2026-11-05', 'half_day');
    cfrDay($e, '2026-11-06', 'on_leave');
    foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'] as $date) {
        cfrDay($e, $date, 'late', ['late_minutes' => 25]);
    }
    cfrDay($e, '2026-11-07', 'weekend');
    approvedLeave($e, '2026-11-06', null, null, 'Unpaid');
    loanFor($e, 12000, 5000, '2026-11');

    $r = cfrResult($e);

    expect($r->gross)->toBe(9000000)
        ->and($r->attendanceDeduction)->toBe(1350000)
        ->and($r->taxablePay)->toBe(7140000)
        ->and($r->tax)->toBe(357000)
        ->and($r->fixedDeductions)->toBe(300000)
        ->and($r->loanDeduction)->toBe(500000)
        ->and($r->net)->toBe(6493000)
        ->and($r->breakdown['attendance']['deducted_halves'])->toBe(9)
        ->and($r->breakdown['attendance']['late_days_deducted'])->toBe(1)
        ->and($r->blocking)->toBe([]);
});

it('deducts only the unpaid day for an attendance-exempt person', function () {
    $e = Employee::factory()->create(['joining_date' => '2025-01-01', 'attendance_exempt' => true]);
    salaryProfile($e);
    foreach (['2026-11-02', '2026-11-03', '2026-11-04'] as $date) {
        cfrDay($e, $date, 'late', ['late_minutes' => 30]);      // 3 lates: the late rule does not apply when exempt
    }
    cfrDay($e, '2026-11-05', 'present');
    cfrDay($e, '2026-11-06', 'on_leave');
    approvedLeave($e, '2026-11-06', null, null, 'Unpaid');

    $r = cfrResult($e);

    // R(90,000 x 2, 60) = 3,000; taxable share R(84,000 x 2, 60) = 2,800; tax R((84,000 - 2,800) x 500, 10,000) = 4,060.
    expect($r->attendanceDeduction)->toBe(300000)
        ->and($r->tax)->toBe(406000)
        ->and($r->net)->toBe(7994000)
        ->and($r->breakdown['attendance']['deducted_halves'])->toBe(2);
});

it('pays a mid-month joiner from the joining date (design example 2: 41,400)', function () {
    $e = Employee::factory()->create(['joining_date' => '2026-11-16']);
    salaryProfile($e, from: '2026-11-16');

    $r = cfrResult($e);

    expect($r->daysPaid)->toBe(15)
        ->and($r->gross)->toBe(4500000)
        ->and($r->net)->toBe(4140000);
});

it('blocks a person with no salary profile in force', function () {
    $e = Employee::factory()->create(['joining_date' => '2025-01-01']);

    expect(cfrResult($e)->blocking)->toBe(['no_profile']);
});
