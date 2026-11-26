<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LoanSkip;
use App\Support\Payroll\PayCalculator;
use App\Support\Payroll\PayInput;
use App\Support\Payroll\PayInputLoader;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The two settlement options of PayInputLoader::load (window_to, loans_in_full). A payroll run never passes them, so a
 * load without them is exactly what it was.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01']);
    $this->e = Employee::factory()->create(['joining_date' => '2025-01-01']);
    salaryProfile($this->e);

    for ($d = 1; $d <= 30; $d++) {
        Attendance::factory()->create(['employee_id' => $this->e->id, 'date' => sprintf('2026-11-%02d', $d), 'status' => 'present', 'closed' => true]);
    }
});

function lwLoad(array $inputs = []): PayInput
{
    $e = test()->e;

    return PayInputLoader::load('2026-11', new Collection([$e]), $inputs === [] ? [] : [$e->id => $inputs])[$e->id];
}

it('leaves a run unchanged without the options: the month window and the installment due', function () {
    $loan = loanFor($this->e, 80000, 5000, '2026-06');
    loanFor($this->e, 9000, 3000, '2026-12'); // starts later: not due in November

    $in = lwLoad();

    expect($in->windowFrom)->toBe('2026-11-01')->and($in->windowTo)->toBe('2026-11-30')
        ->and($in->segments[0]->to)->toBe('2026-11-30')
        ->and(array_map(fn ($l) => $l->toArray(), $in->loans))->toBe([
            ['loan_id' => $loan->id, 'type' => 'loan', 'remaining' => 8_000_000, 'installment' => 500_000, 'skipped' => false],
        ])
        // A full month of profile P: 90,000 - PF 3,000 - tax 4,200 - installment 5,000.
        ->and(PayCalculator::calculate($in)->net)->toBe(7_780_000);
});

it('window_to ends the pay window on that day', function () {
    $in = lwLoad(['window_to' => '2026-11-18']);

    expect($in->windowTo)->toBe('2026-11-18')
        ->and($in->segments)->toHaveCount(1)
        ->and($in->segments[0]->to)->toBe('2026-11-18')
        ->and($in->segments[0]->days)->toBe(18)
        ->and(PayCalculator::calculate($in)->basic)->toBe(3_600_000);
});

it('refuses a window_to outside the month', function () {
    lwLoad(['window_to' => '2026-12-01']);
})->throws(InvalidArgumentException::class);

it('loans_in_full makes every active loan due in full, whatever its start, and ignores a skip', function () {
    $old = loanFor($this->e, 80000, 5000, '2026-06');
    $later = loanFor($this->e, 9000, 3000, '2026-12');
    LoanSkip::query()->create(['loan_id' => $old->id, 'month' => '2026-11', 'reason' => 'Festival']);

    $in = lwLoad(['loans_in_full' => true]);

    expect(array_map(fn ($l) => $l->toArray(), $in->loans))->toBe([
        ['loan_id' => $old->id, 'type' => 'loan', 'remaining' => 8_000_000, 'installment' => 8_000_000, 'skipped' => false],
        ['loan_id' => $later->id, 'type' => 'loan', 'remaining' => 900_000, 'installment' => 900_000, 'skipped' => false],
    ]);

    // Without the option the skip still holds and the later loan is not due.
    expect(array_map(fn ($l) => $l->toArray(), lwLoad()->loans))->toBe([
        ['loan_id' => $old->id, 'type' => 'loan', 'remaining' => 8_000_000, 'installment' => 500_000, 'skipped' => true],
    ]);
});

it('applies the options to the named person only', function () {
    $other = Employee::factory()->create(['joining_date' => '2025-01-01']);
    salaryProfile($other);
    loanFor($other, 9000, 3000, '2026-06');

    $inputs = PayInputLoader::load('2026-11', new Collection([$this->e, $other]), [$this->e->id => ['window_to' => '2026-11-10', 'loans_in_full' => true]]);

    expect($inputs[$this->e->id]->windowTo)->toBe('2026-11-10')
        ->and($inputs[$other->id]->windowTo)->toBe('2026-11-30')
        ->and($inputs[$other->id]->loans[0]->installment)->toBe(300_000);
});
