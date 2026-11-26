<?php

use App\Models\Clearance;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\FinalSettlementLine;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\Resignation;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitNotice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The Phase 6c tables, the open-exit guard, the model rules and the data migration that moves the experience letter
| template from the "letters" settings group to "exit".
*/

it('creates the four exit tables and the loan repayment link', function () {
    $tables = [
        'resignations' => [
            'id', 'employee_id', 'type', 'submitted_on', 'last_working_day', 'reason', 'notice_period_days', 'status',
            'open_employee_id', 'recorded_by', 'manager_skipped', 'manager_decided_by', 'manager_decided_at',
            'final_decided_by', 'final_decided_at', 'own_decision', 'decision_note', 'withdrawn_at', 'cancelled_by',
            'cancelled_at', 'cancel_reason', 'status_applied_at', 'status_error', 'letter_text', 'letter_issued_by',
            'letter_issued_at', 'created_at', 'updated_at',
        ],
        'clearances' => ['id', 'resignation_id', 'kind', 'label', 'sort_order', 'cleared_by', 'cleared_at', 'created_at', 'updated_at'],
        'final_settlements' => [
            'id', 'resignation_id', 'employee_id', 'month', 'status', 'basic', 'allowances', 'overtime', 'additions', 'gross',
            'attendance_deduction', 'fixed_deductions', 'other_deductions', 'loan_deduction', 'taxable_pay', 'tax', 'net',
            'encashment_days', 'encashment_amount', 'loan_shortfall', 'tax_overridden', 'tax_override', 'tax_override_reason',
            'breakdown', 'stale', 'calculated_at', 'prepared_by', 'approved_by', 'approved_at', 'own_settlement', 'paid_on',
            'paid_by', 'summary', 'statement_path', 'created_at', 'updated_at',
        ],
        'final_settlement_lines' => ['id', 'final_settlement_id', 'kind', 'type', 'label', 'amount', 'is_taxable', 'created_by', 'created_at', 'updated_at'],
    ];

    foreach ($tables as $table => $columns) {
        expect(Schema::hasTable($table))->toBeTrue("table {$table}");
        expect(Schema::hasColumns($table, $columns))->toBeTrue("columns of {$table}");
    }

    expect(Schema::hasColumn('loan_repayments', 'final_settlement_id'))->toBeTrue();
    expect(Audit::AREAS)->toHaveKey('exit', 'Exit');
});

it('allows one open exit per person and any number of ended ones', function () {
    $employee = Employee::factory()->create();

    $first = resignationRow($employee);
    expect($first->open_employee_id)->toBe($employee->id);

    expect(fn () => resignationRow($employee))->toThrow(QueryException::class);

    // Ending it frees the person: the guard column is NULL once the status is not open.
    $first->status = 'rejected';
    $first->save();
    expect($first->fresh()->open_employee_id)->toBeNull();

    resignationRow($employee, ['status' => 'withdrawn']);
    $second = resignationRow($employee, ['status' => 'approved']);

    expect($second->open_employee_id)->toBe($employee->id)
        ->and(Resignation::query()->where('employee_id', $employee->id)->count())->toBe(3)
        ->and($employee->openResignation->is($second))->toBeTrue();
});

it('never changes or deletes an ended exit', function () {
    $rejected = resignationRow(Employee::factory()->create(), ['status' => 'rejected']);

    $rejected->reason = 'Changed my mind.';
    expect(fn () => $rejected->save())->toThrow(LogicException::class, 'This exit has ended and cannot change.');

    expect(fn () => $rejected->delete())->toThrow(LogicException::class);
});

it('keeps a clearance row once per sort order and a settlement once per exit', function () {
    $exit = approvedExit(Employee::factory()->create(), '2026-12-10');

    expect(fn () => Clearance::factory()->create(['resignation_id' => $exit->id, 'sort_order' => 0]))->toThrow(QueryException::class);

    settlementRow($exit);
    expect(fn () => settlementRow($exit))->toThrow(QueryException::class);
});

it('stores a signed net and unsigned money columns on a settlement', function () {
    $exit = approvedExit(Employee::factory()->create(), '2026-12-10');
    $settlement = settlementRow($exit, ['net' => -420_000, 'gross' => 5_400_000, 'encashment_days' => 13, 'encashment_amount' => 1_950_000]);

    expect($settlement->fresh()->net)->toBe(-420_000)
        ->and($settlement->fresh()->encashment_days)->toBe(13)
        ->and($settlement->breakdown['settlement']['owed_by_employee'])->toBe(0)
        ->and($settlement->isFrozen())->toBeFalse()
        ->and(settlementRow(approvedExit(Employee::factory()->create(), '2026-12-10'), ['status' => 'paid'])->isFrozen())->toBeTrue();
});

it('links a loan repayment to its settlement, once per loan', function () {
    $employee = Employee::factory()->create();
    $exit = approvedExit($employee, '2026-12-10');
    $settlement = settlementRow($exit);
    $loan = loanFor($employee, 100000, 10000, '2026-01');

    LoanRepayment::factory()->create(['loan_id' => $loan->id, 'final_settlement_id' => $settlement->id, 'month' => '2026-12']);

    expect(fn () => LoanRepayment::factory()->create(['loan_id' => $loan->id, 'final_settlement_id' => $settlement->id, 'month' => '2026-12']))
        ->toThrow(QueryException::class);

    // A manual or payroll repayment has no settlement: any number may coexist.
    LoanRepayment::factory()->count(2)->create(['loan_id' => $loan->id, 'final_settlement_id' => null]);
    expect(LoanRepayment::query()->where('loan_id', $loan->id)->count())->toBe(3)
        ->and($settlement->repayments()->count())->toBe(1)
        ->and(Loan::query()->whereKey($loan->id)->exists())->toBeTrue();
});

it('deletes a draft settlement with its lines and leaves the clearance rows', function () {
    $exit = approvedExit(Employee::factory()->create(), '2026-12-10');
    $settlement = settlementRow($exit);
    FinalSettlementLine::factory()->create(['final_settlement_id' => $settlement->id]);

    expect(FinalSettlementLine::query()->count())->toBe(1);

    FinalSettlementLine::query()->delete();
    $settlement->delete();

    expect(FinalSettlement::query()->count())->toBe(0)
        ->and(Clearance::query()->where('resignation_id', $exit->id)->count())->toBe(3);
});

it('works out the notice given and its label', function () {
    expect(ExitNotice::given('2026-11-10', '2026-12-09'))->toBe(30)
        ->and(ExitNotice::given('2026-11-25', '2026-11-25'))->toBe(1)
        ->and(ExitNotice::given('2026-11-25', '2026-11-20'))->toBe(0)
        ->and(ExitNotice::shortBy(30, 18))->toBe(12)
        ->and(ExitNotice::shortBy(30, 45))->toBe(0);

    $enough = resignationRow(Employee::factory()->create(), ['submitted_on' => '2026-11-10', 'last_working_day' => '2026-12-09', 'notice_period_days' => 30]);
    $short = resignationRow(Employee::factory()->create(), ['submitted_on' => '2026-11-10', 'last_working_day' => '2026-11-27', 'notice_period_days' => 30]);

    expect(ExitNotice::label($enough))->toBe('30 days notice')
        ->and(ExitNotice::label($short))->toBe('12 days short of notice (30 required)');
});

// ---- the letters -> exit settings data migration ---------------------------

function xsRunMove(string $direction = 'up'): void
{
    $migration = require database_path('migrations/2026_11_26_400100_move_letter_template_to_exit_settings.php');
    $migration->{$direction}();
}

function xsSetting(string $group, string $key): ?string
{
    $value = DB::table('settings')->where('group', $group)->where('key', $key)->value('value');

    return $value === null ? null : json_decode($value, true);
}

it('moves a saved experience template from letters to exit', function () {
    DB::table('settings')->insert([
        'group' => 'letters', 'key' => 'experience_template',
        'value' => json_encode('Custom text for {employee_name}.'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    xsRunMove();

    expect(xsSetting('exit', 'experience_template'))->toBe('Custom text for {employee_name}.')
        ->and(DB::table('settings')->where('group', 'letters')->count())->toBe(0)
        ->and(settings('exit.experience_template'))->toBe('Custom text for {employee_name}.');
});

it('is idempotent and keeps a value already saved under exit', function () {
    DB::table('settings')->insert([
        ['group' => 'letters', 'key' => 'experience_template', 'value' => json_encode('Old'), 'created_at' => now(), 'updated_at' => now()],
        ['group' => 'exit', 'key' => 'experience_template', 'value' => json_encode('Newer'), 'created_at' => now(), 'updated_at' => now()],
    ]);

    xsRunMove();
    xsRunMove();

    expect(xsSetting('exit', 'experience_template'))->toBe('Newer')
        ->and(DB::table('settings')->where('group', 'exit')->count())->toBe(1)
        ->and(DB::table('settings')->where('group', 'letters')->count())->toBe(0);
});

it('does nothing when nothing was saved, and moves it back on down()', function () {
    xsRunMove();
    expect(DB::table('settings')->count())->toBe(0)
        ->and(settings('exit.experience_template'))->toContain('To whom it may concern');

    DB::table('settings')->insert([
        'group' => 'exit', 'key' => 'experience_template', 'value' => json_encode('Back again'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    xsRunMove('down');

    expect(xsSetting('letters', 'experience_template'))->toBe('Back again')
        ->and(DB::table('settings')->where('group', 'exit')->count())->toBe(0);
});
