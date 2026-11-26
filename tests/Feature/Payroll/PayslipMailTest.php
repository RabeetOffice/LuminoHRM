<?php

use App\Events\PayrollRunApproved;
use App\Jobs\Payroll\GeneratePayslip;
use App\Listeners\QueuePayslips;
use App\Mail\PayslipIssued;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/PayslipFixtures.php';

/*
| After approval (PayrollRunApproved): one GeneratePayslip job per line that is not excluded; the job writes the PDF
| and mails it once to the employee's own active login, PDF attached.
*/

beforeEach(function () {
    Storage::fake('local');
});

/** An employee with an active login (email) linked to it. */
function pmWithLogin(array $employee = [], array $user = []): Employee
{
    $e = Employee::factory()->create(['joining_date' => '2024-03-01', ...$employee]);
    User::factory()->create(['employee_id' => $e->id, 'email' => $e->email, 'is_active' => true, ...$user]);

    return $e;
}

it('listens for PayrollRunApproved', function () {
    Event::fake();
    Event::assertListening(PayrollRunApproved::class, QueuePayslips::class);
});

it('queues one job per line that is not excluded', function () {
    Queue::fake();
    $run = psRun();
    $a = psItem($run, pmWithLogin());
    $b = psItem($run, pmWithLogin());
    $excluded = psItem($run, pmWithLogin(), ['excluded' => true, 'excluded_reason' => 'Paid separately']);

    event(new PayrollRunApproved($run->id));

    Queue::assertPushed(GeneratePayslip::class, 2);
    Queue::assertPushed(GeneratePayslip::class, fn (GeneratePayslip $job) => $job->itemId === $a->id);
    Queue::assertPushed(GeneratePayslip::class, fn (GeneratePayslip $job) => $job->itemId === $b->id);
    Queue::assertNotPushed(GeneratePayslip::class, fn (GeneratePayslip $job) => $job->itemId === $excluded->id);
});

it('queues nothing for a draft run', function () {
    Queue::fake();
    $run = psRun('2026-11', 'draft');
    psItem($run, pmWithLogin());

    event(new PayrollRunApproved($run->id));

    Queue::assertNothingPushed();
});

it('stores the PDF and queues one mail with the PDF attached', function () {
    Mail::fake();
    $sara = pmWithLogin(['employee_code' => 'LD-0007', 'first_name' => 'Sara', 'last_name' => 'Ahmed']);
    $item = psItem(psRun(), $sara);

    (new GeneratePayslip($item->id))->handle();

    $item->refresh();
    expect($item->payslip_path)->toBe("payslips/2026-11/LD-0007-{$item->id}.pdf")
        ->and($item->payslip_generated_at)->not->toBeNull()
        ->and($item->payslip_emailed_at)->not->toBeNull();
    expect(Storage::disk('local')->get($item->payslip_path))->toStartWith('%PDF');

    Mail::assertQueued(PayslipIssued::class, 1);
    Mail::assertQueued(PayslipIssued::class, function (PayslipIssued $mail) use ($sara, $item) {
        $attachments = $mail->attachments();

        return $mail->hasTo($sara->email)
            && $mail->item->is($item)
            && count($attachments) === 1
            && $attachments[0]->as === 'payslip-2026-11-LD-0007.pdf'
            && $attachments[0]->mime === 'application/pdf';
    });
});

it('mails only once when the job runs again or the event fires twice', function () {
    Mail::fake();
    $item = psItem(psRun(), pmWithLogin());

    (new GeneratePayslip($item->id))->handle();
    (new GeneratePayslip($item->id))->handle();

    Mail::assertQueued(PayslipIssued::class, 1);
});

it('runs the jobs of a doubled event and still mails each person once', function () {
    Mail::fake();
    $run = psRun();
    psItem($run, pmWithLogin());
    psItem($run, pmWithLogin());

    event(new PayrollRunApproved($run->id)); // the sync queue runs the jobs at once
    event(new PayrollRunApproved($run->id));

    Mail::assertQueued(PayslipIssued::class, 2);
});

it('sends no mail to an inactive login, a login without an email or a person without a login', function (string $case) {
    Mail::fake();
    $employee = match ($case) {
        'inactive' => pmWithLogin([], ['is_active' => false]),
        'no email' => pmWithLogin([], ['email' => '']),
        'no login' => Employee::factory()->create(),
    };
    $item = psItem(psRun(), $employee);

    (new GeneratePayslip($item->id))->handle();

    Mail::assertNothingQueued();
    expect($item->refresh()->payslip_path)->not->toBeNull()
        ->and($item->payslip_emailed_at)->toBeNull();
})->with(['inactive', 'no email', 'no login']);

it('skips an excluded line and a draft run', function () {
    Mail::fake();
    $excluded = psItem(psRun(), pmWithLogin(), ['excluded' => true]);
    $draft = psItem(psRun('2026-10', 'draft'), pmWithLogin());

    (new GeneratePayslip($excluded->id))->handle();
    (new GeneratePayslip($draft->id))->handle();

    Mail::assertNothingQueued();
    expect(PayrollItem::query()->whereNotNull('payslip_path')->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('builds the mail with the person\'s own net pay and no one else\'s data', function () {
    $run = psRun();
    $sara = pmWithLogin(['employee_code' => 'LD-0007', 'first_name' => 'Sara_*', 'last_name' => 'Ahmed']);
    $other = pmWithLogin(['first_name' => 'Bilal', 'last_name' => 'Khan']);
    $item = psItem($run, $sara);
    psItem($run, $other, ['net' => 1234500, 'breakdown' => psBreakdown($other, '2026-11', ['totals' => ['net' => 1234500]])]);

    $mail = new PayslipIssued($item);
    $html = $mail->render();

    expect($mail->envelope()->subject)->toBe('Your payslip for November 2026')
        ->and($html)->toContain('Rs 64,930')
        ->toContain('Sara_*')
        ->toContain(route('my.payslips'))
        ->not->toContain('Bilal')
        ->not->toContain('12,345')
        ->not->toContain('<em>');
});
