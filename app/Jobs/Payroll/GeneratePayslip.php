<?php

namespace App\Jobs\Payroll;

use App\Mail\PayslipIssued;
use App\Models\PayrollItem;
use App\Models\User;
use App\Support\Payroll\PayslipPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Writes one payslip PDF and emails it once. Queued by QueuePayslips after a run is approved.
 *
 * Skips an excluded line and a run that is not approved / paid (never a draft). The file is written from the frozen
 * data (PayslipPdf::pathOrRender: an existing file is kept, a missing one rendered again identically). The mail goes
 * only to the employee's own login when it is active and has an email, and only once: payslip_emailed_at is claimed
 * with one conditional update (set where still null), so a second job for the same line (the event fired twice, a
 * retry) mails nothing. If queueing the mail fails, the claim is released and the job retries.
 */
class GeneratePayslip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $itemId) {}

    public function handle(): void
    {
        $item = PayrollItem::query()->with('run')->find($this->itemId);

        if ($item === null || $item->excluded || $item->run === null || ! $item->run->isLocked()) {
            return;
        }

        PayslipPdf::pathOrRender($item);

        $user = User::query()
            ->where('employee_id', $item->employee_id)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->first();

        if ($user === null) {
            return;
        }

        $claimed = PayrollItem::query()->whereKey($item->id)->whereNull('payslip_emailed_at')->update(['payslip_emailed_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        try {
            Mail::to($user)->queue(new PayslipIssued($item->refresh()));
        } catch (Throwable $e) {
            PayrollItem::query()->whereKey($item->id)->update(['payslip_emailed_at' => null]);

            throw $e;
        }
    }
}
