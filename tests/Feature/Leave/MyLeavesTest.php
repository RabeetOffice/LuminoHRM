<?php

use App\Support\Settings;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| My Leaves: own balances and requests only. "Now" is 2026-11-24 14:00 in Karachi.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

it('shows a friendly empty page to a user without an employee record', function () {
    $this->actingAs(userWithRole('employee'))->get('/my/leaves')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('my/leaves')
            ->where('employee', null)
            ->where('balances', [])
            ->where('requests.data', [])
            ->where('year', 2026)
            ->where('window', ['min' => '2026-10-25', 'max' => '2027-12-31'])
            ->where('today', '2026-11-24'));
});

it('shows own balances (unpaid unlimited), active types and own requests only, newest first', function () {
    $managerUser = userWithRole('manager');
    $manager = employeeFor($managerUser, ['first_name' => 'Ahmed', 'joining_date' => '2026-01-01']);
    $user = userWithRole('employee');
    $me = employeeFor($user, ['joining_date' => '2026-01-01', 'manager_id' => $manager->id]);
    $other = employeeFor(userWithRole('employee'), ['joining_date' => '2026-01-01']);
    leaveType('Garden', ['is_active' => false]);

    $approved = approvedLeave($me, '2026-11-02', '2026-11-03');
    $pending = leaveRequest($me, ['from_date' => '2026-12-07', 'to_date' => '2026-12-07', 'is_half_day' => true, 'half' => 'pm']);
    leaveRequest($other, ['from_date' => '2026-12-08', 'to_date' => '2026-12-08']);

    $this->actingAs($user)->get('/my/leaves')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('my/leaves')
            ->where('employee.name', $me->full_name)
            ->has('requests.data', 2)
            ->where('requests.data.0.id', $pending->id)
            ->where('requests.data.0.dates', 'Mon 7 Dec 2026, second half')
            ->where('requests.data.0.days', 0.5)
            ->where('requests.data.0.status', 'pending')
            ->where('requests.data.0.stage', 'manager')
            ->where('requests.data.0.waiting_for', 'Ahmed')
            ->where('requests.data.0.can_cancel', true)
            ->where('requests.data.0.has_attachment', false)
            ->where('requests.data.1.id', $approved->id)
            ->where('requests.data.1.can_cancel', false)
            ->where('requests.data.1.stage', null)
            ->where('types', fn ($types) => collect($types)->pluck('name')->all() === ['Annual', 'Sick', 'Casual', 'Unpaid'])
            ->where('balances', function ($lines) {
                $byName = collect($lines)->keyBy('type_name');

                return $byName['Annual']['remaining'] == 12 && $byName['Annual']['used'] == 2
                    && $byName['Annual']['pending'] == 0.5
                    && $byName['Unpaid']['unlimited'] === true && $byName['Unpaid']['remaining'] === null;
            }));
});

it('shows "Waiting for HR" data when there is no manager step and picks the year from the query', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user, ['joining_date' => '2026-01-01']);
    leaveRequest($me);

    $this->actingAs($user)->get('/my/leaves?year=2027')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('year', 2027)
            ->where('years', [2026, 2027])
            ->where('requests.data.0.stage', 'final')
            ->where('requests.data.0.waiting_for', null));

    $this->actingAs($user)->get('/my/leaves?year=1999')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('year', 2026));
});

it('needs a login', function () {
    $this->get('/my/leaves')->assertRedirect('/login');
});
