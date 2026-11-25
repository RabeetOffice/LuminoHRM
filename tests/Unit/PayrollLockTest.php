<?php

use App\Support\PayrollLock;

it('reports no month as locked in this phase', function (string $month) {
    expect(PayrollLock::isLocked($month))->toBeFalse();
})->with(['2026-01', '2026-11', '2026-12', '1999-07']);

it('rejects a malformed month', function (string $month) {
    PayrollLock::isLocked($month);
})->throws(InvalidArgumentException::class)->with(['2026-13', '2026-00', '2026-1', '26-11', '2026-11-01', '', 'abcd-ef']);

it('finds no locked month among dates', function () {
    expect(PayrollLock::firstLocked(['2026-11-24', '2026-12-31', '2027-01-01']))->toBeNull()
        ->and(PayrollLock::firstLocked([]))->toBeNull()
        ->and(PayrollLock::firstLocked(new ArrayIterator(['2026-11-24'])))->toBeNull();
});

it('rejects a malformed date in firstLocked', function () {
    PayrollLock::firstLocked(['2026-11-24', '2026-13-01']);
})->throws(InvalidArgumentException::class);
