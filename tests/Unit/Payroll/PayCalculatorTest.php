<?php

use App\Support\Payroll\LoanDue;
use App\Support\Payroll\OvertimeRule;
use App\Support\Payroll\PayAdjustment;
use App\Support\Payroll\PayCalculator;
use App\Support\Payroll\PayComponent;
use App\Support\Payroll\PayInput;
use App\Support\Payroll\PayResult;
use App\Support\Payroll\ProfileSegment;

/*
 * Pure: every PayInput is built by hand. Profile P (design 5.3): basic 60,000; House rent 24,000 taxable; Medical
 * 6,000 not taxable; Provident fund 3,000; tax 5.00% of gross taxable. November 2026 (D = 30) unless stated.
 * Amounts are written in rupees and asserted in paisa (pcRs).
 */

function pcRs(int $rupees): int
{
    return $rupees * 100;
}

/**
 * A segment of profile P (or of the figures given), monthly amounts in rupees except $taxValue (paisa for fixed,
 * basis points for percentage).
 *
 * @param  list<array{0: string, 1: int, 2: bool}>  $allowances
 * @param  list<array{0: string, 1: int}>  $deductions
 */
function pcSeg(
    string $from = '2026-11-01',
    string $to = '2026-11-30',
    int $basic = 60000,
    array $allowances = [['House rent', 24000, true], ['Medical', 6000, false]],
    array $deductions = [['Provident fund', 3000]],
    string $taxMode = 'percentage',
    int $taxValue = 500,
    ?string $taxBase = 'gross_taxable',
    int $profileId = 1,
): ProfileSegment {
    $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;

    return new ProfileSegment(
        $profileId,
        $from,
        $to,
        $days,
        pcRs($basic),
        array_map(fn (array $a) => new PayComponent($a[0], pcRs($a[1]), $a[2]), $allowances),
        array_map(fn (array $d) => new PayComponent($d[0], pcRs($d[1]), false), $deductions),
        $taxMode,
        $taxValue,
        $taxBase,
    );
}

function pcLoan(int $id, int $remainingRupees, int $installmentRupees, bool $skipped = false, string $type = 'loan'): LoanDue
{
    return new LoanDue($id, $type, pcRs($remainingRupees), pcRs($installmentRupees), $skipped);
}

function pcOvertime(bool $enabled = true, string $mode = 'multiple', int $percent = 150, int $fixedRateRupees = 0, int $hours = 8): OvertimeRule
{
    return new OvertimeRule($enabled, $mode, $percent, pcRs($fixedRateRupees), $hours);
}

/**
 * The test's input builder: profile P for the whole of November 2026; named PayInput arguments override.
 *
 * @param  array<string, mixed>  $overrides
 */
function pcInput(array $overrides = []): PayInput
{
    $defaults = [
        'employeeId' => 7,
        'employee' => ['code' => 'LD-0007', 'name' => 'Sara Ahmed', 'department' => 'HR', 'designation' => 'Officer', 'joining_date' => '2024-03-01'],
        'month' => '2026-11',
        'daysInMonth' => 30,
        'windowFrom' => '2026-11-01',
        'windowTo' => '2026-11-30',
        'segments' => [pcSeg()],
        'basis' => 'calendar_days',
        'basisDays' => 30,
        'attendanceHalves' => [],
        'unpaidLeaveHalves' => [],
        'offDates' => [],
        'manualWorkedDates' => [],
        'lateDays' => 0,
        'lateRuleCount' => 3,
        'overtimeMinutes' => 0,
        'overtime' => pcOvertime(enabled: false),
        'attendanceTotals' => [],
        'countedFrom' => '2026-11-01',
        'loans' => [],
        'adjustments' => [],
        'taxOverride' => null,
        'taxOverrideReason' => null,
        'exempt' => false,
    ];

    return new PayInput(...array_merge($defaults, $overrides));
}

/**
 * Calculates and checks the invariants every result must hold.
 *
 * @param  array<string, mixed>  $overrides
 */
function pcCalc(array $overrides = []): PayResult
{
    $r = PayCalculator::calculate(pcInput($overrides));

    // gross − every deduction − tax = net
    expect($r->gross - $r->attendanceDeduction - $r->fixedDeductions - $r->otherDeductions - $r->loanDeduction - $r->tax)
        ->toBe($r->net);
    expect($r->gross)->toBe($r->basic + $r->allowances + $r->overtime + $r->additions);
    expect(array_sum($r->loanDeductions))->toBe($r->loanDeduction);

    // nothing blocks ⇒ net is not below zero
    if ($r->blocking === []) {
        expect($r->net)->toBeGreaterThanOrEqual(0);
    }

    // every stored figure is a whole rupee
    $item = $r->toItemAttributes();
    foreach (['basic', 'allowances', 'overtime', 'additions', 'gross', 'attendance_deduction', 'fixed_deductions',
        'other_deductions', 'loan_deduction', 'taxable_pay', 'tax', 'net'] as $column) {
        expect($item[$column] % 100)->toBe(0, $column);
    }

    // breakdown agrees with the figures
    $b = $r->breakdown;
    expect($b['version'])->toBe(1);
    expect($b['totals'])->toBe([
        'gross' => $item['gross'],
        'attendance_deduction' => $item['attendance_deduction'],
        'fixed_deductions' => $item['fixed_deductions'],
        'other_deductions' => $item['other_deductions'],
        'loan_deduction' => $item['loan_deduction'],
        'tax' => $item['tax'],
        'net' => $item['net'],
    ]);
    expect(array_sum(array_column($b['earnings'], 'amount')))->toBe($r->gross);
    $byKey = fn (string $key) => array_sum(array_column(array_filter($b['deductions'], fn ($l) => $l['key'] === $key), 'amount'));
    expect($byKey('attendance'))->toBe($r->attendanceDeduction);
    expect($byKey('fixed'))->toBe($r->fixedDeductions);
    expect($byKey('adjustment'))->toBe($r->otherDeductions);
    expect($byKey('loan'))->toBe($r->loanDeduction);
    expect($b['tax']['amount'])->toBe($r->tax);
    expect($b['tax']['taxable_pay'])->toBe($r->taxablePay);
    expect($b['period']['days_paid'])->toBe($r->daysPaid);
    expect($b['warnings'])->toBe($r->warnings);

    return $r;
}

/** @return list<string> */
function pcWarningKeys(PayResult $r): array
{
    return array_column($r->warnings, 'key');
}

/** @return array<string, int> label => amount for the breakdown's earnings or deductions */
function pcLines(PayResult $r, string $side): array
{
    $lines = [];
    foreach ($r->breakdown[$side] as $line) {
        $lines[$line['label']] = $line['amount'];
    }

    return $lines;
}

/**
 * Every date of November 2026 with the given halves.
 *
 * @return array<string, int>
 */
function pcEveryDay(int $halves, string $month = '2026-11', int $days = 30): array
{
    $out = [];
    for ($d = 1; $d <= $days; $d++) {
        $out[sprintf('%s-%02d', $month, $d)] = $halves;
    }

    return $out;
}

// ---------------------------------------------------------------- worked examples (design 5.3)

it('example 1: full month with absences, a half day, lates, unpaid leave and a loan nets 64,930', function () {
    $r = pcCalc([
        'attendanceHalves' => ['2026-11-03' => 2, '2026-11-04' => 2, '2026-11-05' => 1],
        'unpaidLeaveHalves' => ['2026-11-06' => 2],
        'lateDays' => 5,
        'lateRuleCount' => 3,
        'loans' => [pcLoan(4, 12000, 5000)],
        'attendanceTotals' => ['present' => 20, 'late' => 5, 'half_day' => 1, 'absent' => 2, 'on_leave' => 1, 'off' => 8],
    ]);

    expect($r->daysPaid)->toBe(30)
        ->and($r->basic)->toBe(pcRs(60000))
        ->and($r->allowances)->toBe(pcRs(30000))
        ->and($r->gross)->toBe(pcRs(90000))
        ->and($r->attendanceDeduction)->toBe(pcRs(13500))
        ->and($r->taxablePay)->toBe(pcRs(71400))
        ->and($r->tax)->toBe(pcRs(3570))
        ->and($r->fixedDeductions)->toBe(pcRs(3000))
        ->and($r->loanDeduction)->toBe(pcRs(5000))
        ->and($r->loanDeductions)->toBe([4 => pcRs(5000)])
        ->and($r->net)->toBe(pcRs(64930))
        ->and($r->blocking)->toBe([])
        ->and($r->warnings)->toBe([]);

    $b = $r->breakdown;
    expect($b['attendance'])->toBe([
        'counted_from' => '2026-11-01',
        'present' => 20,
        'late' => 5,
        'half_day' => 1,
        'absent' => 2,
        'on_leave' => 1,
        'unpaid_leave' => 1,
        'paid_leave' => 0,
        'off' => 8,
        'late_days_deducted' => 1,
        'deducted_halves' => 9,
        'overtime_minutes' => 0,
    ]);
    expect($b['deductions'][0])->toBe(['key' => 'attendance', 'label' => 'Attendance (4.5 days)', 'amount' => pcRs(13500), 'taxable' => false]);
    expect($b['deductions'])->toContain(['key' => 'loan', 'label' => 'Loan installment', 'amount' => pcRs(5000), 'taxable' => false, 'loan_id' => 4, 'due' => pcRs(5000)]);
    expect($b['tax'])->toBe([
        'mode' => 'percentage', 'base' => 'gross_taxable', 'rate_bp' => 500, 'taxable_pay' => pcRs(71400), 'gross_taxable_pay' => pcRs(71400),
        'amount' => pcRs(3570), 'overridden' => false, 'override_reason' => null,
    ]);
    expect(pcLines($r, 'earnings'))->toBe(['Basic salary' => pcRs(60000), 'House rent' => pcRs(24000), 'Medical' => pcRs(6000)]);
});

it('example 2: joiner on 16 Nov is paid 15 days and nets 41,400', function () {
    $r = pcCalc([
        'windowFrom' => '2026-11-16',
        'segments' => [pcSeg('2026-11-16', '2026-11-30')],
    ]);

    expect($r->daysPaid)->toBe(15)
        ->and($r->basic)->toBe(pcRs(30000))
        ->and($r->allowances)->toBe(pcRs(15000))
        ->and($r->gross)->toBe(pcRs(45000))
        ->and($r->fixedDeductions)->toBe(pcRs(1500))
        ->and($r->taxablePay)->toBe(pcRs(42000))
        ->and($r->tax)->toBe(pcRs(2100))
        ->and($r->net)->toBe(pcRs(41400))
        ->and($r->breakdown['period']['days_paid'])->toBe(15)
        ->and($r->warnings)->toBe([]);
});

it('example 3: joiner on 10 Dec 2026 (22 of 31 days) nets 58,761', function () {
    $r = pcCalc([
        'month' => '2026-12',
        'daysInMonth' => 31,
        'windowFrom' => '2026-12-10',
        'windowTo' => '2026-12-31',
        'basisDays' => 31,
        'segments' => [pcSeg('2026-12-10', '2026-12-31')],
    ]);

    expect($r->daysPaid)->toBe(22)
        ->and(pcLines($r, 'earnings'))->toBe(['Basic salary' => pcRs(42581), 'House rent' => pcRs(17032), 'Medical' => pcRs(4258)])
        ->and($r->gross)->toBe(pcRs(63871))
        ->and($r->fixedDeductions)->toBe(pcRs(2129))
        ->and($r->taxablePay)->toBe(pcRs(59613))
        ->and($r->tax)->toBe(pcRs(2981))
        ->and($r->net)->toBe(pcRs(58761));
});

it('example 4: a raise from 16 Nov splits the month into two segments', function () {
    $r = pcCalc([
        'segments' => [
            pcSeg('2026-11-01', '2026-11-15'),
            pcSeg('2026-11-16', '2026-11-30', 66000, [['House rent', 26400, true], ['Medical', 6000, false]], profileId: 2),
        ],
        'attendanceHalves' => ['2026-11-20' => 2],
    ]);

    expect(pcLines($r, 'earnings'))->toBe(['Basic salary' => pcRs(63000), 'House rent' => pcRs(25200), 'Medical' => pcRs(6000)])
        ->and($r->gross)->toBe(pcRs(94200))
        ->and($r->attendanceDeduction)->toBe(pcRs(3280))
        ->and($r->fixedDeductions)->toBe(pcRs(3000))
        ->and($r->taxablePay)->toBe(pcRs(88200 - 3080))
        ->and($r->tax)->toBe(pcRs(4256))
        ->and($r->net)->toBe(pcRs(94200 - 3280 - 3000 - 4256))
        ->and(pcLines($r, 'deductions'))->toBe(['Attendance (1 day)' => pcRs(3280), 'Provident fund' => pcRs(3000)]);

    expect(array_column($r->breakdown['segments'], 'profile_id'))->toBe([1, 2])
        ->and($r->breakdown['segments'][1])->toBe([
            'profile_id' => 2, 'from' => '2026-11-16', 'to' => '2026-11-30', 'days' => 15,
            'gross' => pcRs(98400), 'taxable_gross' => pcRs(92400), 'basic' => pcRs(66000),
        ]);
});

it('example 5: day pay on the thirty and working-days bases', function () {
    $thirty = pcCalc([
        'month' => '2027-02',
        'daysInMonth' => 28,
        'windowFrom' => '2027-02-01',
        'windowTo' => '2027-02-28',
        'basis' => 'thirty',
        'basisDays' => 30,
        'segments' => [pcSeg('2027-02-01', '2027-02-28')],
        'attendanceHalves' => ['2027-02-10' => 2],
    ]);
    expect($thirty->gross)->toBe(pcRs(90000))
        ->and($thirty->attendanceDeduction)->toBe(pcRs(3000))
        ->and($thirty->breakdown['period']['basis'])->toBe('thirty')
        ->and($thirty->breakdown['period']['basis_days'])->toBe(30);

    $working = pcCalc([
        'basis' => 'working_days',
        'basisDays' => 21,
        'attendanceHalves' => ['2026-11-03' => 2],
    ]);
    expect($working->attendanceDeduction)->toBe(pcRs(4286));
});

it('example 6: overtime at 150% and at a fixed hourly rate; off means no line', function () {
    $multiple = pcCalc(['overtime' => pcOvertime(), 'overtimeMinutes' => 600]);
    expect($multiple->overtime)->toBe(pcRs(5625))
        ->and($multiple->gross)->toBe(pcRs(95625))
        ->and($multiple->taxablePay)->toBe(pcRs(84000 + 5625))
        ->and($multiple->tax)->toBe(pcRs(4481)) // 4,481.25
        ->and($multiple->breakdown['earnings'])->toContain(['key' => 'overtime', 'label' => 'Overtime (10 h)', 'amount' => pcRs(5625), 'taxable' => true]);

    $fixed = pcCalc(['overtime' => pcOvertime(mode: 'fixed', fixedRateRupees: 300), 'overtimeMinutes' => 600]);
    expect($fixed->overtime)->toBe(pcRs(3000));

    $off = pcCalc(['overtime' => pcOvertime(enabled: false), 'overtimeMinutes' => 600]);
    expect($off->overtime)->toBe(0)
        ->and(array_column($off->breakdown['earnings'], 'key'))->not->toContain('overtime')
        ->and($off->breakdown['attendance']['overtime_minutes'])->toBe(600);

    $exempt = pcCalc(['overtime' => pcOvertime(), 'overtimeMinutes' => 600, 'exempt' => true]);
    expect($exempt->overtime)->toBe(0);

    $minutes = pcCalc(['overtime' => pcOvertime(mode: 'fixed', fixedRateRupees: 300), 'overtimeMinutes' => 605]);
    expect(pcLines($minutes, 'earnings'))->toHaveKey('Overtime (10 h 5 min)')
        ->and($minutes->overtime)->toBe(pcRs(3025));
});

it('example 7: an allowance of 15,001 for 15 of 30 days rounds half up to 7,501', function () {
    $r = pcCalc([
        'windowFrom' => '2026-11-16',
        'segments' => [pcSeg('2026-11-16', '2026-11-30', allowances: [['Special', 15001, true]])],
    ]);

    expect(pcLines($r, 'earnings')['Special'])->toBe(pcRs(7501));
});

// ---------------------------------------------------------------- segments and days

it('blocks with no_profile and zero figures when no segment exists', function () {
    $r = pcCalc(['segments' => [], 'loans' => [pcLoan(4, 5000, 5000)], 'attendanceHalves' => ['2026-11-03' => 2]]);

    expect($r->blocking)->toBe(['no_profile'])
        ->and($r->toItemAttributes())->toMatchArray([
            'days_paid' => 0, 'basic' => 0, 'allowances' => 0, 'overtime' => 0, 'additions' => 0, 'gross' => 0,
            'attendance_deduction' => 0, 'fixed_deductions' => 0, 'other_deductions' => 0, 'loan_deduction' => 0,
            'taxable_pay' => 0, 'tax' => 0, 'net' => 0,
        ])
        ->and($r->loanDeductions)->toBe([])
        ->and($r->breakdown['segments'])->toBe([]);
});

it('pays a one-day joiner on the last day of the month', function () {
    $r = pcCalc(['windowFrom' => '2026-11-30', 'segments' => [pcSeg('2026-11-30', '2026-11-30')]]);

    expect($r->daysPaid)->toBe(1)
        ->and($r->gross)->toBe(pcRs(3000))
        ->and($r->fixedDeductions)->toBe(pcRs(100))
        ->and($r->tax)->toBe(pcRs(140))
        ->and($r->net)->toBe(pcRs(2760));
});

it('warns when days of the window have no profile and does not pay them', function () {
    $r = pcCalc(['segments' => [pcSeg('2026-11-20', '2026-11-30')]]);

    expect($r->daysPaid)->toBe(11)
        ->and($r->gross)->toBe(pcRs(33000))
        ->and(pcWarningKeys($r))->toBe(['days_without_profile'])
        ->and($r->warnings[0]['message'])->toContain('19 days');
});

it('ignores deducted dates that fall in no segment', function () {
    $r = pcCalc([
        'windowFrom' => '2026-11-16',
        'segments' => [pcSeg('2026-11-16', '2026-11-30')],
        'attendanceHalves' => ['2026-11-05' => 2],
        'unpaidLeaveHalves' => ['2026-11-06' => 2],
    ]);

    expect($r->attendanceDeduction)->toBe(0)
        ->and($r->breakdown['attendance']['deducted_halves'])->toBe(0)
        ->and($r->net)->toBe(pcRs(41400));
});

it('charges a date at the profile of its segment and lates at the last profile', function () {
    $raise = [pcSeg('2026-11-01', '2026-11-15'), pcSeg('2026-11-16', '2026-11-30', 66000, [['House rent', 26400, true], ['Medical', 6000, false]], profileId: 2)];

    expect(pcCalc(['segments' => $raise, 'attendanceHalves' => ['2026-11-05' => 2]])->attendanceDeduction)->toBe(pcRs(3000))
        ->and(pcCalc(['segments' => $raise, 'lateDays' => 3])->attendanceDeduction)->toBe(pcRs(3280));
});

it('sums a component of the same name across segments into one line and keeps different names apart', function () {
    $r = pcCalc(['segments' => [
        pcSeg('2026-11-01', '2026-11-15', deductions: [['Provident fund', 3000]]),
        pcSeg('2026-11-16', '2026-11-30', allowances: [['House rent', 24000, true], ['Fuel', 6000, false]], deductions: [['Provident fund', 3000], ['Canteen', 600]], profileId: 2),
    ]]);

    expect(pcLines($r, 'earnings'))->toBe(['Basic salary' => pcRs(60000), 'House rent' => pcRs(24000), 'Medical' => pcRs(3000), 'Fuel' => pcRs(3000)])
        ->and(pcLines($r, 'deductions'))->toBe(['Provident fund' => pcRs(3000), 'Canteen' => pcRs(300)]);
});

// ---------------------------------------------------------------- deducted halves, lates, caps and conflicts

it('applies the late rule: rounded down, 0 = off', function (int $lateDays, int $rule, int $expectedDays) {
    $r = pcCalc(['lateDays' => $lateDays, 'lateRuleCount' => $rule]);

    expect($r->breakdown['attendance']['late_days_deducted'])->toBe($expectedDays)
        ->and($r->attendanceDeduction)->toBe(pcRs(3000 * $expectedDays));
})->with([
    'rule off' => [10, 0, 0],
    'below the rule' => [2, 3, 0],
    'at the rule' => [3, 3, 1],
    'above the rule' => [5, 3, 1],
    'twice the rule' => [6, 3, 2],
    'no lates' => [0, 3, 0],
]);

it('deducts a half day as half a day', function () {
    $r = pcCalc(['attendanceHalves' => ['2026-11-05' => 1]]);

    expect($r->attendanceDeduction)->toBe(pcRs(1500))
        ->and(pcLines($r, 'deductions'))->toHaveKey('Attendance (0.5 days)');
});

it('deducts at most one day per date', function (array $attendance, array $unpaid, int $halves) {
    $r = pcCalc(['attendanceHalves' => $attendance, 'unpaidLeaveHalves' => $unpaid]);

    expect($r->breakdown['attendance']['deducted_halves'])->toBe($halves)
        ->and($r->attendanceDeduction)->toBe(pcRs(1500 * $halves));
})->with([
    'unpaid half + half-leave absent' => [['2026-11-10' => 1], ['2026-11-10' => 1], 2],
    'manual absent + unpaid full day' => [['2026-11-11' => 2], ['2026-11-11' => 2], 2],
    'absent + unpaid half' => [['2026-11-12' => 2], ['2026-11-12' => 1], 2],
    'half day + unpaid half on another date' => [['2026-11-12' => 1], ['2026-11-13' => 1], 2],
    'unpaid full day alone' => [[], ['2026-11-13' => 2], 2],
]);

it('does not deduct unpaid leave on a day off and warns', function () {
    $r = pcCalc(['unpaidLeaveHalves' => ['2026-11-14' => 2], 'offDates' => ['2026-11-14', '2026-11-15'], 'attendanceTotals' => ['on_leave' => 0]]);

    expect($r->attendanceDeduction)->toBe(0)
        ->and($r->breakdown['attendance']['deducted_halves'])->toBe(0)
        ->and($r->breakdown['attendance']['unpaid_leave'])->toBe(0)
        ->and(pcWarningKeys($r))->toBe(['unpaid_leave_on_off_day'])
        ->and($r->warnings[0]['message'])->toContain('14 Nov')
        ->and(array_column($r->breakdown['deductions'], 'key'))->not->toContain('attendance');
});

it('deducts unpaid leave on a day corrected as worked and warns', function () {
    $r = pcCalc(['unpaidLeaveHalves' => ['2026-11-11' => 2], 'manualWorkedDates' => ['2026-11-11']]);

    expect($r->attendanceDeduction)->toBe(pcRs(3000))
        ->and(pcWarningKeys($r))->toBe(['unpaid_leave_on_worked_day'])
        ->and($r->warnings[0]['message'])->toContain('11 Nov');
});

it('gives exempt staff no attendance or late deduction but still deducts unpaid leave', function () {
    $r = pcCalc([
        'exempt' => true,
        'attendanceHalves' => ['2026-11-03' => 2, '2026-11-04' => 1],
        'unpaidLeaveHalves' => ['2026-11-06' => 2],
        'lateDays' => 6,
    ]);

    expect($r->attendanceDeduction)->toBe(pcRs(3000))
        ->and($r->breakdown['attendance']['late_days_deducted'])->toBe(0)
        ->and($r->breakdown['attendance']['deducted_halves'])->toBe(2);
});

it('caps the attendance deduction at earned gross; tax 0; below-zero net blocks', function () {
    $calendar = pcCalc(['attendanceHalves' => pcEveryDay(2)]);
    expect($calendar->attendanceDeduction)->toBe(pcRs(90000))
        ->and($calendar->tax)->toBe(0)
        ->and($calendar->net)->toBe(pcRs(-3000))
        ->and($calendar->blocking)->toBe(['negative_net']);

    // working days: 60 halves over 2 × 21 would be 128,571 uncapped
    $working = pcCalc([
        'basis' => 'working_days',
        'basisDays' => 21,
        'attendanceHalves' => pcEveryDay(2),
        'lateDays' => 9,
        'segments' => [pcSeg(deductions: [])],
        'loans' => [pcLoan(4, 5000, 5000)],
    ]);
    expect($working->attendanceDeduction)->toBe(pcRs(90000))
        ->and($working->taxablePay)->toBe(0)
        ->and($working->tax)->toBe(0)
        ->and($working->loanDeduction)->toBe(0)
        ->and($working->net)->toBe(0)
        ->and($working->blocking)->toBe([]);
});

it('never reduces fixed deductions or fixed tax by absences', function () {
    $r = pcCalc([
        'segments' => [pcSeg(taxMode: 'fixed', taxValue: pcRs(2000), taxBase: null)],
        'attendanceHalves' => ['2026-11-03' => 2, '2026-11-04' => 2],
    ]);

    expect($r->fixedDeductions)->toBe(pcRs(3000))
        ->and($r->tax)->toBe(pcRs(2000))
        ->and($r->attendanceDeduction)->toBe(pcRs(6000));
});

// ---------------------------------------------------------------- tax

it('takes no tax in mode none and still fills taxable pay', function () {
    $r = pcCalc([
        'segments' => [pcSeg(taxMode: 'none', taxValue: 0, taxBase: null)],
        'attendanceHalves' => ['2026-11-03' => 2],
    ]);

    expect($r->tax)->toBe(0)
        ->and($r->taxablePay)->toBe(pcRs(84000 - 2800))
        ->and($r->breakdown['tax'])->toMatchArray(['mode' => 'none', 'base' => null, 'rate_bp' => null]);
});

it('prorates a fixed tax by calendar days', function () {
    $r = pcCalc([
        'windowFrom' => '2026-11-16',
        'segments' => [pcSeg('2026-11-16', '2026-11-30', taxMode: 'fixed', taxValue: pcRs(2000), taxBase: null)],
    ]);

    expect($r->tax)->toBe(pcRs(1000))
        ->and($r->taxablePay)->toBe(pcRs(42000));
});

it('takes percentage tax of basic after the basic share of the attendance deduction', function () {
    // two absent days: basic share R(60,000 × 4, 60) = 4,000 → R(56,000 × 500, 10,000) = 2,800
    $r = pcCalc([
        'segments' => [pcSeg(taxBase: 'basic')],
        'attendanceHalves' => ['2026-11-03' => 2, '2026-11-04' => 2],
    ]);

    expect($r->taxablePay)->toBe(pcRs(56000))
        ->and($r->tax)->toBe(pcRs(2800))
        ->and($r->breakdown['tax']['base'])->toBe('basic');

    // one absent day: basic share 2,000 → R(58,000 × 500, 10,000) = 2,900
    $one = pcCalc(['segments' => [pcSeg(taxBase: 'basic')], 'attendanceHalves' => ['2026-11-03' => 2]]);
    expect($one->taxablePay)->toBe(pcRs(58000))->and($one->tax)->toBe(pcRs(2900));
});

it('uses the tax mode and rate of the last segment', function () {
    $r = pcCalc([
        'segments' => [
            pcSeg('2026-11-01', '2026-11-15', taxMode: 'fixed', taxValue: pcRs(2000), taxBase: null),
            pcSeg('2026-11-16', '2026-11-30', 66000, [['House rent', 26400, true], ['Medical', 6000, false]], taxValue: 1000, profileId: 2),
        ],
        'attendanceHalves' => ['2026-11-20' => 2],
    ]);

    expect($r->taxablePay)->toBe(pcRs(85120))
        ->and($r->tax)->toBe(pcRs(8512))
        ->and($r->breakdown['tax']['rate_bp'])->toBe(1000);
});

it('prorates a fixed tax over every segment at the last profile\'s amount', function () {
    $r = pcCalc(['segments' => [
        pcSeg('2026-11-01', '2026-11-15', taxMode: 'fixed', taxValue: pcRs(1000), taxBase: null),
        pcSeg('2026-11-16', '2026-11-30', taxMode: 'fixed', taxValue: pcRs(3000), taxBase: null, profileId: 2),
    ]]);

    expect($r->tax)->toBe(pcRs(3000));
});

it('uses HR\'s tax override and gives the same result on recalculation', function () {
    $overrides = ['taxOverride' => pcRs(1234), 'taxOverrideReason' => 'Exemption certificate', 'attendanceHalves' => ['2026-11-03' => 2]];
    $r = pcCalc($overrides);

    expect($r->tax)->toBe(pcRs(1234))
        ->and($r->taxablePay)->toBe(pcRs(84000 - 2800))
        ->and($r->breakdown['tax'])->toMatchArray(['overridden' => true, 'override_reason' => 'Exemption certificate', 'amount' => pcRs(1234)])
        ->and($r->net)->toBe(pcRs(90000 - 3000 - 3000 - 1234));

    expect(pcCalc($overrides)->toArray())->toBe($r->toArray());
});

it('allows an override of zero', function () {
    $r = pcCalc(['taxOverride' => 0, 'taxOverrideReason' => 'Below threshold']);

    expect($r->tax)->toBe(0)->and($r->breakdown['tax']['overridden'])->toBeTrue();
});

// ---------------------------------------------------------------- one-off items

it('adds one-off additions (taxable or not) to gross and keeps deductions apart', function () {
    $taxable = pcCalc(['adjustments' => [new PayAdjustment(1, 'Bonus', 'addition', pcRs(10000), true)]]);
    expect($taxable->additions)->toBe(pcRs(10000))
        ->and($taxable->gross)->toBe(pcRs(100000))
        ->and($taxable->taxablePay)->toBe(pcRs(94000))
        ->and($taxable->tax)->toBe(pcRs(4700))
        ->and($taxable->breakdown['earnings'])->toContain(['key' => 'addition', 'label' => 'Bonus', 'amount' => pcRs(10000), 'taxable' => true, 'adjustment_id' => 1]);

    $notTaxable = pcCalc(['adjustments' => [new PayAdjustment(2, 'Travel', 'addition', pcRs(10000), false)]]);
    expect($notTaxable->gross)->toBe(pcRs(100000))
        ->and($notTaxable->tax)->toBe(pcRs(4200));

    $deduction = pcCalc(['adjustments' => [new PayAdjustment(3, 'Canteen', 'deduction', pcRs(1500), false)]]);
    expect($deduction->otherDeductions)->toBe(pcRs(1500))
        ->and($deduction->gross)->toBe(pcRs(90000))
        ->and($deduction->tax)->toBe(pcRs(4200))
        ->and($deduction->net)->toBe(pcRs(90000 - 3000 - 4200 - 1500))
        ->and($deduction->breakdown['deductions'])->toContain(['key' => 'adjustment', 'label' => 'Canteen', 'amount' => pcRs(1500), 'taxable' => false, 'adjustment_id' => 3]);
});

// ---------------------------------------------------------------- loans and pay short

it('reduces an advance when pay is short so net is zero', function () {
    $r = pcCalc([
        'windowFrom' => '2026-11-28',
        'segments' => [pcSeg('2026-11-28', '2026-11-30')],
        'loans' => [pcLoan(9, 10000, 10000, type: 'advance')],
    ]);

    expect($r->gross)->toBe(pcRs(9000))
        ->and($r->fixedDeductions)->toBe(pcRs(300))
        ->and($r->tax)->toBe(pcRs(420))
        ->and($r->loanDeduction)->toBe(pcRs(8280))
        ->and($r->loanDeductions)->toBe([9 => pcRs(8280)])
        ->and($r->net)->toBe(0)
        ->and(pcWarningKeys($r))->toBe(['installment_reduced'])
        ->and($r->blocking)->toBe([])
        ->and($r->breakdown['deductions'])->toContain(['key' => 'loan', 'label' => 'Advance recovery', 'amount' => pcRs(8280), 'taxable' => false, 'loan_id' => 9, 'due' => pcRs(10000)]);
});

it('blocks with negative_net and deducts no loan when net is below zero before loans', function () {
    $r = pcCalc([
        'windowFrom' => '2026-11-30',
        'segments' => [pcSeg('2026-11-30', '2026-11-30')],
        'taxOverride' => pcRs(5000),
        'taxOverrideReason' => 'Arrears',
        'loans' => [pcLoan(4, 12000, 5000)],
    ]);

    expect($r->gross)->toBe(pcRs(3000))
        ->and($r->fixedDeductions)->toBe(pcRs(100))
        ->and($r->blocking)->toContain('negative_net')
        ->and($r->loanDeduction)->toBe(0)
        ->and($r->loanDeductions)->toBe([4 => 0])
        ->and($r->net)->toBe(pcRs(3000 - 100 - 5000));
});

it('keeps a skipped loan as a zero line', function () {
    $r = pcCalc(['loans' => [pcLoan(4, 12000, 5000, skipped: true)]]);

    expect($r->loanDeduction)->toBe(0)
        ->and($r->loanDeductions)->toBe([4 => 0])
        ->and($r->net)->toBe(pcRs(90000 - 3000 - 4200))
        ->and($r->warnings)->toBe([])
        ->and(pcLines($r, 'deductions'))->toHaveKey('Loan installment (skipped this month)');
});

it('takes the oldest loan first when pay is short', function () {
    $r = pcCalc([
        'windowFrom' => '2026-11-28',
        'segments' => [pcSeg('2026-11-28', '2026-11-30')],
        'loans' => [pcLoan(4, 20000, 5000), pcLoan(9, 20000, 5000)],
    ]);

    expect($r->loanDeductions)->toBe([4 => pcRs(5000), 9 => pcRs(3280)])
        ->and($r->net)->toBe(0)
        ->and(pcWarningKeys($r))->toBe(['installment_reduced'])
        ->and($r->warnings[0]['message'])->toContain('Rs 3,280 of Rs 5,000');
});

it('deducts only what remains when it is below the installment', function () {
    $r = pcCalc(['loans' => [pcLoan(4, 2000, 5000)]]);

    expect($r->loanDeduction)->toBe(pcRs(2000))
        ->and($r->warnings)->toBe([])
        ->and($r->net)->toBe(pcRs(90000 - 3000 - 4200 - 2000));
});

// ---------------------------------------------------------------- breakdown

it('writes the version 1 breakdown with every key', function () {
    $r = pcCalc();

    expect(array_keys($r->breakdown))->toBe(['version', 'employee', 'period', 'segments', 'earnings', 'deductions', 'tax', 'attendance', 'totals', 'warnings'])
        ->and($r->breakdown['employee'])->toBe(['id' => 7, 'code' => 'LD-0007', 'name' => 'Sara Ahmed', 'department' => 'HR', 'designation' => 'Officer', 'joining_date' => '2024-03-01'])
        ->and($r->breakdown['period'])->toBe([
            'month' => '2026-11', 'from' => '2026-11-01', 'to' => '2026-11-30', 'days_in_month' => 30,
            'days_paid' => 30, 'basis' => 'calendar_days', 'basis_days' => 30,
        ])
        ->and(array_keys($r->breakdown['tax']))->toBe(['mode', 'base', 'rate_bp', 'taxable_pay', 'gross_taxable_pay', 'amount', 'overridden', 'override_reason'])
        ->and(array_keys($r->breakdown['attendance']))->toBe(['counted_from', 'present', 'late', 'half_day', 'absent', 'on_leave', 'unpaid_leave', 'paid_leave', 'off', 'late_days_deducted', 'deducted_halves', 'overtime_minutes'])
        ->and(json_decode(json_encode($r->breakdown, JSON_THROW_ON_ERROR), true))->toBe($r->breakdown);
});

it('splits leave into unpaid and paid in the breakdown', function () {
    $r = pcCalc(['unpaidLeaveHalves' => ['2026-11-06' => 1], 'attendanceTotals' => ['on_leave' => 3]]);

    expect($r->breakdown['attendance']['unpaid_leave'])->toBe(0.5)
        ->and($r->breakdown['attendance']['paid_leave'])->toBe(2.5);
});

// ---------------------------------------------------------------- fix round (Task 2 review)

it('prorates a fixed tax over two segments as one amount, rounded once', function () {
    // 1,001 × 15 / 30 = 500.5 per segment: rounding each would give 501 + 501 = 1,002.
    $r = pcCalc(['segments' => [
        pcSeg('2026-11-01', '2026-11-15', taxMode: 'fixed', taxValue: pcRs(1001), taxBase: null),
        pcSeg('2026-11-16', '2026-11-30', taxMode: 'fixed', taxValue: pcRs(1001), taxBase: null, profileId: 2),
    ]]);

    expect($r->tax)->toBe(pcRs(1001));
});

it('without a loader paid_leave total, subtracts only unpaid leave on counted days that are not days off', function () {
    $before = pcCalc([
        'unpaidLeaveHalves' => ['2026-11-02' => 2],
        'countedFrom' => '2026-11-05',
        'attendanceTotals' => ['on_leave' => 1],
    ]);
    expect($before->breakdown['attendance']['paid_leave'])->toBe(1);

    $offDay = pcCalc([
        'unpaidLeaveHalves' => ['2026-11-08' => 2, '2026-11-10' => 1],
        'offDates' => ['2026-11-08'],
        'attendanceTotals' => ['on_leave' => 2],
    ]);
    expect($offDay->breakdown['attendance']['paid_leave'])->toBe(1.5);
});

it('uses the loader\'s paid_leave total when it is supplied', function () {
    // Same unpaid leave as the fallback case; the loader's figure (from the cells) wins over the subtraction.
    $r = pcCalc([
        'unpaidLeaveHalves' => ['2026-11-02' => 2],
        'countedFrom' => '2026-11-05',
        'attendanceTotals' => ['on_leave' => 3, 'paid_leave' => 2.5],
    ]);

    expect($r->breakdown['attendance']['paid_leave'])->toBe(2.5)
        ->and($r->breakdown['attendance']['unpaid_leave'])->toBe(1);
});

it('labels the non-taxable line when one allowance name has both taxabilities', function () {
    $r = pcCalc(['segments' => [
        pcSeg('2026-11-01', '2026-11-15', allowances: [['House rent', 24000, true], ['Medical', 6000, false]]),
        pcSeg('2026-11-16', '2026-11-30', allowances: [['House rent', 24000, true], ['Medical', 6000, true]], profileId: 2),
    ]]);

    expect(pcLines($r, 'earnings'))->toBe([
        'Basic salary' => pcRs(60000),
        'House rent' => pcRs(24000),
        'Medical (not taxable)' => pcRs(3000),
        'Medical' => pcRs(3000),
    ])
        ->and(count($r->breakdown['earnings']))->toBe(4)
        ->and($r->taxablePay)->toBe(pcRs(84000 + 3000));
});

it('refuses a tax override that is negative or not whole rupees', function (int $override) {
    PayCalculator::calculate(pcInput(['taxOverride' => $override, 'taxOverrideReason' => 'x']));
})->with([-100, 150, -1])->throws(InvalidArgumentException::class);

it('reviewer case: December with a raise on the 16th, an absence, a half day and 3 lates nets 79,614', function () {
    /*
     * D = 31. Profile P 1–15 Dec (15 days), raise 66,000 / 26,400 / 6,000 (gross 98,400, taxable 92,400) 16–31 Dec (16).
     * Basic R(60,000×15, 31) 29,032 + R(66,000×16, 31) 34,065 = 63,097; house rent 11,613 + 13,626 = 25,239;
     * medical 2,903 + 3,097 = 6,000; gross 94,336. Halves: 10 Dec 2 (seg 1); 20 Dec 1 + lates 3 → 1 day = 2 (seg 2) → 3.
     * Attendance R(90,000×2, 62) 2,903 + R(98,400×3, 62) 4,761 = 7,664. Taxable share 2,710 + 4,471 = 7,181;
     * taxable pay 88,336 − 7,181 = 81,155; tax R(81,155×500, 10,000) = 4,058. PF 1,452 + 1,548 = 3,000.
     * Net 94,336 − 7,664 − 3,000 − 4,058 = 79,614.
     */
    $r = pcCalc([
        'month' => '2026-12',
        'daysInMonth' => 31,
        'windowFrom' => '2026-12-01',
        'windowTo' => '2026-12-31',
        'basisDays' => 31,
        'countedFrom' => '2026-12-01',
        'segments' => [
            pcSeg('2026-12-01', '2026-12-15'),
            pcSeg('2026-12-16', '2026-12-31', 66000, [['House rent', 26400, true], ['Medical', 6000, false]], profileId: 2),
        ],
        'attendanceHalves' => ['2026-12-10' => 2, '2026-12-20' => 1],
        'lateDays' => 3,
    ]);

    expect($r->gross)->toBe(pcRs(94336))
        ->and(pcLines($r, 'earnings'))->toBe(['Basic salary' => pcRs(63097), 'House rent' => pcRs(25239), 'Medical' => pcRs(6000)])
        ->and($r->attendanceDeduction)->toBe(pcRs(7664))
        ->and($r->fixedDeductions)->toBe(pcRs(3000))
        ->and($r->taxablePay)->toBe(pcRs(81155))
        ->and($r->tax)->toBe(pcRs(4058))
        ->and($r->net)->toBe(pcRs(79614));
});

it('reviewer case: a February 2027 joiner on the 20th (9 days) nets 26,615', function () {
    $r = pcCalc([
        'month' => '2027-02',
        'daysInMonth' => 28,
        'windowFrom' => '2027-02-20',
        'windowTo' => '2027-02-28',
        'basisDays' => 28,
        'countedFrom' => '2027-02-20',
        'segments' => [pcSeg('2027-02-20', '2027-02-28')],
    ]);

    expect($r->daysPaid)->toBe(9)
        ->and(pcLines($r, 'earnings'))->toBe(['Basic salary' => pcRs(19286), 'House rent' => pcRs(7714), 'Medical' => pcRs(1929)])
        ->and($r->gross)->toBe(pcRs(28929))
        ->and($r->fixedDeductions)->toBe(pcRs(964))
        ->and($r->tax)->toBe(pcRs(1350))
        ->and($r->net)->toBe(pcRs(26615));
});

it('reviewer case: two half days plus lates at rule 3', function (int $lates, int $deduction) {
    $r = pcCalc([
        'attendanceHalves' => ['2026-11-03' => 1, '2026-11-04' => 1],
        'lateDays' => $lates,
        'lateRuleCount' => 3,
    ]);

    expect($r->attendanceDeduction)->toBe(pcRs($deduction));
})->with([[2, 3000], [3, 6000], [5, 6000], [6, 9000]]);

it('reviewer case: net exactly 0 before loans deducts no loan, warns, and does not block', function () {
    // One day: gross 3,000, provident fund 100, tax override 2,900 → net 0 before loans.
    $r = pcCalc([
        'windowFrom' => '2026-11-30',
        'segments' => [pcSeg('2026-11-30', '2026-11-30')],
        'taxOverride' => pcRs(2900),
        'taxOverrideReason' => 'Arrears',
        'loans' => [pcLoan(4, 12000, 5000)],
    ]);

    expect($r->net)->toBe(0)
        ->and($r->loanDeduction)->toBe(0)
        ->and($r->loanDeductions)->toBe([4 => 0])
        ->and(pcWarningKeys($r))->toBe(['installment_reduced'])
        ->and($r->blocking)->toBe([]);
});

it('reviewer case: date keys in reverse order give an identical result', function () {
    $forward = [
        'attendanceHalves' => ['2026-11-03' => 2, '2026-11-04' => 2, '2026-11-05' => 1],
        'unpaidLeaveHalves' => ['2026-11-06' => 2, '2026-11-14' => 2],
        'offDates' => ['2026-11-14'],
        'lateDays' => 5,
        'loans' => [pcLoan(4, 12000, 5000)],
        'attendanceTotals' => ['on_leave' => 2],
    ];
    $reverse = [
        ...$forward,
        'attendanceHalves' => array_reverse($forward['attendanceHalves'], true),
        'unpaidLeaveHalves' => array_reverse($forward['unpaidLeaveHalves'], true),
        'offDates' => array_reverse($forward['offDates']),
    ];

    expect(pcCalc($reverse)->toArray())->toBe(pcCalc($forward)->toArray());
});
