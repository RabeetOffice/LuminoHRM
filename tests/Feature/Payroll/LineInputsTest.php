<?php

use App\Actions\Payroll\DeleteDraftRun;
use App\Actions\Payroll\GeneratePayrollRun;
use App\Actions\Payroll\PayrollLineInputs;
use App\Models\LoanSkip;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/Support/run-helpers.php';

/*
 * HR's inputs on a draft line. "Now" 2026-12-05 in Karachi; no attendance rows, so profile P pays a full November:
 * gross 90,000, provident fund 3,000, tax 5% of 84,000 = 4,200, net 82,800.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01']);
    $this->hr = userWithRole('hr');
    $this->admin = userWithRole('admin');
    $this->e = prEmployee();
    salaryProfile($this->e);
    $this->run = prGenerate($this->hr);
    $this->item = prItem($this->run, $this->e);
    $this->inputs = app(PayrollLineInputs::class);
});

function liFresh(): array
{
    $run = test()->run->fresh();

    return [$run, prItem($run, test()->e)];
}

function liError(Closure $fn): array
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('No validation error.');
}

it('raises gross and tax with a taxable addition and keeps the run totals current', function () {
    $adjustment = $this->inputs->addAdjustment($this->hr, $this->item, '  Eid bonus ', 'addition', 10000, true);
    [$run, $item] = liFresh();

    expect($adjustment->label)->toBe('Eid bonus')
        ->and($adjustment->amount)->toBe(prRs(10000))
        ->and($adjustment->is_taxable)->toBeTrue()
        ->and($item->additions)->toBe(prRs(10000))
        ->and($item->gross)->toBe(prRs(100000))
        ->and($item->tax)->toBe(prRs(4700))
        ->and($item->net)->toBe(prRs(92300))
        ->and($run->gross)->toBe(prRs(100000))
        ->and($run->tax)->toBe(prRs(4700))
        ->and($run->net)->toBe(prRs(92300));

    $audit = Activity::where('description', 'Payroll adjustment added')->sole();
    expect($audit->subject_id)->toBe($this->run->id)
        ->and($audit->properties['employee'])->toBe($this->e->id)
        ->and($audit->properties['amount_formatted'])->toBe('Rs 10,000');
});

it('adds a non-taxable addition to gross only', function () {
    $this->inputs->addAdjustment($this->hr, $this->item, 'Travel refund', 'addition', 2000, false);
    [, $item] = liFresh();

    expect($item->gross)->toBe(prRs(92000))->and($item->tax)->toBe(prRs(4200))->and($item->net)->toBe(prRs(84800));
});

it('lowers net only with a deduction and never makes it taxable', function () {
    $adjustment = $this->inputs->addAdjustment($this->hr, $this->item, 'Canteen', 'deduction', 2000, true);
    [$run, $item] = liFresh();

    expect($adjustment->is_taxable)->toBeFalse()
        ->and($item->gross)->toBe(prRs(90000))
        ->and($item->tax)->toBe(prRs(4200))
        ->and($item->other_deductions)->toBe(prRs(2000))
        ->and($item->net)->toBe(prRs(80800))
        ->and($run->deductions)->toBe(prRs(5000));
});

it('removes an adjustment and recalculates', function () {
    $adjustment = $this->inputs->addAdjustment($this->hr, $this->item, 'Bonus', 'addition', 10000, true);
    $this->inputs->removeAdjustment($this->hr, $adjustment);
    [$run, $item] = liFresh();

    expect(PayrollAdjustment::count())->toBe(0)
        ->and($item->net)->toBe(prRs(82800))
        ->and($run->net)->toBe(prRs(82800))
        ->and(Activity::where('description', 'Payroll adjustment removed')->count())->toBe(1);

    expect(liError(fn () => $this->inputs->removeAdjustment($this->hr, $adjustment)))->toHaveKey('adjustment');
});

it('validates an adjustment', function () {
    expect(liError(fn () => $this->inputs->addAdjustment($this->hr, $this->item, 'X', 'addition', 0, true)))
        ->toHaveKeys(['label', 'amount'])
        ->and(liError(fn () => $this->inputs->addAdjustment($this->hr, $this->item, 'Bonus', 'gift', 100, true)))->toHaveKey('type')
        ->and(liError(fn () => $this->inputs->addAdjustment($this->hr, $this->item, 'Bonus', 'addition', 100_000_000, true)))->toHaveKey('amount')
        ->and(liError(fn () => $this->inputs->addAdjustment($this->hr, $this->item, str_repeat('a', 101), 'addition', 5, true)))->toHaveKey('label')
        ->and(PayrollAdjustment::count())->toBe(0);
});

it('overrides the tax and clears the override', function () {
    $this->inputs->overrideTax($this->hr, $this->item, 1234, 'Exemption certificate on file.');
    [$run, $item] = liFresh();

    expect($item->tax_overridden)->toBeTrue()
        ->and($item->tax_override)->toBe(prRs(1234))
        ->and($item->tax)->toBe(prRs(1234))
        ->and($item->breakdown['tax']['overridden'])->toBeTrue()
        ->and($item->breakdown['tax']['override_reason'])->toBe('Exemption certificate on file.')
        ->and($item->net)->toBe(prRs(90000 - 3000 - 1234))
        ->and($run->tax)->toBe(prRs(1234));

    $this->inputs->overrideTax($this->hr, $item, null, null);
    [$run, $item] = liFresh();

    expect($item->tax_overridden)->toBeFalse()
        ->and($item->tax_override)->toBeNull()
        ->and($item->tax)->toBe(prRs(4200))
        ->and($run->tax)->toBe(prRs(4200));

    $overridden = Activity::where('description', 'Payroll tax overridden')->sole();
    expect($overridden->properties['old']['tax'])->toBe(prRs(4200))
        ->and($overridden->properties['attributes']['tax'])->toBe(prRs(1234))
        ->and(Activity::where('description', 'Payroll tax override cleared')->count())->toBe(1);

    // Clearing again changes nothing and writes nothing.
    $this->inputs->overrideTax($this->hr, $item, null, null);
    expect(Activity::where('description', 'Payroll tax override cleared')->count())->toBe(1);
});

it('validates the tax override before the calculator sees it', function () {
    expect(liError(fn () => $this->inputs->overrideTax($this->hr, $this->item, -1, 'A good reason.')))->toHaveKey('amount')
        ->and(liError(fn () => $this->inputs->overrideTax($this->hr, $this->item, 100, 'No')))->toHaveKey('reason')
        ->and(liError(fn () => $this->inputs->overrideTax($this->hr, $this->item, 100, null)))->toHaveKey('reason')
        ->and(liFresh()[1]->tax_overridden)->toBeFalse();

    $this->inputs->overrideTax($this->hr, $this->item, 0, 'Below the threshold.');
    expect(liFresh()[1]->tax)->toBe(0);
});

it('excludes a line (net 0, headcount down) and includes it again', function () {
    $other = prEmployee();
    salaryProfile($other);
    app(GeneratePayrollRun::class)->recalculate($this->hr, $this->run);
    expect($this->run->fresh()->headcount)->toBe(2);

    $this->inputs->exclude($this->hr, $this->item, 'Paid by the sister company.');
    [$run, $item] = liFresh();

    expect($item->excluded)->toBeTrue()
        ->and($item->excluded_reason)->toBe('Paid by the sister company.')
        ->and($item->net)->toBe(0)
        ->and($run->headcount)->toBe(1)
        ->and($run->net)->toBe(prRs(82800));

    $this->inputs->include($this->hr, $item);
    [$run, $item] = liFresh();

    expect($item->excluded)->toBeFalse()
        ->and($item->excluded_reason)->toBeNull()
        ->and($item->net)->toBe(prRs(82800))
        ->and($run->headcount)->toBe(2)
        ->and($run->net)->toBe(prRs(165600))
        ->and(Activity::where('description', 'Payroll line excluded')->count())->toBe(1)
        ->and(Activity::where('description', 'Payroll line included')->count())->toBe(1);

    expect(liError(fn () => $this->inputs->exclude($this->hr, $item, 'no')))->toHaveKey('reason');
});

it('refuses HR on their own line and on an Admin or HR person\'s line, and lets Admin edit them', function () {
    [$hr, $hrEmployee] = prUser('hr');
    [$admin, $adminEmployee] = prUser('admin');
    [, $otherHrEmployee] = prUser('hr');
    foreach ([$hrEmployee, $adminEmployee, $otherHrEmployee] as $e) {
        salaryProfile($e);
    }
    app(GeneratePayrollRun::class)->recalculate($this->hr, $this->run);

    foreach ([$hrEmployee, $adminEmployee, $otherHrEmployee] as $e) {
        $line = prItem($this->run, $e);
        expect(fn () => $this->inputs->addAdjustment($hr, $line, 'Bonus', 'addition', 100, true))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->inputs->overrideTax($hr, $line, 0, 'Exemption.'))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->inputs->exclude($hr, $line, 'Paid elsewhere.'))->toThrow(AuthorizationException::class);
    }
    expect(PayrollAdjustment::count())->toBe(0);

    $this->inputs->addAdjustment($admin, prItem($this->run, $otherHrEmployee), 'Bonus', 'addition', 100, true);
    $this->inputs->addAdjustment($admin, prItem($this->run, $adminEmployee), 'Bonus', 'addition', 100, true);
    $this->inputs->addAdjustment($hr, prItem($this->run, $this->e), 'Bonus', 'addition', 100, true);
    expect(PayrollAdjustment::count())->toBe(3);
});

it('refuses every change on an approved run', function () {
    $adjustment = $this->inputs->addAdjustment($this->hr, $this->item, 'Bonus', 'addition', 100, true);
    $this->run->update(['status' => 'approved', 'approved_at' => now()]);
    $item = $this->item->fresh();
    $before = PayrollItem::query()->get()->toArray();
    $message = ['run' => ['This payroll is approved and cannot change.']];

    expect(liError(fn () => $this->inputs->addAdjustment($this->hr, $item, 'More', 'addition', 100, true)))->toBe($message)
        ->and(liError(fn () => $this->inputs->removeAdjustment($this->hr, $adjustment)))->toBe($message)
        ->and(liError(fn () => $this->inputs->overrideTax($this->hr, $item, 0, 'Exemption.')))->toBe($message)
        ->and(liError(fn () => $this->inputs->overrideTax($this->hr, $item, null, null)))->toBe($message)
        ->and(liError(fn () => $this->inputs->exclude($this->hr, $item, 'Paid elsewhere.')))->toBe($message)
        ->and(liError(fn () => $this->inputs->include($this->hr, $item)))->toBe($message)
        ->and(liError(fn () => app(GeneratePayrollRun::class)->recalculate($this->hr, $this->run)))->toBe($message)
        ->and(liError(fn () => app(GeneratePayrollRun::class)->recalculate($this->hr, $this->run, $this->e->id)))->toBe($message)
        ->and(liError(fn () => app(DeleteDraftRun::class)->handle($this->hr, $this->run)))->toHaveKey('run')
        ->and(PayrollAdjustment::count())->toBe(1)
        ->and(PayrollItem::query()->get()->toArray())->toBe($before)
        ->and(PayrollRun::query()->sole()->status)->toBe('approved');
});

it('picks up a loan skip on the line\'s recalculation', function () {
    $loan = loanFor($this->e, 10000, 2000, '2026-11');
    app(GeneratePayrollRun::class)->recalculate($this->hr, $this->run, $this->e->id);
    expect(liFresh()[1]->loan_deduction)->toBe(prRs(2000));

    LoanSkip::query()->create(['loan_id' => $loan->id, 'month' => '2026-11', 'reason' => 'Family wedding.']);
    app(GeneratePayrollRun::class)->recalculate($this->hr, $this->run, $this->e->id);
    [$run, $item] = liFresh();

    expect($item->loan_deduction)->toBe(0)->and($run->net)->toBe(prRs(82800));
});
