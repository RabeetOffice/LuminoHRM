<?php

use App\Models\Employee;
use App\Support\EmployeeCode;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/** Inserts an employee row with an explicit code, bypassing the factory's own sequence. */
function employeeWithCode(string $code): Employee
{
    return Employee::factory()->create(['employee_code' => $code]);
}

it('starts at LD-0001 on an empty table', function () {
    expect(EmployeeCode::next())->toBe('LD-0001');
});

it('continues after the highest numeric suffix', function () {
    employeeWithCode('LD-0009');
    expect(EmployeeCode::next())->toBe('LD-0010');

    employeeWithCode('LD-0002');
    expect(EmployeeCode::next())->toBe('LD-0010');
});

it('grows past 9999 without padding', function () {
    employeeWithCode('LD-9999');
    expect(EmployeeCode::next())->toBe('LD-10000');
});

it('ignores codes that are not LD- followed by digits', function () {
    employeeWithCode('LD-0003');
    employeeWithCode('XX-0500');
    employeeWithCode('LD-ABC');
    employeeWithCode('LD-12X');
    employeeWithCode('ld-0900');

    expect(EmployeeCode::next())->toBe('LD-0004');
});

it('opens its own transaction when the caller has none', function () {
    $begun = 0;
    Event::listen(TransactionBeginning::class, function () use (&$begun) {
        $begun++;
    });

    EmployeeCode::next();
    expect($begun)->toBe(1);
});

it('joins the callers transaction instead of opening another', function () {
    // RefreshDatabase already holds one transaction around the test, so compare with the level before.
    $base = DB::transactionLevel();
    $levels = [];

    DB::transaction(function () use (&$levels) {
        $levels[] = DB::transactionLevel();
        EmployeeCode::next();
        $levels[] = DB::transactionLevel();
    });

    expect($levels)->toBe([$base + 1, $base + 1])->and(DB::transactionLevel())->toBe($base);
});

it('reads the existing codes under a row lock', function () {
    expect(EmployeeCode::lockedCodes()->getQuery()->lock)->toBeTrue();
});

it('hands the same code to a second caller that has not saved yet, and the unique index refuses the loser', function () {
    // SQLite has no row locks, so this is the interleaving a lock prevents on MariaDB: both read before either saves.
    $first = EmployeeCode::next();
    $second = EmployeeCode::next();
    expect($second)->toBe($first);

    employeeWithCode($first);

    // The backstop: the second insert hits the unique index, and the helper recognises it as a code conflict.
    try {
        employeeWithCode($second);
        $this->fail('The unique index should have refused the duplicate code.');
    } catch (UniqueConstraintViolationException $e) {
        expect(EmployeeCode::isCodeConflict($e))->toBeTrue();
    }

    // Retrying after the conflict gives a fresh code.
    expect(EmployeeCode::next())->not->toBe($first)->toBe('LD-0002');
});

it('does not treat another unique violation as a code conflict', function () {
    employeeWithCode('LD-0001');

    try {
        Employee::factory()->create(['employee_code' => 'LD-0002', 'email' => Employee::first()->email]);
        $this->fail('The unique email index should have refused the duplicate.');
    } catch (UniqueConstraintViolationException $e) {
        expect(EmployeeCode::isCodeConflict($e))->toBeFalse();
    }
});
