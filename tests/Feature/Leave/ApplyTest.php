<?php

use App\Actions\Leave\ApplyForLeave;
use App\Mail\LeaveRequestSubmitted;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveRouting;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Applying for leave, the preview, the attachment and cancelling while open.
| "Now" is 2026-11-24 (a Tuesday) 14:00 in Karachi. The window runs from 2026-10-25 to 2027-12-31. The default shift
| has Saturday and Sunday off. Seeded types: Annual 14 (carry, cap 5), Sick 8, Casual 10, Unpaid. People join on
| 2026-01-01, so every paid type is allocated in full.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
    Storage::fake('local');
});

/** @return array{0: User, 1: Employee} */
function apPerson(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);
    $employee = employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs]);

    return [$user, $employee];
}

function apPayload(array $overrides = []): array
{
    return [
        'leave_type_id' => leaveType('Annual')->id,
        'from_date' => '2026-12-07',
        'to_date' => '2026-12-08',
        'half' => null,
        'reason' => 'Family wedding out of town.',
        ...$overrides,
    ];
}

function apApply(User $user, array $overrides = [])
{
    return test()->actingAs($user)->from('/my/leaves')->post('/leave/requests', apPayload($overrides));
}

function apLatest(): LeaveRequest
{
    return LeaveRequest::query()->latest('id')->firstOrFail();
}

function apPreview(User $user, array $query)
{
    return test()->actingAs($user)->getJson('/leave/requests/preview?'.http_build_query($query));
}

// ---------------------------------------------------------------- counting and storing

it('stores Mon–Fri with a holiday on Wednesday as 4 days, pending, with the balance before', function () {
    [$user, $employee] = apPerson();
    Holiday::query()->create(['name' => 'Founders Day', 'date' => '2026-12-09']);

    apApply($user, ['from_date' => '2026-12-07', 'to_date' => '2026-12-11'])->assertSessionHasNoErrors()->assertRedirect('/my/leaves');

    $r = apLatest();
    expect($r->employee_id)->toBe($employee->id)
        ->and($r->status)->toBe('pending')
        ->and($r->days)->toBe(4.0)
        ->and($r->from_date->format('Y-m-d'))->toBe('2026-12-07')
        ->and($r->to_date->format('Y-m-d'))->toBe('2026-12-11')
        ->and($r->applied_by)->toBe($user->id)
        ->and($r->balance_before)->toBe(['2026' => 14])
        ->and($r->days()->count())->toBe(0); // leave days only at final approval

    $audit = Activity::query()->where('log_name', 'leave')->where('description', 'Leave requested')->sole();
    expect($audit->subject_id)->toBe($employee->id)
        ->and($audit->properties['request'])->toBe($r->id)
        ->and($audit->properties['days'])->toBe(4)
        ->and($audit->properties['type'])->toBe('Annual');
});

it('stores a half day as 0.5 on one date', function () {
    [$user] = apPerson();

    apApply($user, ['from_date' => '2026-12-07', 'to_date' => '2026-12-10', 'half' => 'pm'])->assertSessionHasNoErrors();

    $r = apLatest();
    expect($r->days)->toBe(0.5)->and($r->is_half_day)->toBeTrue()->and($r->half)->toBe('pm')
        ->and($r->to_date->format('Y-m-d'))->toBe('2026-12-07');
});

it('refuses an end before the start, a weekend-only range and bad input', function () {
    [$user] = apPerson();

    apApply($user, ['from_date' => '2026-12-08', 'to_date' => '2026-12-07'])->assertSessionHasErrors('to_date');
    apApply($user, ['from_date' => '2026-12-05', 'to_date' => '2026-12-06'])->assertSessionHasErrors(['from_date' => 'These dates are all weekly offs or holidays.']);
    apApply($user, ['reason' => 'abc'])->assertSessionHasErrors('reason');
    apApply($user, ['half' => 'noon'])->assertSessionHasErrors('half');
    apApply($user, ['leave_type_id' => 99999])->assertSessionHasErrors('leave_type_id');

    expect(LeaveRequest::query()->count())->toBe(0);
});

it('refuses an inactive type', function () {
    [$user] = apPerson();
    $type = leaveType('Garden', ['is_active' => false]);

    apApply($user, ['leave_type_id' => $type->id])->assertSessionHasErrors('leave_type_id');
    expect(LeaveRequest::query()->count())->toBe(0);
});

it('refuses dates outside the employment window', function () {
    [$user] = apPerson('employee', ['joining_date' => '2026-12-09']);

    apApply($user, ['from_date' => '2026-12-07', 'to_date' => '2026-12-08'])
        ->assertSessionHasErrors(['from_date' => "These dates are outside {$user->employee->full_name}'s employment."]);
});

// ---------------------------------------------------------------- the window and the payroll lock

it('accepts 30 days back and refuses 31', function () {
    [$user] = apPerson();

    // 2026-10-24 is a Saturday, 31 days back; 2026-10-26 the Monday after.
    apApply($user, ['from_date' => '2026-10-24', 'to_date' => '2026-10-26'])
        ->assertSessionHasErrors(['from_date' => 'Leave can be requested from 25 Oct 2026 up to 31 Dec 2027.']);
    apApply($user, ['from_date' => '2026-10-25', 'to_date' => '2026-10-26'])->assertSessionHasNoErrors();

    expect(apLatest()->days)->toBe(1.0);
});

it('refuses a date after 31 December of next year', function () {
    [$user] = apPerson();

    apApply($user, ['leave_type_id' => leaveType('Unpaid')->id, 'from_date' => '2027-12-31', 'to_date' => '2028-01-03'])
        ->assertSessionHasErrors('from_date');
});

it('refuses a past day in a locked payroll month', function () {
    [$user] = apPerson();
    app()->bind(ApplyForLeave::class, fn ($app) => new class($app->make(AttendanceClock::class), $app->make(LeaveBalances::class), new LeaveRouting) extends ApplyForLeave
    {
        protected function firstLockedMonth(array $dates): ?string
        {
            return collect($dates)->contains(fn ($d) => str_starts_with($d, '2026-10')) ? '2026-10' : null;
        }
    });

    apApply($user, ['from_date' => '2026-10-26', 'to_date' => '2026-10-27'])
        ->assertSessionHasErrors(['from_date' => 'Payroll for October 2026 is locked, so leave cannot be added in that month.']);
    apApply($user, ['from_date' => '2026-11-02', 'to_date' => '2026-11-03'])->assertSessionHasNoErrors();
});

// ---------------------------------------------------------------- balances

it('refuses a paid type beyond what is available and points to Unpaid; Unpaid is never refused', function () {
    [$user] = apPerson();

    // Casual 10: three working weeks are 15 days.
    apApply($user, ['leave_type_id' => leaveType('Casual')->id, 'from_date' => '2026-12-07', 'to_date' => '2026-12-25'])
        ->assertSessionHasErrors(['days' => 'Not enough Casual leave for 2026: 10 days left, 15 days asked. Apply for the extra days as Unpaid leave.']);

    apApply($user, ['leave_type_id' => leaveType('Unpaid')->id, 'from_date' => '2026-12-07', 'to_date' => '2026-12-25'])->assertSessionHasNoErrors();

    $r = apLatest();
    expect($r->days)->toBe(15.0)->and($r->balance_before)->toBeNull();
    expect(LeaveBalance::query()->where('leave_type_id', leaveType('Unpaid')->id)->count())->toBe(0);
});

it('counts other pending requests: two that each fit alone do not fit together', function () {
    [$user] = apPerson();
    $casual = leaveType('Casual')->id;

    apApply($user, ['leave_type_id' => $casual, 'from_date' => '2026-12-07', 'to_date' => '2026-12-14'])->assertSessionHasNoErrors(); // 6
    apApply($user, ['leave_type_id' => $casual, 'from_date' => '2026-12-15', 'to_date' => '2026-12-22'])
        ->assertSessionHasErrors(['days' => 'Not enough Casual leave for 2026: 4 days left, 6 days asked. Apply for the extra days as Unpaid leave.']);

    expect(LeaveRequest::query()->count())->toBe(1);
});

it('serialises two applications by the same person: the second sees the first', function () {
    [$user, $employee] = apPerson();
    $action = app(ApplyForLeave::class);
    $casual = leaveType('Casual');

    // Sequential simulation of two submits waiting on the employee row lock: whichever runs second sees the first.
    $action->handle($user, $employee, $casual, '2026-12-07', '2026-12-14', null, 'First request here.', null);

    expect(fn () => app(ApplyForLeave::class)->handle($user, $employee, $casual, '2026-12-15', '2026-12-22', null, 'Second request here.', null))
        ->toThrow(ValidationException::class);
    expect(LeaveRequest::query()->count())->toBe(1);
});

it('checks each year of a request across 31 December against that year', function () {
    [$user, $employee] = apPerson();
    $sick = leaveType('Sick');

    // 30, 31 Dec 2026 = 2 days; 1, 4, 5 Jan 2027 = 3 days. 2027 has only 2 Sick days.
    app(LeaveBalances::class)->ensureRow($employee, $sick, 2027)->update(['allocated' => 2]);

    apApply($user, ['leave_type_id' => $sick->id, 'from_date' => '2026-12-30', 'to_date' => '2027-01-05'])
        ->assertSessionHasErrors(['days' => 'Not enough Sick leave for 2027: 2 days left, 3 days asked. Apply for the extra days as Unpaid leave.']);

    LeaveBalance::query()->where('employee_id', $employee->id)->where('leave_type_id', $sick->id)->where('year', 2027)->update(['allocated' => 8]);

    apApply($user, ['leave_type_id' => $sick->id, 'from_date' => '2026-12-30', 'to_date' => '2027-01-05'])->assertSessionHasNoErrors();
    expect(apLatest()->days)->toBe(5.0)->and(apLatest()->balance_before)->toBe(['2026' => 8, '2027' => 8]);
});

// ---------------------------------------------------------------- overlaps

it('lets an am and a pm half on the same date coexist', function () {
    [$user] = apPerson();

    apApply($user, ['from_date' => '2026-12-07', 'half' => 'am'])->assertSessionHasNoErrors();
    apApply($user, ['from_date' => '2026-12-07', 'half' => 'pm'])->assertSessionHasNoErrors();
    apApply($user, ['from_date' => '2026-12-07', 'half' => 'pm'])->assertSessionHasErrors(['from_date' => 'You already have leave on Mon 7 Dec 2026.']);

    expect(LeaveRequest::query()->count())->toBe(2);
});

it('refuses a full day over an approved half day, and a half over an approved full day', function () {
    [$user, $employee] = apPerson();
    approvedLeave($employee, '2026-12-08', half: 'am');
    approvedLeave($employee, '2026-12-10');

    apApply($user, ['from_date' => '2026-12-07', 'to_date' => '2026-12-09'])->assertSessionHasErrors(['from_date' => 'You already have leave on Tue 8 Dec 2026.']);
    apApply($user, ['from_date' => '2026-12-10', 'half' => 'pm'])->assertSessionHasErrors(['from_date' => 'You already have leave on Thu 10 Dec 2026.']);
    apApply($user, ['from_date' => '2026-12-08', 'half' => 'pm'])->assertSessionHasNoErrors();
});

it('refuses an overlap with a pending or manager-approved request, not with a rejected or cancelled one', function () {
    [$user, $employee] = apPerson();
    leaveRequest($employee, ['from_date' => '2026-12-14', 'to_date' => '2026-12-15', 'days' => 2]);
    leaveRequest($employee, ['from_date' => '2026-12-16', 'to_date' => '2026-12-16', 'status' => 'manager_approved']);
    leaveRequest($employee, ['from_date' => '2026-12-17', 'to_date' => '2026-12-18', 'days' => 2, 'status' => 'rejected']);
    leaveRequest($employee, ['from_date' => '2026-12-21', 'to_date' => '2026-12-21', 'status' => 'cancelled']);

    apApply($user, ['from_date' => '2026-12-11', 'to_date' => '2026-12-14'])->assertSessionHasErrors(['from_date' => 'You already have leave on Mon 14 Dec 2026.']);
    apApply($user, ['from_date' => '2026-12-16', 'half' => 'am'])->assertSessionHasErrors('from_date');
    apApply($user, ['from_date' => '2026-12-17', 'to_date' => '2026-12-21'])->assertSessionHasNoErrors();
});

it('ignores another employee\'s leave on the same dates', function () {
    [$user] = apPerson();
    [, $other] = apPerson();
    approvedLeave($other, '2026-12-07');

    apApply($user, ['from_date' => '2026-12-07', 'to_date' => '2026-12-07'])->assertSessionHasNoErrors();
});

// ---------------------------------------------------------------- on behalf

it('lets HR apply on behalf of an employee, recorded as applied by HR', function () {
    [$hr] = apPerson('hr');
    [, $employee] = apPerson();

    apApply($hr, ['employee_id' => $employee->id])->assertSessionHasNoErrors();

    $r = apLatest();
    expect($r->employee_id)->toBe($employee->id)->and($r->applied_by)->toBe($hr->id);
    expect(Activity::query()->where('description', 'Leave requested on behalf')->where('causer_id', $hr->id)->exists())->toBeTrue();
});

it('refuses on behalf by a Manager (even for a report), by HR for an HR person, and for an unknown id', function () {
    [$managerUser, $manager] = apPerson('manager');
    [, $report] = apPerson('employee', ['manager_id' => $manager->id]);
    [$hr] = apPerson('hr');
    [, $otherHr] = apPerson('hr');
    [$admin] = apPerson('admin');

    apApply($managerUser, ['employee_id' => $report->id])->assertForbidden();
    apApply($hr, ['employee_id' => $otherHr->id])->assertForbidden();
    apApply($hr, ['employee_id' => 999999])->assertForbidden();
    expect(LeaveRequest::query()->count())->toBe(0);

    apApply($admin, ['employee_id' => $otherHr->id])->assertSessionHasNoErrors();
    expect(apLatest()->employee_id)->toBe($otherHr->id);
});

it('refuses a user without an employee record applying for themselves', function () {
    $user = userWithRole('employee');

    apApply($user)->assertForbidden();
});

// ---------------------------------------------------------------- attachments

it('stores a PDF attachment privately and serves it to the requester, approvers and HR only', function () {
    [$managerUser, $manager] = apPerson('manager');
    [$user, $employee] = apPerson('employee', ['manager_id' => $manager->id]);
    [$hr] = apPerson('hr');
    [$stranger] = apPerson();
    [$otherManager] = apPerson('manager');

    $pdf = UploadedFile::fake()->createWithContent('medical note.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");
    apApply($user, ['attachment' => $pdf])->assertSessionHasNoErrors();

    $r = apLatest();
    expect($r->attachment_path)->toMatch('#^employees/'.$employee->id.'/leave/[A-Za-z0-9]{40}\.pdf$#')
        ->and($r->attachment_name)->toBe('medical note.pdf');
    Storage::disk('local')->assertExists($r->attachment_path);

    foreach ([$user, $managerUser, $hr] as $viewer) {
        $response = $this->actingAs($viewer)->get("/leave/requests/{$r->id}/attachment")->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and($response->headers->get('Content-Disposition'))->toContain("leave-{$r->id}.pdf");
    }

    $this->actingAs($stranger)->get("/leave/requests/{$r->id}/attachment")->assertForbidden();
    $this->actingAs($otherManager)->get("/leave/requests/{$r->id}/attachment")->assertForbidden();
    $this->actingAs($stranger)->get('/leave/requests/999999/attachment')->assertForbidden();
});

it('checks the attachment by its content: a renamed program is refused and nothing is stored', function () {
    [$user] = apPerson();

    $exe = UploadedFile::fake()->createWithContent('note.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\x00", 64));
    apApply($user, ['attachment' => $exe])->assertSessionHasErrors('attachment');

    $big = UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf');
    apApply($user, ['attachment' => $big])->assertSessionHasErrors('attachment');

    expect(LeaveRequest::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('accepts a PNG attachment and removes the file when the request is refused', function () {
    [$user] = apPerson();

    apApply($user, ['attachment' => UploadedFile::fake()->image('scan.png', 100, 100)])->assertSessionHasNoErrors();
    expect(apLatest()->attachment_path)->toEndWith('.png');

    // Overlaps the first: refused inside the transaction, the second file is gone again.
    apApply($user, ['attachment' => UploadedFile::fake()->image('scan2.png', 100, 100)])->assertSessionHasErrors('from_date');
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
});

// ---------------------------------------------------------------- preview

it('previews the counted days, the balance after, and refusals', function () {
    [$user] = apPerson();
    Holiday::query()->create(['name' => 'Founders Day', 'date' => '2026-12-09']);
    $annual = leaveType('Annual')->id;

    $json = apPreview($user, ['type' => $annual, 'from' => '2026-12-07', 'to' => '2026-12-11'])->assertOk()->json();
    expect($json['total'])->toEqual(4)
        ->and(array_column($json['days'], 'date'))->toBe(['2026-12-07', '2026-12-08', '2026-12-10', '2026-12-11'])
        ->and($json['days'][0]['label'])->toBe('Mon 7 Dec 2026')
        ->and($json['years'])->toEqual([['year' => 2026, 'asked' => 4, 'available' => 14]])
        ->and($json['refusal'])->toBeNull()
        ->and($json['overlap'])->toBeNull();

    $json = apPreview($user, ['type' => $annual, 'from' => '2026-12-05', 'to' => '2026-12-06'])->assertOk()->json();
    expect($json['refusal'])->toBe('These dates are all weekly offs or holidays.');

    $json = apPreview($user, ['type' => leaveType('Unpaid')->id, 'from' => '2026-12-07', 'half' => 'am'])->assertOk()->json();
    expect($json['total'])->toEqual(0.5)->and($json['years'][0]['available'])->toBeNull();

    apApply($user, ['from_date' => '2026-12-07', 'to_date' => '2026-12-07'])->assertSessionHasNoErrors();
    $json = apPreview($user, ['type' => $annual, 'from' => '2026-12-07', 'to' => '2026-12-08'])->assertOk()->json();
    expect($json['overlap'])->toBe('You already have leave on Mon 7 Dec 2026.');
});

it('allows the preview for own record and for HR on behalf, and refuses someone else\'s id (403)', function () {
    [$user] = apPerson();
    [$managerUser, $manager] = apPerson('manager');
    [, $report] = apPerson('employee', ['manager_id' => $manager->id]);
    [$hr] = apPerson('hr');
    $q = ['type' => leaveType('Annual')->id, 'from' => '2026-12-07', 'to' => '2026-12-07'];

    apPreview($user, [...$q, 'employee' => $report->id])->assertForbidden();
    apPreview($managerUser, [...$q, 'employee' => $report->id])->assertForbidden();
    apPreview($user, [...$q, 'employee' => 999999])->assertForbidden();
    apPreview($hr, [...$q, 'employee' => $report->id])->assertOk();
    apPreview(userWithRole('employee'), $q)->assertForbidden();
});

// ---------------------------------------------------------------- cancelling while open

it('lets the employee cancel a pending request, audited', function () {
    [$user, $employee] = apPerson();
    apApply($user)->assertSessionHasNoErrors();
    $r = apLatest();

    $this->actingAs($user)->from('/my/leaves')->post("/leave/requests/{$r->id}/cancel")
        ->assertRedirect('/my/leaves')->assertSessionHas('success');

    $r->refresh();
    expect($r->status)->toBe('cancelled')->and($r->cancelled_by)->toBe($user->id)->and($r->cancelled_at)->not->toBeNull();
    expect(Activity::query()->where('description', 'Leave request cancelled')->where('subject_id', $employee->id)->exists())->toBeTrue();
});

it('refuses cancelling once decided, and another person\'s request (403)', function () {
    [$user, $employee] = apPerson();
    [$stranger] = apPerson();
    [$managerUser, $manager] = apPerson('manager');
    [, $report] = apPerson('employee', ['manager_id' => $manager->id]);

    $approved = approvedLeave($employee, '2026-12-07');
    $this->actingAs($user)->from('/my/leaves')->post("/leave/requests/{$approved->id}/cancel")
        ->assertSessionHas('error', 'This request was already decided.');
    expect($approved->fresh()->status)->toBe('approved');

    $pending = leaveRequest($employee);
    $this->actingAs($stranger)->post("/leave/requests/{$pending->id}/cancel")->assertForbidden();
    $this->actingAs($stranger)->post('/leave/requests/999999/cancel')->assertForbidden();

    // The manager sees a report's request but may not cancel it.
    $reportRequest = leaveRequest($report);
    $this->actingAs($managerUser)->post("/leave/requests/{$reportRequest->id}/cancel")->assertForbidden();
    expect($pending->fresh()->status)->toBe('pending')->and($reportRequest->fresh()->status)->toBe('pending');
});

it('lets HR cancel an open request of an employee', function () {
    [$hr] = apPerson('hr');
    [, $employee] = apPerson();
    $r = leaveRequest($employee, ['status' => 'manager_approved']);

    $this->actingAs($hr)->post("/leave/requests/{$r->id}/cancel")->assertSessionHas('success');
    expect($r->fresh()->status)->toBe('cancelled')->and($r->fresh()->cancelled_by)->toBe($hr->id);
});

// ---------------------------------------------------------------- mail

it('mails the manager when the request waits for them, never the requester or HR', function () {
    [$managerUser, $manager] = apPerson('manager');
    [$user] = apPerson('employee', ['manager_id' => $manager->id]);
    [$hr] = apPerson('hr');

    apApply($user)->assertSessionHasNoErrors();

    Mail::assertQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($managerUser->email) && ! $m->afterManager);
    Mail::assertNotQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($user->email) || $m->hasTo($hr->email));
    Mail::assertQueuedCount(1);
});

it('mails every active final approver when there is no manager step, never the requester or a disabled login', function () {
    [$user] = apPerson();
    [$hr] = apPerson('hr');
    [$admin] = apPerson('admin');
    [$disabledHr] = apPerson('hr');
    $disabledHr->forceFill(['is_active' => false])->save();

    apApply($user)->assertSessionHasNoErrors();

    Mail::assertQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($hr->email));
    Mail::assertQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($admin->email));
    Mail::assertNotQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($user->email) || $m->hasTo($disabledHr->email));
    Mail::assertQueuedCount(2);
});

it('mails only an Admin about an HR person\'s request, and not the HR person applying on behalf', function () {
    [$hrUser] = apPerson('hr');
    [$otherHr] = apPerson('hr');
    [$admin] = apPerson('admin');

    apApply($hrUser)->assertSessionHasNoErrors();
    Mail::assertQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($admin->email));
    Mail::assertNotQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($otherHr->email) || $m->hasTo($hrUser->email));

    [, $employee] = apPerson();
    apApply($otherHr, ['employee_id' => $employee->id, 'from_date' => '2026-12-14', 'to_date' => '2026-12-14'])->assertSessionHasNoErrors();
    Mail::assertNotQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($otherHr->email));
});

it('keeps the request when the mail cannot be queued', function () {
    [$user] = apPerson();
    apPerson('hr');
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Mail is not configured'));

    apApply($user)->assertSessionHasNoErrors()->assertRedirect('/my/leaves');
    expect(LeaveRequest::query()->count())->toBe(1);
});

it('renders the mail with the employee, type, dates, days and reason only', function () {
    [, $employee] = apPerson('employee', ['first_name' => 'Sara', 'last_name' => 'Khan']);
    $r = leaveRequest($employee, ['from_date' => '2026-12-01', 'to_date' => '2026-12-03', 'days' => 3, 'reason' => 'Visiting family abroad.']);

    $html = (new LeaveRequestSubmitted($r, 'Ali', false))->render();
    expect($html)->toContain('Hello Ali')->toContain('Sara Khan')->toContain('Annual')
        ->toContain('Tue 1 Dec 2026 – Thu 3 Dec 2026')->toContain('3 days')->toContain('Visiting family abroad.')
        ->not->toContain('approved by their manager');

    $half = leaveRequest($employee, ['from_date' => '2026-12-07', 'to_date' => '2026-12-07', 'is_half_day' => true, 'half' => 'am']);
    expect((new LeaveRequestSubmitted($half, 'Ali', true))->render())
        ->toContain('Mon 7 Dec 2026, first half')->toContain('0.5 day')->toContain('approved by their manager');
    expect((new LeaveRequestSubmitted($r, 'Ali', false))->envelope()->subject)->toBe('Leave request from Sara Khan');
});
