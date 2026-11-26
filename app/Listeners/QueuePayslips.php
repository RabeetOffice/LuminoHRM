<?php

namespace App\Listeners;

use App\Events\PayrollRunApproved;
use App\Jobs\Payroll\GeneratePayslip;
use App\Models\PayrollRun;

/**
 * After a run is approved: one GeneratePayslip job per line that is not excluded (excluded lines get no payslip).
 * Does nothing for a run that is missing or not approved / paid. Firing twice queues the jobs twice; each job mails
 * at most once per line (GeneratePayslip).
 */
class QueuePayslips
{
    public function handle(PayrollRunApproved $event): void
    {
        $run = PayrollRun::query()->find($event->runId);

        if ($run === null || ! $run->isLocked()) {
            return;
        }

        $run->items()->where('excluded', false)->orderBy('id')->pluck('id')
            ->each(fn (int $id) => GeneratePayslip::dispatch($id));
    }
}
