<?php

use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Support\Payroll\PayrollRoster;
use Illuminate\Support\Facades\DB;

/*
 * Who is in the November 2026 run (design 5.2): included, settled separately (last working day in the month) and left
 * without a last working day. Someone whose last working day is before the month is in no list.
 */

/** @param  array<string, mixed>  $attrs */
function rosterPerson(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2025-01-01', ...$attrs]);
}

/** @return list<int> */
function rosterIds(array $list): array
{
    return array_map(fn (array $row): int => $row['id'], $list);
}

it('puts each person in the right list', function () {
    $active = rosterPerson();
    $probation = rosterPerson(['status' => 'probation']);
    $joinsLastDay = rosterPerson(['joining_date' => '2026-11-30']);
    $joinsDecember = rosterPerson(['joining_date' => '2026-12-01']);
    $leaves20th = rosterPerson(['status' => 'resigned', 'last_working_day' => '2026-11-20']);
    $leavesLastDay = rosterPerson(['last_working_day' => '2026-11-30']);
    $leavesFirstDay = rosterPerson(['status' => 'terminated', 'last_working_day' => '2026-11-01']);
    $leavesDecember = rosterPerson(['status' => 'resigned', 'last_working_day' => '2026-12-05']);
    $leftOctober = rosterPerson(['status' => 'resigned', 'last_working_day' => '2026-10-31']);
    $inactiveFuture = rosterPerson(['status' => 'inactive', 'last_working_day' => '2026-12-31']);
    $resignedNoDay = rosterPerson(['status' => 'resigned']);
    $inactiveNoDay = rosterPerson(['status' => 'inactive']);
    $terminatedNoDayJoinsLater = rosterPerson(['status' => 'terminated', 'joining_date' => '2027-01-01']);

    $roster = PayrollRoster::forMonth('2026-11');
    $included = $roster->included->pluck('id')->all();

    expect($included)->toEqualCanonicalizing([
        $active->id, $probation->id, $joinsLastDay->id, $leavesDecember->id, $inactiveFuture->id,
    ])
        ->and(rosterIds($roster->settledSeparately))->toEqualCanonicalizing([$leaves20th->id, $leavesLastDay->id, $leavesFirstDay->id])
        ->and(rosterIds($roster->leftWithoutLastDay))->toEqualCanonicalizing([$resignedNoDay->id, $inactiveNoDay->id]);

    $everyone = [...$included, ...rosterIds($roster->settledSeparately), ...rosterIds($roster->leftWithoutLastDay)];
    expect($everyone)->not->toContain($joinsDecember->id)
        ->not->toContain($leftOctober->id)
        ->not->toContain($terminatedNoDayJoinsLater->id);
});

it('lists the settled-separately and left people with name, code and date or status', function () {
    $leaver = rosterPerson(['first_name' => 'Sara', 'last_name' => 'Ahmed', 'last_working_day' => '2026-11-20']);
    $gone = rosterPerson(['first_name' => 'Ali', 'last_name' => 'Khan', 'status' => 'resigned']);

    $roster = PayrollRoster::forMonth('2026-11');

    expect($roster->settledSeparately)->toBe([
        ['id' => $leaver->id, 'name' => 'Sara Ahmed', 'code' => $leaver->employee_code, 'last_working_day' => '2026-11-20'],
    ])
        ->and($roster->leftWithoutLastDay)->toBe([
            ['id' => $gone->id, 'name' => 'Ali Khan', 'code' => $gone->employee_code, 'status' => 'resigned'],
        ]);
});

it('loads the included people completely with their relations', function () {
    $e = rosterPerson();
    EmployeeBankAccount::query()->create(['employee_id' => $e->id, 'bank_name' => 'HBL', 'account_title' => 'X', 'account_number' => '123']);

    $person = PayrollRoster::forMonth('2026-11')->included->first();

    foreach (['department', 'designation', 'shift', 'bankAccount', 'user'] as $relation) {
        expect($person->relationLoaded($relation))->toBeTrue();
    }

    expect($person->bankAccount->bank_name)->toBe('HBL')
        ->and($person->getAttributes())->toHaveKeys(['joining_date', 'last_working_day', 'attendance_exempt', 'shift_id', 'status']);
});

it('reads a fixed number of queries whatever the headcount', function () {
    $count = function (): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        PayrollRoster::forMonth('2026-11');

        return count(DB::getQueryLog());
    };

    rosterPerson();
    rosterPerson(['last_working_day' => '2026-11-20']);
    rosterPerson(['status' => 'resigned']);
    $few = $count();

    for ($i = 0; $i < 4; $i++) {
        rosterPerson();
        rosterPerson(['last_working_day' => '2026-11-20']);
        rosterPerson(['status' => 'resigned']);
    }

    expect($count())->toBe($few);
});

it('rejects a malformed month', function () {
    PayrollRoster::forMonth('2026-13');
})->throws(InvalidArgumentException::class);
