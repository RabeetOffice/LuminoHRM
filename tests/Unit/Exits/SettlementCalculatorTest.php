<?php

use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Exits\SettlementCalculator;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Feature/Exits/Support/settlement-helpers.php';

/*
 * The settlement money rules with exact rupee figures (design 5.5). Profile P: basic 60,000, house rent 24,000
 * taxable, medical 6,000 not taxable, provident fund 3,000, tax 5% of gross taxable. November 2026 (30 days), calendar
 * days basis, attendance from 1 Nov. "Now" is 5 Dec 2026.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01', 'late_rule_count' => 3]);
});

it('worked example: encashment 6.5 days, bonus, notice shortfall and a loan of 80,000 end at net 0 with a shortfall', function () {
    [$e, , $s] = stExample();
    stEncashment($e, 6.5);
    stLine($s, 'bonus', 10000);
    stLine($s, 'notice_shortfall', 5000);
    $loan = loanFor($e, 80000, 5000, '2026-06');

    $r = SettlementCalculator::calculate($s);

    expect($r->figures)->toMatchArray([
        'basic' => stRs(36000),
        'allowances' => stRs(18000),
        'additions' => stRs(29500),
        'gross' => stRs(83500),
        'attendance_deduction' => stRs(3000),
        'fixed_deductions' => stRs(1800),
        'other_deductions' => stRs(5000),
        'tax' => stRs(3855),
        'loan_deduction' => stRs(69845),
        'net' => 0,
        'encashment_days' => 13,
        'encashment_amount' => stRs(19500),
        'loan_shortfall' => stRs(10155),
    ])
        ->and($r->loanRecovered)->toBe([$loan->id => stRs(69845)])
        ->and($r->blocking)->toBe([])
        ->and(array_column($r->warnings, 'message'))->toContain('Loan balance Rs 10,155 is more than the settlement can recover; the rest stays on the loan.')
        ->and(array_column($r->warnings, 'key'))->not->toContain('installment_reduced')
        ->and($r->breakdown['totals']['net'])->toBe(0)
        ->and($r->breakdown['tax']['gross_taxable_pay'])->toBe(stRs(77100))
        ->and($r->breakdown['settlement']['encashment'])->toMatchArray(['enabled' => true, 'type_name' => 'Annual', 'days' => 6.5, 'amount' => stRs(19500)])
        ->and($r->breakdown['settlement']['loans'])->toBe([['loan_id' => $loan->id, 'type' => 'loan', 'remaining' => stRs(80000), 'recovered' => stRs(69845)]])
        ->and($r->breakdown['settlement']['loan_shortfall'])->toBe(stRs(10155))
        ->and($r->breakdown['settlement']['owed_by_employee'])->toBe(0)
        ->and(collect($r->breakdown['earnings'])->pluck('label')->all())->toContain('Leave encashment (6.5 days)');
});

it('worked example with a loan of 20,000: the loan is recovered in full and net is 49,845', function () {
    [$e, , $s] = stExample();
    stEncashment($e, 6.5);
    stLine($s, 'bonus', 10000);
    stLine($s, 'notice_shortfall', 5000);
    $loan = loanFor($e, 20000, 2000, '2026-12'); // starts after the month: still recovered in full

    $r = SettlementCalculator::calculate($s);

    expect($r->figures['net'])->toBe(stRs(49845))
        ->and($r->figures['loan_deduction'])->toBe(stRs(20000))
        ->and($r->figures['loan_shortfall'])->toBe(0)
        ->and($r->loanRecovered)->toBe([$loan->id => stRs(20000)])
        ->and(array_column($r->warnings, 'key'))->not->toContain('loan_shortfall');
});

it('worked example with encashment off and no loan or lines: net 46,820', function () {
    [, , $s] = stExample();

    $r = SettlementCalculator::calculate($s);

    expect($r->figures)->toMatchArray([
        'gross' => stRs(54000), 'attendance_deduction' => stRs(3000), 'fixed_deductions' => stRs(1800),
        'tax' => stRs(2380), 'net' => stRs(46820), 'encashment_days' => 0, 'encashment_amount' => 0, 'loan_shortfall' => 0,
    ])
        ->and($r->breakdown['settlement']['encashment'])->toBeNull()
        ->and($r->breakdown['settlement']['encashment_note'])->toBe('Leave encashment is off')
        ->and($r->breakdown['period'])->toMatchArray(['from' => '2026-11-01', 'to' => '2026-11-18', 'days_paid' => 18]);
});

it('pays a joiner leaving in the joining month from the joining date to the last day', function () {
    $e = stPerson(['joining_date' => '2026-11-10']);
    $exit = approvedExit($e, '2026-11-18');
    stDays($e, '2026-11-10', '2026-11-18');

    $r = SettlementCalculator::calculate(settlementRow($exit));

    // 9 days: basic 18,000, house rent 7,200, medical 1,800; PF 900; tax 5% of 25,200 = 1,260.
    expect($r->figures)->toMatchArray([
        'basic' => stRs(18000), 'allowances' => stRs(9000), 'gross' => stRs(27000), 'fixed_deductions' => stRs(900),
        'tax' => stRs(1260), 'net' => stRs(24840),
    ])->and($r->breakdown['period'])->toMatchArray(['from' => '2026-11-10', 'to' => '2026-11-18', 'days_paid' => 9]);
});

it('splits a mid-month profile change, each salary paying its own days', function () {
    $e = stPerson();
    salaryProfile($e, 90000, [], [], '2026-11-11');
    $exit = approvedExit($e, '2026-11-18');
    stDays($e, '2026-11-01', '2026-11-18');

    $r = SettlementCalculator::calculate(settlementRow($exit));

    // 1-10 Nov on P: basic 20,000, house rent 8,000, medical 2,000, PF 1,000; 11-18 Nov: basic 24,000.
    expect($r->figures)->toMatchArray([
        'basic' => stRs(44000), 'allowances' => stRs(10000), 'gross' => stRs(54000), 'fixed_deductions' => stRs(1000),
        'tax' => stRs(2600), 'net' => stRs(50400),
    ])->and($r->breakdown['segments'])->toHaveCount(2);
});

it('counts late days only up to the last working day', function () {
    $e = stPerson();
    $exit = approvedExit($e, '2026-11-18');
    stDays($e, '2026-11-01', '2026-11-18', ['2026-11-17' => 'late', '2026-11-18' => 'late']);
    // Rows after the last day are outside the employment window and never count.
    stDays($e, '2026-11-19', '2026-11-20', ['2026-11-19' => 'late', '2026-11-20' => 'late']);

    $r = SettlementCalculator::calculate(settlementRow($exit));

    // Two lates, rule 3: nothing deducted. Tax 5% of 50,400 = 2,520.
    expect($r->figures)->toMatchArray(['attendance_deduction' => 0, 'tax' => stRs(2520), 'net' => stRs(49680)])
        ->and($r->breakdown['attendance']['late_days_deducted'])->toBe(0);
});

it('prorates a fixed tax by the days paid', function () {
    $e = stPerson();
    $e->salaryProfiles()->update(['tax_mode' => 'fixed', 'tax_value' => stRs(9000), 'tax_base' => null]);
    $exit = approvedExit($e, '2026-11-18');
    stDays($e, '2026-11-01', '2026-11-18');

    $r = SettlementCalculator::calculate(settlementRow($exit));

    expect($r->figures['tax'])->toBe(stRs(5400))->and($r->figures['net'])->toBe(stRs(46800));
});

it('warns and encashes nothing when no leave type is set or named Annual', function () {
    [, , $s] = stExample();
    Settings::set('payroll', ['leave_encashment' => true]);
    LeaveType::query()->whereRaw('LOWER(name) = ?', ['annual'])->update(['name' => 'Vacation']);

    $r = SettlementCalculator::calculate($s);

    expect($r->figures['encashment_amount'])->toBe(0)
        ->and($r->figures['net'])->toBe(stRs(46820))
        ->and($r->breakdown['settlement']['encashment']['type_name'])->toBeNull()
        ->and(array_column($r->warnings, 'message'))->toContain(SettlementCalculator::NO_ENCASHMENT_TYPE);
});

it('pays nothing for an overdrawn balance and never deducts it', function () {
    [$e, , $s] = stExample();
    Settings::set('payroll', ['leave_encashment' => true]);
    $type = leaveType('Annual', ['is_paid' => true, 'carry_forward' => false]);
    LeaveBalance::query()->create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'year' => 2026, 'allocated' => 0, 'opening_used' => 3]);

    $r = SettlementCalculator::calculate($s);

    expect($r->figures)->toMatchArray(['encashment_days' => 0, 'encashment_amount' => 0, 'net' => stRs(46820)])
        ->and(collect($r->breakdown['earnings'])->pluck('key')->all())->not->toContain('addition');
});

it('allows a negative net: owed by the employee, no loan recovered, nothing blocking', function () {
    [$e, , $s] = stExample();
    stLine($s, 'notice_shortfall', 60000);
    $loan = loanFor($e, 20000, 5000, '2026-06');

    $r = SettlementCalculator::calculate($s);

    expect($r->figures['net'])->toBe(stRs(-13180))
        ->and($r->figures['loan_deduction'])->toBe(0)
        ->and($r->figures['loan_shortfall'])->toBe(stRs(20000))
        ->and($r->loanRecovered)->toBe([$loan->id => 0])
        ->and($r->blocking)->toBe([])
        ->and($r->breakdown['settlement']['owed_by_employee'])->toBe(stRs(13180))
        ->and(array_column($r->warnings, 'message'))->toContain('Owed by employee: Rs 13,180.');
});
