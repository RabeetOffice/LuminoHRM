<?php

use App\Models\Employee;
use App\Support\ManagerLoop;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('says no cycle when there is no manager', function () {
    $a = Employee::factory()->create();

    expect(ManagerLoop::wouldCreateCycle($a->id, null))->toBeFalse();
});

it('refuses an employee as their own manager', function () {
    $a = Employee::factory()->create();

    expect(ManagerLoop::wouldCreateCycle($a->id, $a->id))->toBeTrue();
});

it('detects a two-step loop (A under B, then B under A)', function () {
    $a = Employee::factory()->create();
    $b = Employee::factory()->create(['manager_id' => $a->id]);

    expect(ManagerLoop::wouldCreateCycle($a->id, $b->id))->toBeTrue()
        ->and(ManagerLoop::wouldCreateCycle($b->id, $a->id))->toBeFalse();
});

it('detects a three-step loop', function () {
    $a = Employee::factory()->create();
    $b = Employee::factory()->create(['manager_id' => $a->id]);
    $c = Employee::factory()->create(['manager_id' => $b->id]);

    expect(ManagerLoop::wouldCreateCycle($a->id, $c->id))->toBeTrue()
        ->and(ManagerLoop::wouldCreateCycle($a->id, $b->id))->toBeTrue();
});

it('allows a manager from another branch', function () {
    $a = Employee::factory()->create();
    $b = Employee::factory()->create(['manager_id' => $a->id]);
    $other = Employee::factory()->create();

    expect(ManagerLoop::wouldCreateCycle($b->id, $other->id))->toBeFalse();
});

it('does not loop forever on corrupt data that already contains a cycle', function () {
    $x = Employee::factory()->create();
    $y = Employee::factory()->create(['manager_id' => $x->id]);
    $x->forceFill(['manager_id' => $y->id])->save(); // x <-> y, corrupt
    $fresh = Employee::factory()->create();

    // The new person is not part of the loop, so they add no new cycle; the call just has to return.
    expect(ManagerLoop::wouldCreateCycle($fresh->id, $x->id))->toBeFalse();
});

it('stops at a hard depth cap and treats an over-deep chain as unsafe', function () {
    $top = Employee::factory()->create();
    $parent = $top;
    for ($i = 0; $i < 6; $i++) {
        $parent = Employee::factory()->create(['manager_id' => $parent->id]);
    }
    $fresh = Employee::factory()->create();

    expect(ManagerLoop::wouldCreateCycle($fresh->id, $parent->id, maxDepth: 3))->toBeTrue()
        ->and(ManagerLoop::wouldCreateCycle($fresh->id, $parent->id))->toBeFalse();
});

it('lists everyone below an employee, however deep, and survives a corrupt loop', function () {
    $a = Employee::factory()->create();
    $b = Employee::factory()->create(['manager_id' => $a->id]);
    $c = Employee::factory()->create(['manager_id' => $b->id]);
    $unrelated = Employee::factory()->create();
    $a->forceFill(['manager_id' => $c->id])->save(); // corrupt loop a -> c -> b -> a

    $below = ManagerLoop::descendantIds($a->id);

    expect($below)->toContain($b->id)->toContain($c->id)->not->toContain($unrelated->id);
});
