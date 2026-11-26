<?php

use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;

/*
 * The model-level safety nets behind the payroll actions: an approved or paid run keeps its figures and summary (only the
 * paid stamps may be written), and no adjustment is created, changed or deleted on a line of such a run.
 */

it('lets a draft run take new figures and a summary', function () {
    $run = payrollRun('2026-11');

    $run->forceFill(['headcount' => 3, 'gross' => 900, 'net' => 800, 'summary' => ['checks' => []]])->save();

    expect($run->fresh()->net)->toBe(800);
});

it('moves draft to approved with its summary and approval columns, then to paid with the paid stamps', function () {
    $run = payrollRun('2026-11');
    $admin = userWithRole('admin');

    $run->forceFill(['summary' => ['company' => ['name' => 'Lumino']], 'status' => 'approved', 'approved_by' => $admin->id, 'approved_at' => now()])->save();
    expect($run->fresh()->status)->toBe('approved');

    $run->forceFill(['status' => 'paid', 'paid_by' => $admin->id, 'paid_at' => now(), 'paid_on' => '2026-12-02'])->save();
    expect($run->fresh()->status)->toBe('paid')->and(substr((string) $run->fresh()->paid_on, 0, 10))->toBe('2026-12-02');
});

it('refuses to save changed figures or summary on an approved or paid run', function (string $status, array $change) {
    $run = payrollRun('2026-11', $status);
    // Written around the guard on purpose: the figures the run was approved with.
    PayrollRun::query()->whereKey($run->id)->update(['headcount' => 2, 'gross' => 1000, 'deductions' => 100, 'tax' => 50, 'net' => 850]);
    $run = $run->fresh();

    expect(fn () => $run->forceFill($change)->save())->toThrow(LogicException::class);

    $stored = PayrollRun::query()->find($run->id);
    expect([$stored->headcount, $stored->gross, $stored->net, $stored->summary])->toBe([2, 1000, 850, null]);
})->with(['approved', 'paid'])->with([
    'headcount' => [['headcount' => 3]],
    'gross' => [['gross' => 1]],
    'deductions' => [['deductions' => 1]],
    'tax' => [['tax' => 1]],
    'net' => [['net' => 1]],
    'summary' => [['summary' => ['company' => ['name' => 'Someone else']]]],
    'month' => [['month' => '2026-10']],
    'approval' => [['approved_by' => 1]],
]);

it('still lets an approved run take its paid stamps', function () {
    $run = payrollRun('2026-11', 'approved');

    $run->forceFill(['status' => 'paid', 'paid_by' => userWithRole('hr')->id, 'paid_at' => now(), 'paid_on' => '2026-12-02'])->save();

    expect($run->fresh()->status)->toBe('paid');
});

it('lets a paid run be saved again with nothing changed', function () {
    $run = payrollRun('2026-11', 'paid');

    $run->touch();

    expect($run->fresh()->status)->toBe('paid');
});

it('takes adjustments on a draft run, created, changed and deleted', function () {
    $item = PayrollItem::factory()->create(['payroll_run_id' => payrollRun('2026-11')->id]);

    $adjustment = PayrollAdjustment::factory()->create(['payroll_item_id' => $item->id]);
    $adjustment->update(['amount' => 100]);
    $adjustment->delete();

    expect(PayrollAdjustment::count())->toBe(0);
});

it('refuses to create, change or delete an adjustment on a line of an approved or paid run', function (string $status) {
    $draft = payrollRun('2026-10');
    $run = payrollRun('2026-11', $status);
    $item = PayrollItem::factory()->create(['payroll_run_id' => $run->id]);
    $draftItem = PayrollItem::factory()->create(['payroll_run_id' => $draft->id]);

    expect(fn () => PayrollAdjustment::factory()->create(['payroll_item_id' => $item->id]))->toThrow(LogicException::class);

    // One written around the guard, as if it had been there before the approval.
    $existing = PayrollAdjustment::factory()->create(['payroll_item_id' => $draftItem->id]);
    PayrollAdjustment::query()->whereKey($existing->id)->update(['payroll_item_id' => $item->id]);
    $existing = $existing->fresh();

    expect(fn () => $existing->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $existing->update(['label' => 'Renamed']))->toThrow(LogicException::class)
        ->and(fn () => $existing->delete())->toThrow(LogicException::class)
        ->and(PayrollAdjustment::query()->sole()->label)->toBe('Bonus');

    // Moving one onto a locked run's line is refused too.
    $mine = PayrollAdjustment::factory()->create(['payroll_item_id' => $draftItem->id]);
    expect(fn () => $mine->update(['payroll_item_id' => $item->id]))->toThrow(LogicException::class);
})->with(['approved', 'paid']);
