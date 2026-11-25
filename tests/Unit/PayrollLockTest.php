<?php

use App\Support\PayrollLock;

/*
| Input checks only (no database here): every malformed month or date is refused before any query runs. The real lock
| (approved / paid runs) is pinned in tests/Feature/Payroll/PayrollLockTest.php.
*/

it('rejects a malformed month', function (string $month) {
    PayrollLock::isLocked($month);
})->throws(InvalidArgumentException::class, 'Not a payroll month')->with(['2026-13', '2026-00', '2026-1', '26-11', '2026-11-01', '', 'abcd-ef']);

it('rejects a malformed month in lockedMonths and message', function () {
    expect(fn () => PayrollLock::lockedMonths(['2026-11', '2026-13']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PayrollLock::message('2026-1'))->toThrow(InvalidArgumentException::class);
});

it('finds nothing in an empty list without asking the database', function () {
    expect(PayrollLock::firstLocked([]))->toBeNull()
        ->and(PayrollLock::firstLocked(new ArrayIterator([])))->toBeNull()
        ->and(PayrollLock::lockedMonths([]))->toBe([]);
});

it('rejects a malformed date in firstLocked, wherever it is in the list', function (array $dates) {
    PayrollLock::firstLocked($dates);
})->throws(InvalidArgumentException::class, 'Not a date')->with([
    [['2026-11-24', '2026-13-01']],
    [['2026-02-30']],
    [['2026-11-24 00:00:00']],
]);

it('rejects a malformed date in rangeTouchesLocked', function () {
    PayrollLock::rangeTouchesLocked('2026-11-01', '2026-11');
})->throws(InvalidArgumentException::class, 'Not a date');
