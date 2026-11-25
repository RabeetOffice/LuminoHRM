<?php

use App\Models\Employee;
use App\Models\LeaveDay;
use App\Support\Leave\LeaveLedger;

/*
 * LeaveLedger: unpaid days for payroll, and who is on leave on a date. approvedLeave() writes a day for every
 * calendar date, so only weekday ranges are used (Mon 2026-11-23 … Fri 27, Mon 30).
 */

it('counts unpaid days and halves in a range and ignores paid days', function () {
    $e = Employee::factory()->create();
    approvedLeave($e, '2026-11-23', '2026-11-24', type: 'Unpaid');     // 2
    approvedLeave($e, '2026-11-25', null, 'pm', 'Unpaid');              // 0.5
    approvedLeave($e, '2026-11-26', '2026-11-27');                      // Annual: paid
    approvedLeave($e, '2026-11-30', null, 'am', 'Sick');                // paid half
    approvedLeave($e, '2026-12-01', null, 'am', 'Unpaid');              // outside the range

    $days = LeaveLedger::unpaidDays($e->id, '2026-11-01', '2026-11-30');

    expect($days)->toBeFloat()->toBe(2.5)
        ->and(LeaveLedger::unpaidDays($e->id, '2026-11-24', '2026-11-24'))->toBe(1.0) // both ends inclusive
        ->and(LeaveLedger::unpaidDays($e->id, '2026-11-01', '2026-12-31'))->toBe(3.0)
        ->and(LeaveLedger::unpaidDays($e->id, '2026-10-01', '2026-10-31'))->toBeFloat()->toBe(0.0);
});

it('counts only the given employee', function () {
    $a = Employee::factory()->create();
    $b = Employee::factory()->create();
    approvedLeave($a, '2026-11-23', null, type: 'Unpaid');
    approvedLeave($b, '2026-11-23', '2026-11-25', type: 'Unpaid');

    expect(LeaveLedger::unpaidDays($a->id, '2026-11-01', '2026-11-30'))->toBe(1.0);
});

it('never counts a half on top of a full unpaid day on the same date', function () {
    $e = Employee::factory()->create();
    $request = approvedLeave($e, '2026-11-23', null, type: 'Unpaid');
    LeaveDay::query()->create([
        'leave_request_id' => $request->id, 'employee_id' => $e->id, 'leave_type_id' => leaveType('Unpaid')->id,
        'date' => '2026-11-23', 'amount' => 0.5, 'half' => 'pm',
    ]);

    expect(LeaveLedger::unpaidDays($e->id, '2026-11-23', '2026-11-23'))->toBe(1.0);
});

it('sums many unpaid halves exactly', function () {
    $e = Employee::factory()->create();
    $d = new DateTimeImmutable('2026-01-01');

    for ($i = 0; $i < 61; $i++, $d = $d->modify('+1 day')) {
        approvedLeave($e, $d->format('Y-m-d'), null, 'am', 'Unpaid');
    }

    expect(LeaveLedger::unpaidDays($e->id, '2026-01-01', '2026-12-31'))->toBe(30.5);
});

it('says who is on leave on a date, with type, colour and half', function () {
    $full = Employee::factory()->create();
    $am = Employee::factory()->create();
    $both = Employee::factory()->create();
    $none = Employee::factory()->create();
    $outside = Employee::factory()->create();
    approvedLeave($full, '2026-11-23', '2026-11-25', type: 'Sick');
    approvedLeave($am, '2026-11-24', null, 'am');
    approvedLeave($both, '2026-11-24', null, 'pm', 'Casual');
    approvedLeave($both, '2026-11-24', null, 'am', 'Unpaid');
    approvedLeave($outside, '2026-11-24');

    $on = LeaveLedger::daysOn([$full->id, $am->id, $both->id, $none->id], '2026-11-24');

    expect($on)->toBe([
        $full->id => ['type' => 'Sick', 'colour' => leaveType('Sick')->colour, 'half' => 'full'],
        $am->id => ['type' => 'Annual', 'colour' => leaveType('Annual')->colour, 'half' => 'am'],
        $both->id => ['type' => 'Unpaid', 'colour' => leaveType('Unpaid')->colour, 'half' => 'full'],
    ])
        ->and(LeaveLedger::daysOn([$full->id], '2026-11-26'))->toBe([])
        ->and(LeaveLedger::daysOn([], '2026-11-24'))->toBe([]);
});

it('reports a full row plus an am row on the same date as one full day of the full row\'s type', function () {
    $e = Employee::factory()->create();
    $request = approvedLeave($e, '2026-11-24', null, type: 'Sick');
    LeaveDay::query()->create([
        'leave_request_id' => $request->id, 'employee_id' => $e->id, 'leave_type_id' => leaveType('Annual')->id,
        'date' => '2026-11-24', 'amount' => 0.5, 'half' => 'am',
    ]);

    expect(LeaveLedger::daysOn([$e->id], '2026-11-24'))->toBe([
        $e->id => ['type' => 'Sick', 'colour' => leaveType('Sick')->colour, 'half' => 'full'],
    ]);
});

it('rejects a malformed date', function () {
    LeaveLedger::unpaidDays(1, '2026-11-31', '2026-12-01');
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------- unpaidByDate (payroll)

it('gives unpaid halves per person and date, ignoring paid types and dates outside the range', function () {
    $a = Employee::factory()->create();
    $b = Employee::factory()->create();
    $none = Employee::factory()->create();
    approvedLeave($a, '2026-11-23', '2026-11-24', type: 'Unpaid');    // 2 + 2
    approvedLeave($a, '2026-11-25', null, 'pm', 'Unpaid');             // 1
    approvedLeave($a, '2026-11-26', '2026-11-27');                     // Annual: paid
    approvedLeave($a, '2026-12-01', null, 'am', 'Unpaid');             // outside
    approvedLeave($b, '2026-11-30', null, 'am', 'Unpaid');             // 1, last day inclusive
    approvedLeave($none, '2026-11-24', null, type: 'Sick');

    expect(LeaveLedger::unpaidByDate([$a->id, $b->id, $none->id], '2026-11-01', '2026-11-30'))->toBe([
        $a->id => ['2026-11-23' => 2, '2026-11-24' => 2, '2026-11-25' => 1],
        $b->id => ['2026-11-30' => 1],
    ])
        ->and(LeaveLedger::unpaidByDate([], '2026-11-01', '2026-11-30'))->toBe([]);
});

it('sums an am and a pm unpaid half to 2, and a full row wins over a half on the same date', function () {
    $e = Employee::factory()->create();
    approvedLeave($e, '2026-11-23', null, 'am', 'Unpaid');
    approvedLeave($e, '2026-11-23', null, 'pm', 'Unpaid');
    $request = approvedLeave($e, '2026-11-24', null, type: 'Unpaid');
    LeaveDay::query()->create([
        'leave_request_id' => $request->id, 'employee_id' => $e->id, 'leave_type_id' => leaveType('Unpaid')->id,
        'date' => '2026-11-24', 'amount' => 0.5, 'half' => 'pm',
    ]);

    expect(LeaveLedger::unpaidByDate([$e->id], '2026-11-23', '2026-11-24'))->toBe([
        $e->id => ['2026-11-23' => 2, '2026-11-24' => 2],
    ]);
});

it('reads unpaid days of many people in one query', function () {
    $people = Employee::factory()->count(5)->create();
    foreach ($people as $p) {
        approvedLeave($p, '2026-11-23', '2026-11-25', type: 'Unpaid');
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = LeaveLedger::unpaidByDate($people->pluck('id')->all(), '2026-11-01', '2026-11-30');

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($result)->toHaveCount(5);
});

it('rejects a malformed date in unpaidByDate', function () {
    LeaveLedger::unpaidByDate([1], '2026-11-01', '2026-11-31');
})->throws(InvalidArgumentException::class);
