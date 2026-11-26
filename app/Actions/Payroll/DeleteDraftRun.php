<?php

namespace App\Actions\Payroll;

use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a draft payroll run with its lines and their one-off adjustments (design 5.6 "Review"). An approved or paid
 * run is never deleted.
 *
 *   $delete->handle($hr, $run);
 *
 * payroll.run (the controller checks it too) else AuthorizationException. One transaction, the run row FOR UPDATE first
 * (deleting lines takes no lock on the employee rows); [run] refused unless draft. Audited "Payroll draft deleted"
 * (subject the run; month, headcount, lines).
 */
class DeleteDraftRun
{
    public function handle(User $by, PayrollRun $run): void
    {
        if (! $by->can('payroll.run')) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($by, $run): void {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages(['run' => 'This payroll is approved and cannot be deleted.']);
            }

            $itemIds = PayrollItem::query()->where('payroll_run_id', $locked->id)->pluck('id')->all();

            Audit::record('payroll', 'Payroll draft deleted', $locked, [
                'month' => $locked->month,
                'headcount' => $locked->headcount,
                'lines' => count($itemIds),
            ], $by);

            PayrollAdjustment::query()->whereIn('payroll_item_id', $itemIds)->delete();
            PayrollItem::query()->whereIn('id', $itemIds)->delete();
            $locked->delete();
        }, 3);
    }
}
