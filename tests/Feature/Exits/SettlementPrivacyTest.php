<?php

use App\Actions\Exits\ApproveSettlement;
use App\Actions\Exits\MarkSettlementPaid;
use App\Actions\Exits\OverrideSettlementTax;
use App\Actions\Exits\PrepareSettlement;
use App\Actions\Exits\RecalculateSettlement;
use App\Actions\Exits\RemoveSettlementLine;
use App\Actions\Exits\SaveSettlementLine;
use App\Actions\Exits\TickClearanceItem;
use App\Models\Clearance;
use App\Support\Exits\SettlementAccess;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

require_once __DIR__.'/Support/settlement-helpers.php';

/* Managers and Employees reach no settlement action and see no settlement figure (design 5.9). */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01']);
    $this->manager = userWithRole('manager');
    $managerEmployee = employeeFor($this->manager, ['joining_date' => '2024-01-01']);
    $this->employee = userWithRole('employee');
    $this->e = employeeFor($this->employee, ['joining_date' => '2025-01-01', 'manager_id' => $managerEmployee->id]);
    salaryProfile($this->e);
    $this->exit = approvedExit($this->e, '2026-11-18');
    stDays($this->e, '2026-11-01', '2026-11-18');
    $this->settlement = settlementRow($this->exit);
    $this->line = stLine($this->settlement, 'bonus', 100);
    $this->approved = settlementRow(approvedExit(stPerson(), '2026-11-20'), ['status' => 'approved', 'approved_at' => now()]);
});

it('refuses every settlement and clearance action to a Manager and to the Employee themselves', function (string $who) {
    $user = $this->{$who};
    $item = Clearance::query()->where('resignation_id', $this->exit->id)->where('kind', 'access')->firstOrFail();

    $actions = [
        fn () => app(PrepareSettlement::class)->handle($user, $this->exit),
        fn () => app(RecalculateSettlement::class)->handle($user, $this->settlement),
        fn () => app(SaveSettlementLine::class)->handle($user, $this->settlement, ['kind' => 'bonus', 'label' => 'Bonus', 'amount' => 100]),
        fn () => app(RemoveSettlementLine::class)->handle($user, $this->line),
        fn () => app(OverrideSettlementTax::class)->handle($user, $this->settlement, 0, 'Reason'),
        fn () => app(ApproveSettlement::class)->handle($user, $this->settlement),
        fn () => app(MarkSettlementPaid::class)->handle($user, $this->approved, '2026-12-05'),
        fn () => app(TickClearanceItem::class)->handle($user, $item, true),
    ];

    foreach ($actions as $action) {
        expect($action)->toThrow(AuthorizationException::class);
    }

    expect(SettlementAccess::mayView($user, $this->settlement))->toBeFalse()
        ->and(SettlementAccess::mayView($user, $this->approved))->toBeFalse()
        ->and(SettlementAccess::mayEdit($user, $this->settlement))->toBeFalse()
        ->and(SettlementAccess::mayApprove($user, $this->settlement))->toBeFalse()
        ->and($this->settlement->refresh()->lines()->count())->toBe(1)
        ->and($this->settlement->status)->toBe('draft');
})->with(['manager', 'employee']);

it('lets HR view and edit but not approve; Admin approve', function () {
    $hr = userWithRole('hr');
    $admin = userWithRole('admin');

    expect(SettlementAccess::mayView($hr, $this->settlement))->toBeTrue()
        ->and(SettlementAccess::mayEdit($hr, $this->settlement))->toBeTrue()
        ->and(SettlementAccess::mayApprove($hr, $this->settlement))->toBeFalse()
        ->and(SettlementAccess::mayApprove($admin, $this->settlement))->toBeTrue();
});
