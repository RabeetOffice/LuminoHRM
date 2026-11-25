<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\Attendance\RebuildMarks;
use App\Support\Audit\Audit;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

/*
| "Now" is 2026-11-24 (Tuesday) 14:00 in Karachi: the attendance day is 2026-11-24. Attendance started 2026-01-01.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** Re-runs the default leave types data migration, as a second `migrate` would if it were not recorded. */
function lsRunSeedMigration(): void
{
    (require database_path('migrations/2026_11_25_100100_seed_default_leave_types.php'))->up();
}

// ---------------------------------------------------------------- schema

it('creates the four leave tables and attendances.leave_half', function () {
    $tables = [
        'leave_types' => ['id', 'name', 'yearly_quota', 'is_paid', 'carry_forward', 'carry_forward_cap', 'is_active', 'is_system', 'colour', 'sort_order', 'created_at', 'updated_at'],
        'leave_balances' => ['id', 'employee_id', 'leave_type_id', 'year', 'allocated', 'adjustment', 'opening_used', 'created_at', 'updated_at'],
        'leave_requests' => [
            'id', 'employee_id', 'leave_type_id', 'from_date', 'to_date', 'is_half_day', 'half', 'days', 'reason',
            'attachment_path', 'attachment_name', 'balance_before', 'status', 'applied_by', 'manager_decided_by',
            'manager_decided_at', 'manager_skipped', 'hr_decided_by', 'hr_decided_at', 'decision_note', 'cancelled_by',
            'cancelled_at', 'cancel_reason', 'cancelled_from', 'created_at', 'updated_at',
        ],
        'leave_days' => ['id', 'leave_request_id', 'employee_id', 'leave_type_id', 'date', 'amount', 'half', 'created_at'],
    ];

    foreach ($tables as $table => $columns) {
        expect(Schema::getColumnListing($table))->toEqualCanonicalizing($columns);
    }

    expect(Schema::hasColumn('attendances', 'leave_half'))->toBeTrue();
});

it('rolls the schema migration back and forward again', function () {
    $migration = require database_path('migrations/2026_11_25_100000_create_leave_tables.php');

    $migration->down();

    foreach (['leave_types', 'leave_balances', 'leave_requests', 'leave_days'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasColumn('attendances', 'leave_half'))->toBeFalse();

    $migration->up();
    lsRunSeedMigration();

    expect(Schema::hasTable('leave_days'))->toBeTrue()
        ->and(Schema::hasColumn('attendances', 'leave_half'))->toBeTrue()
        ->and(LeaveType::query()->count())->toBe(4);
});

// ---------------------------------------------------------------- seeded types

it('seeds the four default types with the spec values', function () {
    $types = LeaveType::query()->orderBy('sort_order')->get();

    expect($types->pluck('name')->all())->toBe(['Annual', 'Sick', 'Casual', 'Unpaid']);

    $annual = $types->firstWhere('name', 'Annual');
    expect($annual->yearly_quota)->toBe(14.0)
        ->and($annual->is_paid)->toBeTrue()
        ->and($annual->carry_forward)->toBeTrue()
        ->and($annual->carry_forward_cap)->toBe(5.0)
        ->and($annual->colour)->toBe('emerald')
        ->and($annual->is_active)->toBeTrue()
        ->and($annual->is_system)->toBeFalse();

    $sick = $types->firstWhere('name', 'Sick');
    expect([$sick->yearly_quota, $sick->is_paid, $sick->carry_forward, $sick->carry_forward_cap, $sick->colour])
        ->toBe([8.0, true, false, 0.0, 'rose']);

    $casual = $types->firstWhere('name', 'Casual');
    expect([$casual->yearly_quota, $casual->is_paid, $casual->carry_forward, $casual->colour])
        ->toBe([10.0, true, false, 'sky']);

    $unpaid = $types->firstWhere('name', 'Unpaid');
    expect([$unpaid->yearly_quota, $unpaid->is_paid, $unpaid->carry_forward, $unpaid->is_system, $unpaid->colour])
        ->toBe([0.0, false, false, true, 'slate'])
        ->and($unpaid->isUnpaid())->toBeTrue()
        ->and($annual->isUnpaid())->toBeFalse();

    // Seeding is a system write: no audit entry.
    expect(Activity::query()->where('log_name', 'leave')->count())->toBe(0);
});

it('never duplicates or overwrites the types when the seed runs again', function () {
    LeaveType::query()->where('name', 'Annual')->firstOrFail()->update(['yearly_quota' => 18, 'name' => 'Annual Leave']);
    LeaveType::query()->where('name', 'Casual')->firstOrFail()->delete();

    lsRunSeedMigration();
    lsRunSeedMigration();

    expect(LeaveType::query()->pluck('name')->sort()->values()->all())->toBe(['Annual Leave', 'Sick', 'Unpaid'])
        ->and(LeaveType::query()->where('name', 'Annual Leave')->value('yearly_quota'))->toEqual(18);
});

it('seeds the defaults into an empty table', function () {
    DB::table('leave_types')->delete();

    lsRunSeedMigration();

    expect(LeaveType::query()->count())->toBe(4);
});

// ---------------------------------------------------------------- unique keys

it('allows one balance row per employee, type and year', function () {
    $employee = Employee::factory()->create();
    $annual = leaveType('Annual');

    LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => 2026]);
    LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => 2027]);
    LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => leaveType('Sick')->id, 'year' => 2026]);

    expect(fn () => LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => 2026]))
        ->toThrow(QueryException::class);
});

it('allows one leave day per employee, date and half; an am and a pm half may share a date', function () {
    $employee = Employee::factory()->create();
    $first = approvedLeave($employee, '2026-12-01', null, 'am');
    $second = approvedLeave($employee, '2026-12-01', null, 'pm');

    expect(LeaveDay::query()->where('employee_id', $employee->id)->onDate('2026-12-01')->count())->toBe(2);

    expect(fn () => LeaveDay::query()->create([
        'leave_request_id' => $second->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $first->leave_type_id,
        'date' => '2026-12-01',
        'amount' => 0.5,
        'half' => 'am',
    ]))->toThrow(QueryException::class);
});

it('stores a leave name only once', function () {
    expect(fn () => LeaveType::factory()->create(['name' => 'Sick']))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------- models

it('casts a request and its days', function () {
    $employee = Employee::factory()->create();
    $request = approvedLeave($employee, '2026-12-01', '2026-12-03');

    $request->refresh();

    expect($request->from_date->format('Y-m-d'))->toBe('2026-12-01')
        ->and($request->to_date->format('Y-m-d'))->toBe('2026-12-03')
        ->and($request->days)->toBe(3.0)
        ->and($request->status)->toBe('approved')
        ->and($request->isOpen())->toBeFalse()
        ->and($request->isPartlyCancelled())->toBeFalse()
        ->and($request->days()->count())->toBe(3)
        ->and($request->type->name)->toBe('Annual')
        ->and($request->employee->is($employee))->toBeTrue();

    $day = $request->days()->orderBy('date')->first();
    expect($day->date->format('Y-m-d'))->toBe('2026-12-01')
        ->and($day->amount)->toBe(1.0)
        ->and($day->half)->toBe('full')
        ->and($day->updated_at ?? null)->toBeNull();

    expect($employee->leaveRequests()->count())->toBe(1)
        ->and($employee->leaveDays()->count())->toBe(3);

    $request->update(['cancelled_from' => '2026-12-02']);
    expect($request->fresh()->isPartlyCancelled())->toBeTrue();
});

it('keeps balance_before as an array and halves as floats', function () {
    $employee = Employee::factory()->create();
    $request = leaveRequest($employee, ['balance_before' => ['2026' => 9.5], 'is_half_day' => true, 'half' => 'am', 'days' => 0.5]);

    $request->refresh();

    expect($request->balance_before)->toBe(['2026' => 9.5])
        ->and($request->days)->toBe(0.5)
        ->and($request->is_half_day)->toBeTrue()
        ->and($request->isOpen())->toBeTrue();

    $balance = LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => leaveType()->id, 'allocated' => 7.5, 'adjustment' => -1.5]);
    expect($balance->fresh()->allocated)->toBe(7.5)
        ->and($balance->fresh()->adjustment)->toBe(-1.5)
        ->and($balance->fresh()->opening_used)->toBe(0.0)
        ->and($employee->leaveBalances()->count())->toBe(1);
});

it('refuses an unknown request status, half or colour', function () {
    $employee = Employee::factory()->create();

    expect(fn () => leaveRequest($employee, ['status' => 'waiting']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => leaveRequest($employee, ['is_half_day' => true, 'half' => 'noon']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => LeaveType::factory()->create(['colour' => 'pink']))->toThrow(InvalidArgumentException::class);
});

it('never makes the system unpaid type paid and never deletes it', function () {
    $unpaid = leaveType('Unpaid');

    expect(fn () => $unpaid->update(['is_paid' => true]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $unpaid->delete())->toThrow(InvalidArgumentException::class)
        ->and(LeaveType::query()->whereKey($unpaid->id)->value('is_paid'))->toBeFalsy();
});

it('keeps is_system out of mass assignment and never changes it on an existing type', function () {
    $unpaid = leaveType('Unpaid');

    // is_system is ignored by update(); is_paid alone would make the system type paid: refused.
    expect(fn () => $unpaid->update(['is_system' => false, 'is_paid' => true]))->toThrow(InvalidArgumentException::class);

    $fresh = $unpaid->fresh();
    expect($fresh->is_system)->toBeTrue()->and($fresh->is_paid)->toBeFalse();

    // Forced past mass assignment: still refused, either way round.
    expect(fn () => $fresh->forceFill(['is_system' => false])->save())->toThrow(InvalidArgumentException::class, 'system flag')
        ->and(fn () => leaveType('Sick')->forceFill(['is_system' => true])->save())->toThrow(InvalidArgumentException::class, 'system flag')
        ->and(LeaveType::query()->where('is_system', true)->pluck('name')->all())->toBe(['Unpaid']);

    // create() ignores it too; the factory may set it (it writes past mass assignment).
    $made = LeaveType::query()->create(['name' => 'Study', 'colour' => 'sky', 'is_system' => true]);
    expect($made->fresh()->is_system)->toBeFalse()
        ->and(LeaveType::factory()->unpaid()->create(['is_system' => true])->fresh()->is_system)->toBeTrue();
});

it('refuses to deactivate the system unpaid type', function () {
    $unpaid = leaveType('Unpaid');

    expect(fn () => $unpaid->update(['is_active' => false]))
        ->toThrow(InvalidArgumentException::class, 'Unpaid leave must stay active; it is what staff use when their balance runs out.')
        ->and(LeaveType::query()->whereKey($unpaid->id)->value('is_active'))->toBeTruthy();

    // Other changes to it still save.
    $unpaid->fresh()->update(['name' => 'Leave without pay', 'colour' => 'amber']);
    expect($unpaid->fresh()->name)->toBe('Leave without pay');
});

it('stores dates assigned as Carbon objects as plain Y-m-d strings', function () {
    $employee = Employee::factory()->create();
    $request = leaveRequest($employee, [
        'from_date' => CarbonImmutable::parse('2026-12-01 15:30:00'),
        'to_date' => CarbonImmutable::parse('2026-12-03'),
        'status' => 'approved',
    ]);
    $request->update(['cancelled_from' => CarbonImmutable::parse('2026-12-02 09:00:00')]);

    $raw = DB::table('leave_requests')->where('id', $request->id)->first(['from_date', 'to_date', 'cancelled_from']);

    expect([$raw->from_date, $raw->to_date, $raw->cancelled_from])->toBe(['2026-12-01', '2026-12-03', '2026-12-02'])
        ->and(LeaveRequest::query()->where('from_date', '<=', '2026-12-01')->where('to_date', '>=', '2026-12-03')->pluck('id')->all())->toBe([$request->id])
        ->and(LeaveRequest::query()->where('cancelled_from', '2026-12-02')->exists())->toBeTrue()
        ->and($request->fresh()->from_date->format('Y-m-d'))->toBe('2026-12-01');
});

it('refuses a half day that is not one date, or half and is_half_day that disagree', function () {
    $employee = Employee::factory()->create();

    expect(fn () => leaveRequest($employee, ['is_half_day' => true, 'half' => null]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => leaveRequest($employee, ['is_half_day' => false, 'half' => 'am']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => leaveRequest($employee, ['is_half_day' => true, 'half' => 'pm', 'from_date' => '2026-12-01', 'to_date' => '2026-12-02']))->toThrow(InvalidArgumentException::class)
        ->and(LeaveRequest::query()->count())->toBe(0);

    $ok = leaveRequest($employee, ['is_half_day' => true, 'half' => 'pm', 'from_date' => '2026-12-01', 'to_date' => '2026-12-01']);
    expect($ok->exists)->toBeTrue();
});

it('audits leave type changes in the leave area', function () {
    $type = LeaveType::factory()->create(['name' => 'Study', 'yearly_quota' => 3]);
    $type->update(['yearly_quota' => 4.5]);
    $type->delete();

    $entries = Activity::query()->where('log_name', 'leave')->orderBy('id')->get();

    expect($entries->pluck('description')->all())->toBe(['Leave type created', 'Leave type updated', 'Leave type deleted'])
        ->and($entries[1]->properties['old']['yearly_quota'])->toEqual(3)
        ->and($entries[1]->properties['attributes']['yearly_quota'])->toEqual(4.5)
        ->and(array_keys($entries[0]->properties['attributes']))->not->toContain('created_at')
        ->and(Audit::AREAS)->toHaveKey('leave');
});

it('lists only active types with the active scope', function () {
    leaveType('Casual')->update(['is_active' => false]);

    expect(LeaveType::query()->active()->pluck('name')->all())->not->toContain('Casual')
        ->and(LeaveType::query()->active()->count())->toBe(3);
});

it('declares the leave enums in config', function () {
    expect(config('hrm.leave.statuses'))->toBe(['pending', 'manager_approved', 'approved', 'rejected', 'cancelled'])
        ->and(config('hrm.leave.open_statuses'))->toBe(['pending', 'manager_approved'])
        ->and(config('hrm.leave.halves'))->toBe(['am', 'pm'])
        ->and(config('hrm.leave.colours'))->toBe(['emerald', 'sky', 'amber', 'rose', 'violet', 'teal', 'orange', 'slate']);
});

// ---------------------------------------------------------------- RebuildMarks::forLeaveDays

it('flags a closed non-manual day, creates a bare row for a past day without one, skips manual and future days', function () {
    $employee = Employee::factory()->create(['joining_date' => '2026-01-01']);
    $other = Employee::factory()->create(['joining_date' => '2026-01-01']);

    $closed = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-20', 'closed' => true]);
    $manual = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-19', 'closed' => true, 'is_manual' => true]);
    $open = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-24']);
    $othersDay = Attendance::factory()->create(['employee_id' => $other->id, 'date' => '2026-11-20', 'closed' => true]);

    $count = RebuildMarks::forLeaveDays($employee->id, ['2026-11-19', '2026-11-20', '2026-11-23', '2026-11-24', '2026-11-25', '2026-11-20']);

    // 2026-11-20 and 2026-11-24 flagged, 2026-11-23 created; 2026-11-19 manual and 2026-11-25 future skipped.
    expect($count)->toBe(3)
        ->and($closed->fresh()->needs_rebuild)->toBeTrue()
        ->and($open->fresh()->needs_rebuild)->toBeTrue()
        ->and($manual->fresh()->needs_rebuild)->toBeFalse()
        ->and($othersDay->fresh()->needs_rebuild)->toBeFalse()
        ->and(Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-25')->exists())->toBeFalse()
        ->and(Attendance::query()->where('employee_id', $other->id)->count())->toBe(1);

    $bare = Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-23')->firstOrFail();
    expect($bare->status)->toBeNull()
        ->and($bare->needs_rebuild)->toBeTrue()
        ->and($bare->is_manual)->toBeFalse()
        ->and($bare->closed)->toBeFalse();
});

it('returns zero for nothing to do and does not count a day already flagged twice', function () {
    $employee = Employee::factory()->create(['joining_date' => '2026-01-01']);
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-20', 'closed' => true, 'needs_rebuild' => true]);

    expect(RebuildMarks::forLeaveDays($employee->id, []))->toBe(0)
        ->and(RebuildMarks::forLeaveDays($employee->id, ['2026-12-01']))->toBe(0)
        ->and(RebuildMarks::forLeaveDays($employee->id, ['2026-11-20']))->toBe(0)
        ->and(Attendance::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('touches nothing before the attendance start date', function () {
    $employee = Employee::factory()->create(['joining_date' => '2025-01-01']);
    Settings::set('attendance', ['start_date' => '2026-11-01']);

    // An existing row before the start date is not flagged; no bare row is made there either.
    $early = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-10-29', 'closed' => true]);
    $onStart = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-01', 'closed' => true]);

    expect(RebuildMarks::forLeaveDays($employee->id, ['2026-10-29', '2026-10-30', '2026-11-01', '2026-11-02']))->toBe(2)
        ->and($early->fresh()->needs_rebuild)->toBeFalse()
        ->and($onStart->fresh()->needs_rebuild)->toBeTrue()
        ->and(Attendance::query()->where('employee_id', $employee->id)->orderBy('date')->pluck('date')->map->format('Y-m-d')->all())
        ->toBe(['2026-10-29', '2026-11-01', '2026-11-02']);
});

it('does nothing at all while attendance has not started', function () {
    $employee = Employee::factory()->create(['joining_date' => '2025-01-01']);
    $row = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-20', 'closed' => true]);

    Settings::set('attendance', ['start_date' => null]);

    expect(RebuildMarks::forLeaveDays($employee->id, ['2026-11-20', '2026-11-21']))->toBe(0)
        ->and($row->fresh()->needs_rebuild)->toBeFalse()
        ->and(Attendance::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('rejects a malformed date', function () {
    $employee = Employee::factory()->create();

    RebuildMarks::forLeaveDays($employee->id, ['2026-11-31']);
})->throws(InvalidArgumentException::class);

it('stores leave_half on an attendance row', function () {
    $row = Attendance::factory()->create(['leave_half' => 'am']);

    expect($row->fresh()->leave_half)->toBe('am');
});
