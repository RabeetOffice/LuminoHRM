<?php

use App\Actions\Attendance\ApplyManualCorrection;
use App\Actions\Exits\ApproveSettlement;
use App\Actions\Exits\PrepareSettlement;
use App\Actions\Payroll\LoanActions;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/settlement-helpers.php';

// Statement order and locks, with the technique of tests/Feature/Assets/AssignmentLockOrderTest.php: SQLite drops the
// lock clause, so the grammar is swapped for one writing it as a trailing comment. Lock order (design 5.6): approval =
// employee FOR UPDATE, resignation FOR UPDATE, payroll_runs shared, settlement FOR UPDATE, loans FOR UPDATE; a
// per-employee writer = employee FOR UPDATE, payroll_runs shared, final_settlements shared.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01']);
    $this->hr = userWithRole('hr');
    $this->admin = userWithRole('admin');
});

if (! function_exists('sloStatements')) {
    /** @return list<string> the SQL $run sends (no role / permission / activity log / savepoint statements) */
    function sloStatements(Closure $run): array
    {
        $connection = DB::connection();
        $original = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                return match (true) {
                    $value === true => '/* for update */',
                    $value === false => '/* shared */',
                    default => '',
                };
            }
        });

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        try {
            rescue($run, report: false);
        } finally {
            $connection->setQueryGrammar($original);
        }

        return array_values(array_filter($statements, fn (string $sql) => ! str_contains($sql, 'savepoint')
            && ! str_contains($sql, 'roles') && ! str_contains($sql, 'permissions') && ! str_contains($sql, 'activity_log')
            && ! str_contains($sql, '"settings"')));
    }

    /** Index of the first select from $table ending with $lock ('/* for update *\/' or '/* shared *\/'), or -1. */
    function sloFirst(array $statements, string $table, string $lock): int
    {
        foreach ($statements as $i => $sql) {
            if (str_starts_with($sql, 'select') && str_contains($sql, "from \"{$table}\"") && str_ends_with($sql, $lock)) {
                return $i;
            }
        }

        return -1;
    }
}

it('approve: employee, resignation, payroll runs, settlement, loans, in that order, before any plain read', function () {
    $e = stPerson();
    $exit = approvedExit($e, '2026-11-18');
    stDays($e, '2026-11-01', '2026-11-18');
    loanFor($e, 1000, 100, '2026-06');
    $s = app(PrepareSettlement::class)->handle($this->hr, $exit);
    stClear($exit);

    $statements = sloStatements(fn () => app(ApproveSettlement::class)->handle($this->admin, $s));

    $employee = sloFirst($statements, 'employees', '/* for update */');
    $resignation = sloFirst($statements, 'resignations', '/* for update */');
    $runs = sloFirst($statements, 'payroll_runs', '/* shared */');
    $settlement = sloFirst($statements, 'final_settlements', '/* for update */');
    $loans = sloFirst($statements, 'loans', '/* for update */');

    expect($employee)->toBe(0)
        ->and($resignation)->toBe(1)
        ->and($runs)->toBe(2)
        ->and($settlement)->toBe(3)
        ->and($loans)->toBe(4)
        // Its own settlement row is never share-locked (PayrollLock is not read on it).
        ->and(sloFirst($statements, 'final_settlements', '/* shared */'))->toBe(-1);
});

it('a per-employee writer: employee, then payroll runs shared, then final settlements shared', function () {
    $e = stPerson();
    $loan = loanFor($e, 1000, 100, '2026-06');

    $statements = sloStatements(fn () => app(LoanActions::class)->skip($this->hr, $loan, '2027-01', 'Festival month'));

    $employee = sloFirst($statements, 'employees', '/* for update */');
    $runs = sloFirst($statements, 'payroll_runs', '/* shared */');
    $settlements = sloFirst($statements, 'final_settlements', '/* shared */');

    expect($employee)->toBeGreaterThanOrEqual(0)
        ->and($runs)->toBeGreaterThan($employee)
        ->and($settlements)->toBeGreaterThan($runs)
        ->and(sloFirst($statements, 'loans', '/* for update */'))->toBeGreaterThan($settlements);
});

it('a writer that locks no employee row share-locks the settlement rows before the attendance row', function () {
    $e = stPerson();
    stDays($e, '2026-11-01', '2026-11-20');

    $statements = sloStatements(fn () => app(ApplyManualCorrection::class)->handle($e, '2026-11-10', '09:00', '18:00', null, 'Forgot to punch', $this->hr));

    $runs = sloFirst($statements, 'payroll_runs', '/* shared */');
    $settlements = sloFirst($statements, 'final_settlements', '/* shared */');
    $attendance = sloFirst($statements, 'attendances', '/* for update */');

    expect($runs)->toBeGreaterThanOrEqual(0)
        ->and($settlements)->toBeGreaterThan($runs)
        ->and($attendance)->toBeGreaterThan($settlements);
});
