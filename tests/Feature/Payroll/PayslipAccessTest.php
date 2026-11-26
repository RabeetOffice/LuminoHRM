<?php

use App\Mail\PayslipIssued;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Payroll\PayslipPdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/PayslipFixtures.php';

/*
| Who may see which payslip: the employee their own (approved / paid runs only, never a draft), Admin / HR anyone's;
| a Manager or Employee never reaches Payroll > Payslips. My Payslips lists only the viewer's own approved lines.
*/

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
});

it('lets an employee download their own payslip, inline and never cached', function () {
    $user = psUser('employee', ['employee_code' => 'LD-0007']);
    $item = psItem(psRun(), $user->employee);

    $response = $this->actingAs($user)->get(route('my.payslips.download', $item));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')->toContain('payslip-2026-11-LD-0007.pdf')
        ->and($response->streamedContent())->toStartWith('%PDF');
    Storage::disk('local')->assertExists("payslips/2026-11/LD-0007-{$item->id}.pdf");
});

it('refuses someone else\'s payslip, a draft\'s line and an excluded line on My Payslips', function () {
    $user = psUser('employee');
    $other = psItem(psRun(), Employee::factory()->create());
    $draft = psItem(psRun('2026-10', 'draft'), $user->employee);
    $excluded = psItem(psRun('2026-09'), $user->employee, ['excluded' => true]);

    $this->actingAs($user)->get(route('my.payslips.download', $other))->assertForbidden();
    $this->actingAs($user)->get(route('my.payslips.download', $draft))->assertForbidden();
    $this->actingAs($user)->get(route('my.payslips.download', $excluded))->assertForbidden();
});

it('refuses My Payslips downloads for a user without an employee record', function () {
    $user = userWithRole('admin');
    $item = psItem(psRun(), Employee::factory()->create());

    $this->actingAs($user)->get(route('my.payslips.download', $item))->assertForbidden();
});

it('lists only the viewer\'s own approved and paid payslips, newest first', function () {
    $user = psUser('manager');
    psItem(psRun('2026-10', 'paid'), $user->employee);
    $nov = psItem(psRun('2026-11'), $user->employee);
    psItem(psRun('2026-12', 'draft'), $user->employee);
    psItem(psRun('2026-09'), $user->employee, ['excluded' => true]);
    psItem(PayrollRun::query()->where('month', '2026-11')->first(), Employee::factory()->create(['first_name' => 'Colleague']));

    $this->actingAs($user)->get(route('my.payslips'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my/payslips')
            ->where('employee.name', $user->employee->full_name)
            ->has('payslips', 2)
            ->where('payslips.0.id', $nov->id)
            ->where('payslips.0.label', 'November 2026')
            ->where('payslips.0.net', 'Rs 64,930')
            ->where('payslips.0.gross', 'Rs 90,000')
            ->where('payslips.0.tax', 'Rs 3,570')
            ->where('payslips.1.month', '2026-10'));
});

it('shows an empty My Payslips page without an employee record', function () {
    $this->actingAs(userWithRole('employee'))->get(route('my.payslips'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('my/payslips')->where('employee', null)->has('payslips', 0));
});

it('keeps a Manager and an Employee out of Payroll > Payslips', function (string $role) {
    Mail::fake();
    $user = psUser($role);
    $item = psItem(psRun(), $user->employee); // even their own line

    $this->actingAs($user)->get(route('payroll.payslips.index'))->assertForbidden();
    $this->actingAs($user)->get(route('payroll.payslips.download', $item))->assertForbidden();
    $this->actingAs($user)->post(route('payroll.payslips.resend', $item))->assertForbidden();
    Mail::assertNothingQueued();
})->with(['manager', 'employee']);

it('lets HR and Admin download anyone\'s payslip', function (string $role) {
    $item = psItem(psRun(), psSara());

    $response = $this->actingAs(psUser($role))->get(route('payroll.payslips.download', $item));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with(['hr', 'admin']);

it('returns 404 for a draft\'s or an excluded line on Payroll > Payslips', function () {
    $hr = psUser('hr');
    $draft = psItem(psRun('2026-11', 'draft'), psSara());
    $excluded = psItem(psRun('2026-10'), Employee::factory()->create(), ['excluded' => true]);

    $this->actingAs($hr)->get(route('payroll.payslips.download', $draft))->assertNotFound();
    $this->actingAs($hr)->get(route('payroll.payslips.download', $excluded))->assertNotFound();
    $this->actingAs($hr)->post(route('payroll.payslips.resend', $draft))->assertNotFound();
});

it('re-renders a deleted file on download with the same content', function () {
    $item = psItem(psRun(), psSara());
    $path = PayslipPdf::store($item);
    $text = PayslipPdf::text($item);
    Storage::disk('local')->delete($path);

    $this->actingAs(psUser('hr'))->get(route('payroll.payslips.download', $item))->assertOk();

    Storage::disk('local')->assertExists($path);
    expect(PayslipPdf::text($item->fresh()))->toBe($text);
});

it('lists payslips of approved and paid runs for HR, filtered and paginated', function () {
    $hr = psUser('hr');
    $nov = psRun('2026-11');
    $oct = psRun('2026-10', 'paid');
    $sara = psSara();
    psItem($nov, $sara, ['payslip_generated_at' => now(), 'payslip_emailed_at' => now()]);
    psItem($oct, $sara);
    psItem($nov, Employee::factory()->create(['first_name' => 'Bilal']));
    psItem(psRun('2026-12', 'draft'), $sara);
    psItem($nov, Employee::factory()->create(), ['excluded' => true]);

    $this->actingAs($hr)->get(route('payroll.payslips.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/payslips/index')
            ->has('rows.data', 3)
            ->where('rows.total', 3)
            ->where('rows.data.0.month', '2026-11')
            ->where('rows.data.2.month', '2026-10')
            ->where('months', [['value' => '2026-11', 'label' => 'November 2026'], ['value' => '2026-10', 'label' => 'October 2026']])
            ->where('can_resend', true));

    $this->actingAs($hr)->get(route('payroll.payslips.index', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('filters.month', '2026-10'));

    $this->actingAs($hr)->get(route('payroll.payslips.index', ['search' => 'Sara']))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 2)->where('rows.data.0.employee.name', 'Sara Ahmed')->where('rows.data.0.net', 'Rs 64,930'));

    $this->actingAs($hr)->get(route('payroll.payslips.index', ['month' => '2026-12']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.month', null)->has('rows.data', 3));
});

it('pages the payslip list 25 at a time', function () {
    $run = psRun();
    foreach (Employee::factory()->count(27)->create() as $e) {
        psItem($run, $e);
    }

    $this->actingAs(psUser('admin'))->get(route('payroll.payslips.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 2)->where('rows.total', 27)->where('rows.last_page', 2));
});

it('lets HR resend a payslip: queues the mail regardless of the earlier email and audits it', function () {
    Mail::fake();
    $hr = psUser('hr');
    $sara = psSara();
    User::factory()->create(['employee_id' => $sara->id, 'email' => 'sara@example.com', 'is_active' => true]);
    $item = psItem(psRun(), $sara, ['payslip_emailed_at' => now()->subDay()]);

    $this->actingAs($hr)->from(route('payroll.payslips.index'))
        ->post(route('payroll.payslips.resend', $item))
        ->assertRedirect(route('payroll.payslips.index'))
        ->assertSessionHas('success');

    Mail::assertQueued(PayslipIssued::class, fn (PayslipIssued $m) => $m->hasTo('sara@example.com') && $m->item->is($item));
    $entry = Activity::query()->where('log_name', 'payroll')->where('description', 'Payslip resent')->sole();
    expect($entry->subject_id)->toBe($sara->id)
        ->and($entry->causer_id)->toBe($hr->id)
        ->and($entry->properties['month'])->toBe('2026-11')
        ->and($entry->properties['item'])->toBe($item->id);
});

it('refuses a resend to a person without an active login', function () {
    Mail::fake();
    $item = psItem(psRun(), psSara());

    $this->actingAs(psUser('hr'))->post(route('payroll.payslips.resend', $item))->assertSessionHasErrors('payslip');

    Mail::assertNothingQueued();
    expect(Activity::query()->where('description', 'Payslip resent')->count())->toBe(0);
});
