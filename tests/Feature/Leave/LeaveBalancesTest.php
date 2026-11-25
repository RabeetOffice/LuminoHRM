<?php

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveDay;
use App\Models\LeaveType;
use App\Support\Leave\BalanceLine;
use App\Support\Leave\LeaveBalances;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * LeaveBalances: allocation (pro-rating), rows, and the formula
 *   remaining = allocated + carried + adjustment − opening_used − used;  available = remaining − pending.
 * Today is 2026-11-25 (current leave year 2026). Seeded types: Annual 14 (carry, cap 5), Sick 8, Casual 10, Unpaid.
 * approvedLeave() writes a day for every calendar date, so only weekday ranges are used.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Karachi'));
});

function lbSvc(): LeaveBalances
{
    return app(LeaveBalances::class);
}

function lbEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2024-03-01', ...$attrs]);
}

function lbRow(Employee $e, string $type, int $year, float $allocated, float $adjustment = 0, float $openingUsed = 0): LeaveBalance
{
    return LeaveBalance::query()->create([
        'employee_id' => $e->id, 'leave_type_id' => leaveType($type)->id, 'year' => $year,
        'allocated' => $allocated, 'adjustment' => $adjustment, 'opening_used' => $openingUsed,
    ]);
}

function lbLine(Employee $e, string $type, int $year, ?int $ignore = null): BalanceLine
{
    return lbSvc()->line($e, leaveType($type), $year, $ignore);
}

// --- allocation -----------------------------------------------------------------------------------------------

dataset('pro-rating', [
    'joined 1 Jan, 14 → 14' => ['2026-01-01', 14, 2026, true, 14.0],
    'joined 31 Jan, 14 → 14' => ['2026-01-31', 14, 2026, true, 14.0],
    'joined 15 Jul, 14 → 7.0' => ['2026-07-15', 14, 2026, true, 7.0],
    'joined 1 Oct, 10 → 2.5' => ['2026-10-01', 10, 2026, true, 2.5],
    'joined 1 Dec, 8 → 0.5' => ['2026-12-01', 8, 2026, true, 0.5],
    'joined 1 Feb, 10 → 9 (9.17 rounded down)' => ['2026-02-01', 10, 2026, true, 9.0],
    'joined 1 Nov, 14 → 2 (2.33 rounded down)' => ['2026-11-01', 14, 2026, true, 2.0],
    'joined 1 Jun, 7.5 → 4 (4.375 rounded down)' => ['2026-06-01', 7.5, 2026, true, 4.0],
    'pro-rating off → full' => ['2026-07-15', 14, 2026, false, 14.0],
    'year before joining → 0' => ['2026-07-15', 14, 2025, true, 0.0],
    'year after joining → full' => ['2026-07-15', 14, 2027, true, 14.0],
]);

it('allocates the quota, pro-rated for a mid-year joiner', function (string $joined, float $quota, int $year, bool $prorate, float $expected) {
    Settings::set('leave', ['prorate_new_joiners' => $prorate]);
    $type = LeaveType::factory()->create(['yearly_quota' => $quota]);
    $e = lbEmployee(['joining_date' => $joined]);

    expect(lbSvc()->allocationFor($e, $type, $year))->toBe($expected);
})->with('pro-rating');

it('allocates nothing for an unpaid type', function () {
    expect(lbSvc()->allocationFor(lbEmployee(), leaveType('Unpaid'), 2026))->toBe(0.0);
});

// --- rows -----------------------------------------------------------------------------------------------------

it('creates a missing row once and never changes an existing one', function () {
    $e = lbEmployee(['joining_date' => '2026-07-15']);
    $annual = leaveType('Annual');

    $row = lbSvc()->ensureRow($e, $annual, 2026);
    expect($row->allocated)->toBe(7.0)->and($row->adjustment)->toBe(0.0);

    $row->update(['allocated' => 9, 'opening_used' => 1.5]);
    $again = lbSvc()->ensureRow($e, $annual, 2026);

    expect($again->id)->toBe($row->id)
        ->and($again->allocated)->toBe(9.0)
        ->and($again->opening_used)->toBe(1.5)
        ->and(LeaveBalance::query()->count())->toBe(1);
});

it('refuses a row for an unpaid type', function () {
    lbSvc()->ensureRow(lbEmployee(), leaveType('Unpaid'), 2026);
})->throws(InvalidArgumentException::class);

it('ensures a year for every active paid type only', function () {
    $e = lbEmployee();
    leaveType('Casual', ['is_active' => false]);

    lbSvc()->ensureYear($e, 2026);
    lbSvc()->ensureYear($e, 2026);

    expect(LeaveBalance::query()->where('employee_id', $e->id)->with('type')->get()->pluck('type.name')->sort()->values()->all())
        ->toBe(['Annual', 'Sick']);
});

it('skips years before joining and after the last working day', function () {
    $e = lbEmployee(['joining_date' => '2025-05-01', 'status' => 'resigned', 'last_working_day' => '2026-03-31']);

    lbSvc()->ensureYear($e, 2024);
    lbSvc()->ensureYear($e, 2027);
    expect(LeaveBalance::query()->count())->toBe(0);

    lbSvc()->ensureYear($e, 2026);
    expect(LeaveBalance::query()->count())->toBe(3); // Annual, Sick, Casual
});

// --- the formula ------------------------------------------------------------------------------------------------

it('works out remaining with opening used and an adjustment', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2026, 14, -1.5, 2);
    approvedLeave($e, '2026-11-23', '2026-11-24');          // Mon-Tue: 2
    approvedLeave($e, '2026-11-26', null, 'am');             // 0.5

    $line = lbLine($e, 'Annual', 2026);

    expect($line->allocated)->toBe(14.0)
        ->and($line->adjustment)->toBe(-1.5)
        ->and($line->openingUsed)->toBe(2.0)
        ->and($line->used)->toBe(2.5)
        ->and($line->remaining)->toBe(8.0)          // 14 − 1.5 − 2 − 2.5
        ->and($line->available)->toBe(8.0)
        ->and($line->overdrawn)->toBeFalse()
        ->and($line->projected)->toBeFalse();
});

it('counts used only for that type and that year', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2026, 14);
    approvedLeave($e, '2026-11-23', '2026-11-23');                      // Annual 2026: counts
    approvedLeave($e, '2026-11-24', '2026-11-25', type: 'Sick');        // other type
    approvedLeave($e, '2025-12-01', '2025-12-02');                      // other year (no 2025 row: carries nothing)
    approvedLeave($e, '2027-01-04', '2027-01-04');                      // later year

    expect(lbLine($e, 'Annual', 2026)->used)->toBe(1.0)
        ->and(lbLine($e, 'Annual', 2026)->carried)->toBe(0.0)
        ->and(lbLine($e, 'Sick', 2026)->used)->toBe(2.0);
});

it('counts a full day and a half day on the same date as one day', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2026, 14);
    $request = approvedLeave($e, '2026-11-23', '2026-11-23');
    LeaveDay::query()->create([
        'leave_request_id' => $request->id, 'employee_id' => $e->id, 'leave_type_id' => leaveType()->id,
        'date' => '2026-11-23', 'amount' => 0.5, 'half' => 'am',
    ]);

    expect(lbLine($e, 'Annual', 2026)->used)->toBe(1.0);
});

it('counts pending from open requests only, and leaves out the ignored one', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2026, 14);
    $open = leaveRequest($e, ['from_date' => '2026-12-07', 'to_date' => '2026-12-09', 'days' => 3]);
    leaveRequest($e, ['from_date' => '2026-12-10', 'to_date' => '2026-12-10', 'is_half_day' => true, 'half' => 'am', 'status' => 'manager_approved']);
    leaveRequest($e, ['from_date' => '2026-12-14', 'to_date' => '2026-12-14', 'status' => 'rejected']);
    leaveRequest($e, ['from_date' => '2026-12-15', 'to_date' => '2026-12-15', 'status' => 'cancelled']);
    leaveRequest($e, ['from_date' => '2026-12-16', 'to_date' => '2026-12-16', 'status' => 'approved']); // no days: not used either
    leaveRequest($e, ['from_date' => '2026-12-17', 'to_date' => '2026-12-17', 'leave_type_id' => leaveType('Sick')->id]);
    leaveRequest($e, ['from_date' => '2027-01-05', 'to_date' => '2027-01-05']); // next year

    $line = lbLine($e, 'Annual', 2026);

    expect($line->pending)->toBe(3.5)
        ->and($line->remaining)->toBe(14.0)
        ->and($line->available)->toBe(10.5)
        ->and(lbLine($e, 'Annual', 2026, $open->id)->pending)->toBe(0.5)
        ->and(lbLine($e, 'Annual', 2026, $open->id)->available)->toBe(13.5);
});

it('splits a pending request across 31 Dec between the two years', function () {
    $e = lbEmployee();
    shiftFor($e);
    lbRow($e, 'Annual', 2026, 14);
    leaveRequest($e, ['from_date' => '2026-12-30', 'to_date' => '2027-01-05', 'days' => 5]); // Wed 30, Thu 31 | Fri 1, Mon 4, Tue 5

    expect(lbLine($e, 'Annual', 2026)->pending)->toBe(2.0)
        ->and(lbLine($e, 'Annual', 2027)->pending)->toBe(3.0);
});

it('carries min(remaining last year, cap), never negative', function (float $usedLastYear, float $adjustment, float $expected) {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2025, 14, $adjustment);
    lbRow($e, 'Annual', 2026, 14);

    // $usedLastYear as weekdays in 2025 starting Mon 1 Sep (whole days only in this table).
    $days = 0;
    for ($d = new DateTimeImmutable('2025-09-01'); $days < $usedLastYear; $d = $d->modify('+1 day')) {
        if ((int) $d->format('N') <= 5) {
            approvedLeave($e, $d->format('Y-m-d'));
            $days++;
        }
    }

    $line = lbLine($e, 'Annual', 2026);

    expect($line->carried)->toBe($expected)
        ->and($line->remaining)->toBe(14.0 + $expected);
})->with([
    'remaining 11 → capped at 5' => [3, 0, 5.0],
    'remaining 2 → 2' => [12, 0, 2.0],
    'remaining 5 → 5' => [9, 0, 5.0],
    'overdrawn last year → 0' => [0, -20, 0.0],
    'nothing left → 0' => [14, 0, 0.0],
]);

it('carries nothing for a type without carry-forward', function () {
    $e = lbEmployee();
    lbRow($e, 'Sick', 2025, 8);
    lbRow($e, 'Sick', 2026, 8);

    expect(lbLine($e, 'Sick', 2026)->carried)->toBe(0.0)
        ->and(lbLine($e, 'Sick', 2026)->remaining)->toBe(8.0);
});

it('carries nothing into the first year with a row (go-live)', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2026, 14);

    expect(lbLine($e, 'Annual', 2026)->carried)->toBe(0.0);
});

it('carries nothing over a year without a row', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2024, 14);
    lbRow($e, 'Annual', 2026, 14);

    expect(lbLine($e, 'Annual', 2026)->carried)->toBe(0.0);
});

it('chains carry through several years with a cap', function () {
    $e = lbEmployee();
    leaveType('Annual', ['carry_forward_cap' => 10]);
    lbRow($e, 'Annual', 2024, 14);                 // remaining 14 → carries 10
    lbRow($e, 'Annual', 2025, 2);                  // 2 + 10 = 12, used 4 → 8 → carries 8
    lbRow($e, 'Annual', 2026, 14);
    approvedLeave($e, '2025-03-03', '2025-03-06'); // Mon-Thu: 4

    expect(lbLine($e, 'Annual', 2025)->carried)->toBe(10.0)
        ->and(lbLine($e, 'Annual', 2025)->remaining)->toBe(8.0)
        ->and(lbLine($e, 'Annual', 2026)->carried)->toBe(8.0);
});

it('lowers this year\'s carry when leave is backdated into last December', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2025, 14);
    lbRow($e, 'Annual', 2026, 14);
    approvedLeave($e, '2025-11-03', '2025-11-07'); // 5
    approvedLeave($e, '2025-11-10', '2025-11-14'); // 5 → remaining 4

    expect(lbLine($e, 'Annual', 2026)->carried)->toBe(4.0);

    approvedLeave($e, '2025-12-29', '2025-12-30'); // backdated: remaining 2

    expect(lbLine($e, 'Annual', 2026)->carried)->toBe(2.0)
        ->and(lbLine($e, 'Annual', 2026)->remaining)->toBe(16.0);
});

it('does not take last year\'s leftover pending off this year\'s carry', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2025, 3);
    lbRow($e, 'Annual', 2026, 14);
    leaveRequest($e, ['from_date' => '2025-12-01', 'to_date' => '2025-12-02', 'days' => 2]); // a stale open request

    expect(lbLine($e, 'Annual', 2026)->carried)->toBe(3.0); // 2026 is the current year: no projection
});

it('projects next year with this year\'s pending taken off the carry', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2026, 14);
    approvedLeave($e, '2026-11-02', '2026-11-06'); // 5
    approvedLeave($e, '2026-11-09', '2026-11-09'); // 1 → remaining 8

    expect(lbLine($e, 'Annual', 2027)->carried)->toBe(5.0); // min(8, 5)

    leaveRequest($e, ['from_date' => '2026-12-07', 'to_date' => '2026-12-10', 'days' => 4]); // remaining − pending = 4

    $next = lbLine($e, 'Annual', 2027);

    expect($next->carried)->toBe(4.0)
        ->and($next->allocated)->toBe(14.0)
        ->and($next->projected)->toBeTrue()
        ->and($next->remaining)->toBe(18.0)
        ->and(LeaveBalance::query()->where('year', 2027)->exists())->toBeFalse(); // nothing written
});

it('adds extra pending days to each year and to the carry walk', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2026, 14);
    $annual = leaveType('Annual');

    // A request with 14 days in 2026 and 16 in 2027: 2026 is fully spoken for, so nothing carries into 2027.
    $y2026 = lbSvc()->line($e, $annual, 2026, extraPending: [2026 => 14, 2027 => 16]);
    $y2027 = lbSvc()->line($e, $annual, 2027, extraPending: [2026 => 14, 2027 => 16]);

    expect($y2026->pending)->toBe(14.0)->and($y2026->available)->toBe(0.0)
        ->and($y2027->carried)->toBe(0.0)->and($y2027->pending)->toBe(16.0)->and($y2027->available)->toBe(-2.0);

    // Without the extra days the line is unchanged; real pending and extra days add up.
    expect(lbLine($e, 'Annual', 2027)->carried)->toBe(5.0);
    leaveRequest($e, ['from_date' => '2026-12-07', 'to_date' => '2026-12-08', 'days' => 2]);
    expect(lbSvc()->line($e, $annual, 2026, extraPending: [2026 => 1.5])->pending)->toBe(3.5);
});

it('leaves a type without carry-forward unaffected by extra pending in another year', function () {
    $e = lbEmployee();
    lbRow($e, 'Sick', 2026, 8);

    expect(lbSvc()->line($e, leaveType('Sick'), 2027, extraPending: [2026 => 8])->available)->toBe(8.0);
});

it('names the last year with a balance row or open request of the type', function () {
    $e = lbEmployee();
    $annual = leaveType('Annual');

    expect(lbSvc()->lastYearInUse($e, $annual))->toBeNull();

    lbRow($e, 'Annual', 2026, 14);
    expect(lbSvc()->lastYearInUse($e, $annual))->toBe(2026);

    $r = leaveRequest($e, ['from_date' => '2027-02-01', 'to_date' => '2027-02-02', 'days' => 2]);
    leaveRequest($e, ['from_date' => '2028-02-01', 'to_date' => '2028-02-02', 'days' => 2, 'status' => 'rejected']);
    leaveRequest($e, ['leave_type_id' => leaveType('Sick')->id, 'from_date' => '2028-03-01', 'to_date' => '2028-03-01', 'days' => 1]);

    expect(lbSvc()->lastYearInUse($e, $annual))->toBe(2027)
        ->and(lbSvc()->lastYearInUse($e, $annual, $r->id))->toBe(2026);
});

it('projects a year without a row from allocationFor', function () {
    Settings::set('leave', ['prorate_new_joiners' => true]);
    $e = lbEmployee(['joining_date' => '2026-07-15']);

    $line = lbLine($e, 'Annual', 2026);

    expect($line->allocated)->toBe(7.0)->and($line->projected)->toBeTrue()->and($line->remaining)->toBe(7.0);
});

it('shows a negative remaining as overdrawn', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2026, 1);
    approvedLeave($e, '2026-11-23', '2026-11-24');
    approvedLeave($e, '2026-11-25', null, 'pm');

    $line = lbLine($e, 'Annual', 2026);

    expect($line->remaining)->toBe(-1.5)->and($line->overdrawn)->toBeTrue()
        ->and($line->toArray()['label'])->toBe('-1.5 of 1');
});

it('never limits an unpaid type', function () {
    $e = lbEmployee();
    approvedLeave($e, '2026-11-23', '2026-11-27', type: 'Unpaid');
    approvedLeave($e, '2026-11-30', null, 'am', 'Unpaid');
    leaveRequest($e, ['leave_type_id' => leaveType('Unpaid')->id, 'from_date' => '2026-12-07', 'to_date' => '2026-12-08', 'days' => 2]);

    $line = lbLine($e, 'Unpaid', 2026);
    $props = $line->toArray();

    expect($line->isPaid)->toBeFalse()
        ->and($line->used)->toBe(5.5)
        ->and($line->pending)->toBe(2.0)
        ->and($line->allocated)->toBe(0.0)
        ->and($line->remaining)->toBe(0.0)
        ->and($line->available)->toBe(INF)
        ->and($line->overdrawn)->toBeFalse()
        ->and($props['available'])->toBeNull()
        ->and($props['unlimited'])->toBeTrue()
        ->and($props['label'])->toBe('5.5 used')
        ->and(json_encode($props))->toBeString()
        ->and(LeaveBalance::query()->count())->toBe(0);
});

it('returns floats and display labels', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2026, 14);
    approvedLeave($e, '2026-11-23', '2026-11-26');
    approvedLeave($e, '2026-11-27', null, 'am');

    $line = lbLine($e, 'Annual', 2026);
    $props = $line->toArray();

    foreach (['allocated', 'carried', 'adjustment', 'opening_used', 'used', 'pending', 'remaining', 'available', 'entitlement'] as $key) {
        expect($props[$key])->toBeFloat();
    }

    expect($props['used'])->toBe(4.5)
        ->and($props['label'])->toBe('9.5 of 14')
        ->and(BalanceLine::format(1.0))->toBe('1')
        ->and(BalanceLine::format(0.5))->toBe('0.5');
});

it('lists active types in sort order, plus inactive ones with a row or days', function () {
    $e = lbEmployee();
    $old = LeaveType::factory()->create(['name' => 'Study', 'is_active' => false, 'sort_order' => 9]);
    LeaveType::factory()->create(['name' => 'Retired', 'is_active' => false, 'sort_order' => 8]);
    lbRow($e, 'Study', 2026, 3);

    $names = array_map(fn (BalanceLine $l): string => $l->typeName, lbSvc()->lines($e, 2026));

    expect($names)->toBe(['Annual', 'Sick', 'Casual', 'Unpaid', 'Study'])
        ->and(lbSvc()->line($e, $old, 2025)->typeName)->toBe('Study');
});

// --- fix round: JSON, locking, complete models, leavers, boundaries ---------------------------------------------

it('json_encodes an unpaid line object directly (no INF in the output)', function () {
    $line = lbLine(lbEmployee(), 'Unpaid', 2026);

    $decoded = json_decode(json_encode($line, JSON_THROW_ON_ERROR), true);

    expect($line)->toBeInstanceOf(JsonSerializable::class)
        ->and($decoded['available'])->toBeNull()
        ->and($decoded['remaining'])->toBeNull()
        ->and($decoded['unlimited'])->toBeTrue()
        ->and($decoded)->toBe(json_decode(json_encode($line->toArray()), true))
        ->and(json_encode([lbLine(lbEmployee(), 'Annual', 2026)]))->toBeString();
});

it('gives the same line with locking on (the approval re-check)', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2026, 14);
    approvedLeave($e, '2026-11-23', '2026-11-24');
    $open = leaveRequest($e, ['from_date' => '2026-12-07', 'to_date' => '2026-12-08', 'days' => 2]);
    leaveRequest($e, ['from_date' => '2026-12-09', 'to_date' => '2026-12-09', 'days' => 1]);

    $plain = lbSvc()->line($e, leaveType('Annual'), 2026, $open->id);
    $locked = DB::transaction(fn () => lbSvc()->line($e, leaveType('Annual'), 2026, $open->id, locking: true));

    expect($locked)->toEqual($plain)
        ->and($locked->pending)->toBe(1.0)
        ->and($locked->available)->toBe(11.0);
});

it('refuses an employee model loaded without joining_date with a clear message', function () {
    $e = lbEmployee();
    $narrow = Employee::query()->select(['id', 'first_name', 'last_name'])->findOrFail($e->id);

    expect(fn () => lbSvc()->lines($narrow, 2026))->toThrow(LogicException::class, 'joining_date')
        ->and(fn () => lbSvc()->forEmployees(collect([$narrow]), 2026))->toThrow(LogicException::class, 'joining_date')
        ->and(fn () => lbSvc()->line($narrow, leaveType('Annual'), 2026))->toThrow(LogicException::class, 'joining_date');
});

it('creates no rows after the current leave year for a leaver without a last working day', function () {
    $e = lbEmployee(['joining_date' => '2025-05-01', 'status' => 'resigned', 'last_working_day' => null]);

    lbSvc()->ensureYear($e, 2027);
    expect(LeaveBalance::query()->count())->toBe(0);

    lbSvc()->ensureYear($e, 2026);
    expect(LeaveBalance::query()->where('year', 2026)->count())->toBe(3);
});

it('ignores the balance rows of a type switched from paid to unpaid', function () {
    $e = lbEmployee();
    lbRow($e, 'Casual', 2026, 10, 2, 1);
    approvedLeave($e, '2026-11-23', null, type: 'Casual');
    leaveType('Casual', ['is_paid' => false, 'carry_forward' => false]);

    $line = lbLine($e, 'Casual', 2026);

    expect($line->isPaid)->toBeFalse()
        ->and($line->allocated)->toBe(0.0)
        ->and($line->adjustment)->toBe(0.0)
        ->and($line->openingUsed)->toBe(0.0)
        ->and($line->used)->toBe(1.0)
        ->and($line->available)->toBe(INF)
        ->and($line->toArray()['remaining'])->toBeNull();
});

it('lists an inactive type that only has pending days that year', function () {
    $e = lbEmployee();
    $retired = LeaveType::factory()->create(['name' => 'Retired', 'is_active' => false, 'sort_order' => 8]);
    leaveRequest($e, ['leave_type_id' => $retired->id, 'from_date' => '2026-12-07', 'to_date' => '2026-12-07', 'days' => 1]);

    $names = array_map(fn (BalanceLine $l): string => $l->typeName, lbSvc()->lines($e, 2026));

    expect($names)->toContain('Retired')
        ->and(array_map(fn (BalanceLine $l): string => $l->typeName, lbSvc()->lines($e, 2025)))->not->toContain('Retired');
});

it('carries nothing with carry forward on and a cap of 0', function () {
    $e = lbEmployee();
    leaveType('Annual', ['carry_forward' => true, 'carry_forward_cap' => 0]);
    lbRow($e, 'Annual', 2025, 14);
    lbRow($e, 'Annual', 2026, 14);

    expect(lbLine($e, 'Annual', 2026)->carried)->toBe(0.0)
        ->and(lbLine($e, 'Annual', 2026)->remaining)->toBe(14.0);
});

it('carries a half day (4.5)', function () {
    $e = lbEmployee();
    lbRow($e, 'Annual', 2025, 14);
    lbRow($e, 'Annual', 2026, 14);
    approvedLeave($e, '2025-09-01', '2025-09-05');      // 5
    approvedLeave($e, '2025-09-08', '2025-09-11');      // 4
    approvedLeave($e, '2025-09-12', null, 'am');        // 0.5 → remaining 4.5

    expect(lbLine($e, 'Annual', 2025)->remaining)->toBe(4.5)
        ->and(lbLine($e, 'Annual', 2026)->carried)->toBe(4.5)
        ->and(lbLine($e, 'Annual', 2026)->remaining)->toBe(18.5);
});

it('gives a leaver the full quota in their final year (no pro-rating on leaving)', function () {
    Settings::set('leave', ['prorate_new_joiners' => true]);
    $e = lbEmployee(['joining_date' => '2024-03-01', 'status' => 'resigned', 'last_working_day' => '2026-03-31']);

    expect(lbSvc()->allocationFor($e, leaveType('Annual'), 2026))->toBe(14.0)
        ->and(lbLine($e, 'Annual', 2026)->allocated)->toBe(14.0);
});

it('counts leave dated before the joining date against the balance', function () {
    Settings::set('leave', ['prorate_new_joiners' => true]);
    $e = lbEmployee(['joining_date' => '2026-07-15']);
    lbSvc()->ensureRow($e, leaveType('Annual'), 2026); // 7
    approvedLeave($e, '2026-07-13', '2026-07-14');       // Mon-Tue before the start date

    $line = lbLine($e, 'Annual', 2026);

    expect($line->used)->toBe(2.0)->and($line->remaining)->toBe(5.0);
});

it('builds lines for many employees in a fixed number of queries', function () {
    $make = function (int $n): array {
        $ids = [];

        for ($i = 0; $i < $n; $i++) {
            $e = lbEmployee();
            shiftFor($e);
            lbRow($e, 'Annual', 2025, 14);
            lbRow($e, 'Annual', 2026, 14);
            lbRow($e, 'Sick', 2026, 8);
            approvedLeave($e, '2025-12-29', '2025-12-30');
            approvedLeave($e, '2026-11-23', '2026-11-24');
            approvedLeave($e, '2026-11-25', null, 'am', 'Unpaid');
            leaveRequest($e, ['from_date' => '2026-12-07', 'to_date' => '2026-12-08', 'days' => 2]);
            leaveRequest($e, ['from_date' => '2026-12-30', 'to_date' => '2027-01-05', 'days' => 5]);
            $ids[] = $e->id;
        }

        return $ids;
    };

    $count = function (array $ids, int $year): int {
        $employees = Employee::query()->whereIn('id', $ids)->get();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = lbSvc()->forEmployees($employees, $year);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($result)->toHaveCount(count($ids));
        $first = $result[$ids[0]][leaveType('Annual')->id];
        expect($first->carried)->toBe(5.0)
            ->and($first->pending)->toBe($year === 2026 ? 4.0 : 3.0);

        return $n;
    };

    $count($make(1), 2026); // warm settings caches
    $two = $make(2);
    $six = $make(6);

    expect($count($six, 2026))->toBe($count($two, 2026))
        ->and($count($six, 2027))->toBe($count($two, 2027));
});
