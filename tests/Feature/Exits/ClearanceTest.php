<?php

use App\Actions\Assets\AssignAsset;
use App\Actions\Exits\TickClearanceItem;
use App\Models\Clearance;
use App\Support\Exits\ClearanceStatus;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/Support/settlement-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01']);
    $this->hr = userWithRole('hr');
    $this->e = stPerson();
    $this->exit = approvedExit($this->e, '2026-11-18', ['clearance_items' => ['Handover notes']]);
});

function clItem(string $kind, ?string $label = null): Clearance
{
    return Clearance::query()->where('resignation_id', test()->exit->id)->where('kind', $kind)
        ->when($label !== null, fn ($q) => $q->where('label', $label))->firstOrFail();
}

it('works the assets item out live: ticked when nothing is held, else the held codes', function () {
    $status = ClearanceStatus::for($this->exit);
    $assets = collect($status['items'])->firstWhere('kind', 'assets');

    expect($assets)->toMatchArray(['cleared' => true, 'automatic' => true, 'held' => [], 'cleared_by' => null])
        ->and($status['missing'])->toBe(['System access removed', 'Dues cleared', 'Handover notes'])
        ->and($status['complete'])->toBeFalse();

    $laptop = assignedAsset($this->e, ['asset_code' => 'LT-007']);
    $assets = collect(ClearanceStatus::for($this->exit)['items'])->firstWhere('kind', 'assets');

    expect($assets['cleared'])->toBeFalse()->and($assets['held'])->toBe([$laptop->asset_code]);
});

it('ticks and unticks a hand item with who and when, audited', function () {
    $item = clItem('access');

    app(TickClearanceItem::class)->handle($this->hr, $item, true);
    $item->refresh();
    expect($item->cleared_by)->toBe($this->hr->id)->and($item->cleared_at)->not->toBeNull()
        ->and(Activity::query()->where('description', 'Clearance item ticked')->count())->toBe(1);

    app(TickClearanceItem::class)->handle($this->hr, $item, false);
    expect($item->refresh()->cleared_at)->toBeNull()->and($item->cleared_by)->toBeNull()
        ->and(Activity::query()->where('description', 'Clearance item unticked')->count())->toBe(1);
});

it('is complete once every hand item is ticked and nothing is held', function () {
    stClear($this->exit);

    expect(ClearanceStatus::for($this->exit))->toMatchArray(['complete' => true, 'missing' => []]);
});

it('never ticks the assets item by hand', function () {
    expect(fn () => app(TickClearanceItem::class)->handle($this->hr, clItem('assets'), true))
        ->toThrow(ValidationException::class, 'Assets returned is ticked automatically when nothing is held.');
});

it('is frozen once the settlement is approved, the stored assets tick shown as written', function () {
    settlementRow($this->exit, ['status' => 'approved', 'approved_at' => now()]);
    clItem('assets')->forceFill(['cleared_at' => now()])->save();

    expect(fn () => app(TickClearanceItem::class)->handle($this->hr, clItem('dues'), true))
        ->toThrow(ValidationException::class, 'Clearance is frozen once the settlement is approved.');

    // An asset handed out later would not change the stored tick.
    $assets = collect(ClearanceStatus::for($this->exit)['items'])->firstWhere('kind', 'assets');
    expect($assets['cleared'])->toBeTrue()->and($assets)->not->toHaveKey('held');
});

it('refuses an asset assignment once the final settlement is approved', function () {
    settlementRow($this->exit, ['status' => 'approved', 'approved_at' => now()]);
    $asset = assetRow();

    expect(fn () => app(AssignAsset::class)->handle($this->hr, $asset, $this->e, '2026-12-05', null))
        ->toThrow(ValidationException::class, "This person's final settlement is approved, so no asset can be assigned.");
});

it('still assigns while the settlement is a draft', function () {
    settlementRow($this->exit);

    expect(app(AssignAsset::class)->handle($this->hr, assetRow(), $this->e, '2026-12-05', null)->employee_id)->toBe($this->e->id);
});

it('does not let HR tick their own exit, or a manager tick anyone', function () {
    $own = employeeFor($this->hr, ['joining_date' => '2025-01-01']);
    $ownExit = approvedExit($own, '2026-11-18');
    $item = Clearance::query()->where('resignation_id', $ownExit->id)->where('kind', 'access')->firstOrFail();

    expect(fn () => app(TickClearanceItem::class)->handle($this->hr, $item, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(TickClearanceItem::class)->handle(userWithRole('manager'), clItem('access'), true))->toThrow(AuthorizationException::class);
});
