<?php

use App\Actions\Exits\ApproveSettlement;
use App\Actions\Exits\ChangeExitLastDay;
use App\Actions\Exits\DecideExit;
use App\Actions\Exits\PrepareSettlement;
use App\Actions\Exits\RecordExit;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Resignation;
use App\Support\Exits\ExitStates;
use App\Support\Exits\SettlementChecks;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/settlement-helpers.php';

/*
 * Nobody is paid twice for a month (money review C1): a last working day whose month an approved or paid run already
 * paid the person (a line that is not excluded) is refused when recorded, approved or moved there, and a settlement of
 * such a month cannot be prepared or approved. Plus the go-live gate (I1): a first-ever run in the settlement month does
 * not ask for the previous month's run. "Now" is 5 Dec 2026; attendance started 1 Nov 2026.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01']);
    $this->hr = userWithRole('hr');
    $this->admin = userWithRole('admin');
});

/** November 2026 approved (or $status), with a line for $e (excluded or not). */
function dpPaidNovember(Employee $e, bool $excluded = false, string $status = 'approved'): PayrollRun
{
    $run = payrollRun('2026-11', $status);
    PayrollItem::factory()->create(['payroll_run_id' => $run->id, 'employee_id' => $e->id, 'excluded' => $excluded]);

    return $run;
}

function dpMessage(Employee $e): string
{
    return "{$e->full_name} was already paid in the November 2026 payroll. Move the last working day to a later month first.";
}

function dpRefused(callable $run, string $message, string $key): void
{
    try {
        $run();
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key)
            ->and(collect($e->errors())->flatten()->all())->toContain($message);

        return;
    }

    throw new RuntimeException("Expected a refusal: {$message}");
}

it('refuses to record and approve an exit on the last day of a month the approved run paid', function () {
    $e = stPerson();
    dpPaidNovember($e);

    dpRefused(fn () => app(RecordExit::class)->handle($this->hr, $e, 'resignation', '2026-11-30', 'Moving abroad.', '2026-11-20', true),
        dpMessage($e), 'last_working_day');

    expect(Resignation::query()->where('employee_id', $e->id)->exists())->toBeFalse()
        ->and($e->refresh()->last_working_day)->toBeNull();
});

it('refuses to record the exit without approving it too, and for a paid run as well', function () {
    $e = stPerson();
    dpPaidNovember($e, status: 'paid');

    dpRefused(fn () => app(RecordExit::class)->handle($this->hr, $e, 'resignation', '2026-11-30', 'Moving abroad.', '2026-11-20', false),
        dpMessage($e), 'last_working_day');
});

it('refuses the final approval of an exit whose day lies in a month the run already paid', function () {
    $e = stPerson();
    $exit = resignationRow($e, ['status' => ExitStates::MANAGER_APPROVED, 'last_working_day' => '2026-11-30', 'submitted_on' => '2026-11-10']);
    dpPaidNovember($e);

    dpRefused(fn () => app(DecideExit::class)->approve($this->admin, $exit), dpMessage($e), 'last_working_day');

    expect($exit->refresh()->status)->toBe(ExitStates::MANAGER_APPROVED)
        ->and($e->refresh()->last_working_day)->toBeNull();
});

it('refuses to move an approved exit\'s day back into a month the run already paid; the draft stays', function () {
    $e = stPerson();
    $exit = approvedExit($e, '2026-12-10');
    $s = settlementRow($exit);
    dpPaidNovember($e);

    dpRefused(fn () => app(ChangeExitLastDay::class)->handle($this->hr, $exit, '2026-11-30'), dpMessage($e), 'last_working_day');

    expect($exit->refresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-10')
        ->and($s->refresh()->month)->toBe('2026-12')
        ->and($s->stale)->toBeFalse();
});

it('blocks the settlement of a month the run already paid: prepare refused, approval refused', function () {
    $e = stPerson();
    $exit = approvedExit($e, '2026-11-30');
    stDays($e, '2026-11-01', '2026-11-30');
    stClear($exit);
    dpPaidNovember($e);

    dpRefused(fn () => app(PrepareSettlement::class)->handle($this->hr, $exit), dpMessage($e), 'settlement');
    expect(FinalSettlement::query()->where('resignation_id', $exit->id)->exists())->toBeFalse();

    // A draft from before the run was approved: approval re-checks under its locks.
    $s = settlementRow($exit);
    expect(SettlementChecks::blocking($s))->toBe(['paid_in_run']);

    dpRefused(fn () => app(ApproveSettlement::class)->handle($this->admin, $s), dpMessage($e), 'settlement');
    expect($s->refresh()->status)->toBe('draft');
});

it('allows it when the person\'s line in that run is excluded (it paid nothing)', function () {
    $e = stPerson();
    $exit = approvedExit($e, '2026-11-30');
    stDays($e, '2026-11-01', '2026-11-30');
    dpPaidNovember($e, excluded: true);

    $s = app(PrepareSettlement::class)->handle($this->hr, $exit);

    expect($s->month)->toBe('2026-11')
        ->and(SettlementChecks::blocking($s))->not->toContain('paid_in_run');
});

it('allows it when the month\'s run is still a draft', function () {
    $e = stPerson();
    $run = payrollRun('2026-11');
    PayrollItem::factory()->create(['payroll_run_id' => $run->id, 'employee_id' => $e->id]);

    $exit = app(RecordExit::class)->handle($this->hr, $e, 'resignation', '2026-11-30', 'Moving abroad.', '2026-11-20', true);

    expect($exit->status)->toBe(ExitStates::APPROVED);
});

it('does not ask for the previous month at go-live, when the first run ever is the settlement month', function () {
    $e = stPerson();
    $exit = approvedExit($e, '2026-11-18');
    stDays($e, '2026-11-01', '2026-11-18');
    $s = app(PrepareSettlement::class)->handle($this->hr, $exit);
    stClear($exit);
    payrollRun('2026-11', 'approved'); // go-live; the leaver is settled separately (no line)

    expect(SettlementChecks::blocking($s->refresh()))->toBe([])
        ->and(app(ApproveSettlement::class)->handle($this->admin, $s)->status)->toBe('approved');
});

it('still asks for the previous month once payroll was live before it', function () {
    $e = stPerson();
    $exit = approvedExit($e, '2026-11-18');
    stDays($e, '2026-11-01', '2026-11-18');
    $s = app(PrepareSettlement::class)->handle($this->hr, $exit);
    stClear($exit);
    payrollRun('2026-09', 'approved');
    payrollRun('2026-11', 'approved');

    expect(SettlementChecks::blocking($s->refresh()))->toBe(['previous_month_unpaid']);
});
