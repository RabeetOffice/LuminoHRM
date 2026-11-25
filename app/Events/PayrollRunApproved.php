<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payroll run was approved (dispatched after the approval transaction commits). Listeners generate and email the
 * payslips. Carries only the run id: listeners read the frozen run and lines themselves.
 *
 *   PayrollRunApproved::dispatch($run->id);
 */
final class PayrollRunApproved
{
    use Dispatchable;

    public function __construct(public readonly int $runId) {}
}
