<?php

use App\Support\PayrollLock;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;

/*
| The real payroll lock: a month is locked once its run is approved or paid; a draft locks nothing.
*/

it('locks nothing without a run, nor with a draft', function () {
    expect(PayrollLock::isLocked('2026-11'))->toBeFalse();

    payrollRun('2026-11');

    expect(PayrollLock::isLocked('2026-11'))->toBeFalse()
        ->and(PayrollLock::latestLocked())->toBeNull();
});

it('locks an approved and a paid month, and only that month', function (string $status) {
    payrollRun('2026-11', $status);

    expect(PayrollLock::isLocked('2026-11'))->toBeTrue()
        ->and(PayrollLock::isLocked('2026-10'))->toBeFalse()
        ->and(PayrollLock::isLocked('2026-12'))->toBeFalse();
})->with(['approved', 'paid']);

it('finds the first locked month among dates in the order given, with one query', function () {
    payrollRun('2026-10', 'paid');
    payrollRun('2026-11', 'approved');
    payrollRun('2026-12');

    DB::enableQueryLog();
    $first = PayrollLock::firstLocked(['2026-12-05', '2026-11-24', '2026-10-01', '2026-11-25']);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($first)->toBe('2026-11')
        ->and($queries)->toBe(1)
        ->and(PayrollLock::firstLocked(['2026-12-01', '2027-01-01']))->toBeNull()
        ->and(PayrollLock::firstLocked(new ArrayIterator(['2026-10-31'])))->toBe('2026-10');
});

it('lists the locked months among many with one query', function () {
    payrollRun('2026-09', 'paid');
    payrollRun('2026-11', 'approved');
    payrollRun('2026-12');

    DB::enableQueryLog();
    $locked = PayrollLock::lockedMonths(['2026-12', '2026-11', '2026-10', '2026-09', '2026-11']);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($locked)->toBe(['2026-09', '2026-11'])->and($queries)->toBe(1);
});

it('gives the latest locked month', function () {
    payrollRun('2026-09', 'paid');
    payrollRun('2026-10', 'approved');
    payrollRun('2026-11');

    expect(PayrollLock::latestLocked())->toBe('2026-10');
});

it('finds a locked month inside a date range, in either order, both ends included', function () {
    payrollRun('2026-11', 'approved');
    payrollRun('2027-01', 'paid');

    expect(PayrollLock::rangeTouchesLocked('2026-10-15', '2026-12-02'))->toBe('2026-11')
        ->and(PayrollLock::rangeTouchesLocked('2026-12-02', '2026-10-15'))->toBe('2026-11')
        ->and(PayrollLock::rangeTouchesLocked('2026-11-30', '2026-11-30'))->toBe('2026-11')
        ->and(PayrollLock::rangeTouchesLocked('2026-12-01', '2026-12-31'))->toBeNull()
        ->and(PayrollLock::rangeTouchesLocked('2026-12-31', '2027-01-01'))->toBe('2027-01')
        ->and(PayrollLock::rangeTouchesLocked('2026-10-01', '2026-10-31'))->toBeNull();
});

it('locks the month as soon as its draft run is approved', function () {
    $run = payrollRun('2026-11');
    expect(PayrollLock::isLocked('2026-11'))->toBeFalse();

    $run->update(['status' => 'approved']);

    expect(PayrollLock::isLocked('2026-11'))->toBeTrue()
        ->and(PayrollLock::latestLocked())->toBe('2026-11');
});

it('sends MariaDB a plain top-level select with the share lock, never an exists() subquery', function () {
    // RefreshDatabase's transaction is open, so every read takes the shared lock. Compile what MySQL / MariaDB would be
    // sent (pretend mode runs nothing) by giving the connection the MySQL grammar for the duration.
    $connection = DB::connection();
    $sqlite = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new MySqlGrammar($connection));

    try {
        $sent = $connection->pretend(function () {
            PayrollLock::isLocked('2026-11');
            PayrollLock::lockedMonths(['2026-10', '2026-11']);
            PayrollLock::firstLocked(['2026-11-30']);
            PayrollLock::latestLocked();
            PayrollLock::rangeTouchesLocked('2026-10-01', '2026-12-31');
        });
    } finally {
        $connection->setQueryGrammar($sqlite);
    }

    expect($sent)->toHaveCount(5);

    foreach (array_column($sent, 'query') as $sql) {
        expect($sql)->toStartWith('select `')
            ->toEndWith(' lock in share mode')
            ->not->toContain('exists')
            ->and(substr_count($sql, 'select'))->toBe(1);
    }

    expect($sent[0]['query'])
        // pretend() logs the query with its bindings filled in.
        ->toBe("select `status` from `payroll_runs` where `month` = '2026-11' limit 1 lock in share mode");
});

it('picks the run rows by month only and filters the status in PHP, so a draft row is share-locked too', function () {
    // On MariaDB a `status in (...)` condition could let the optimiser read through the status index and skip the
    // draft row, so a concurrent approval of that draft would not wait for this read. Every reader must select by
    // month (equality, list or range on the unique month index) and lock every row it reads.
    $connection = DB::connection();
    $sqlite = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new MySqlGrammar($connection));

    try {
        $sent = $connection->pretend(function () {
            PayrollLock::isLocked('2026-11');
            PayrollLock::lockedMonths(['2026-10', '2026-11']);
            PayrollLock::firstLocked(['2026-11-30', '2026-12-01']);
            PayrollLock::latestLocked();
            PayrollLock::rangeTouchesLocked('2026-10-01', '2026-12-31');
        });
    } finally {
        $connection->setQueryGrammar($sqlite);
    }

    expect($sent)->toHaveCount(5);

    foreach (array_column($sent, 'query') as $sql) {
        $where = str_contains($sql, ' where ') ? substr($sql, strpos($sql, ' where ')) : '';

        expect($sql)->toEndWith(' lock in share mode')
            ->and($where)->not->toContain('status');
    }

    expect(array_column($sent, 'query'))->toBe([
        "select `status` from `payroll_runs` where `month` = '2026-11' limit 1 lock in share mode",
        "select `status`, `month` from `payroll_runs` where `month` in ('2026-10', '2026-11') order by `month` asc lock in share mode",
        "select `status`, `month` from `payroll_runs` where `month` in ('2026-11', '2026-12') order by `month` asc lock in share mode",
        'select `status`, `month` from `payroll_runs` order by `month` desc lock in share mode',
        "select `status`, `month` from `payroll_runs` where `month` between '2026-10' and '2026-12' order by `month` asc lock in share mode",
    ]);
});

it('gives the same answers when drafts sit between locked months', function () {
    payrollRun('2026-09', 'paid');
    payrollRun('2026-10');
    payrollRun('2026-11', 'approved');
    payrollRun('2026-12');

    expect(PayrollLock::isLocked('2026-10'))->toBeFalse()
        ->and(PayrollLock::isLocked('2026-11'))->toBeTrue()
        ->and(PayrollLock::lockedMonths(['2026-12', '2026-10', '2026-11', '2026-09']))->toBe(['2026-09', '2026-11'])
        ->and(PayrollLock::firstLocked(['2026-12-01', '2026-10-31']))->toBeNull()
        ->and(PayrollLock::latestLocked())->toBe('2026-11')
        ->and(PayrollLock::rangeTouchesLocked('2026-10-01', '2026-10-31'))->toBeNull()
        ->and(PayrollLock::rangeTouchesLocked('2026-10-01', '2026-12-31'))->toBe('2026-11');
});

it('says why a locked month cannot change', function () {
    expect(PayrollLock::message('2026-11'))->toBe('Payroll for November 2026 is approved, so this can no longer change.');
});

it('reads the runs with a shared lock inside a transaction', function () {
    payrollRun('2026-11', 'approved');

    // RefreshDatabase already wraps each test in a transaction, so the shared lock is always on here; outside any
    // transaction (level 0) PayrollLock::query() adds none. Builder::$lock: null = none, false = shared, true = update.
    DB::transaction(function () {
        expect(DB::transactionLevel())->toBeGreaterThan(0)
            ->and(PayrollLock::query()->toBase()->lock)->toBeFalse()
            ->and(PayrollLock::isLocked('2026-11'))->toBeTrue();
    });

    // What MariaDB / MySQL is sent for that read.
    $mysql = new MySqlGrammar(DB::connection());

    expect($mysql->compileSelect(PayrollLock::query()->where('month', '2026-11')->toBase()))->toContain('lock in share mode');
});
