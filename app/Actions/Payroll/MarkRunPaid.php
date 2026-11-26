<?php

namespace App\Actions\Payroll;

use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use App\Support\Payroll\PayrollRoster;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks an approved payroll run paid with the payment date (design 5.6; informational, nothing else changes).
 *
 *   $markPaid->handle($hr, $run, '2026-12-02');
 *
 * payroll.run (the controller checks it too) else AuthorizationException. One transaction, the run row FOR UPDATE first.
 * Refusals: [run] not approved (a draft, or already paid); [paid_on] not a "Y-m-d" date, before the run month's first
 * day, or after today (AttendanceClock::attendanceToday()). Audited "Payroll marked paid" (subject the run; paid_on).
 */
class MarkRunPaid
{
    public function handle(User $by, PayrollRun $run, string $paidOn): void
    {
        if (! $by->can('payroll.run')) {
            throw new AuthorizationException;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn);
        if ($date === false || $date->format('Y-m-d') !== $paidOn) {
            throw ValidationException::withMessages(['paid_on' => 'Enter the payment date.']);
        }

        DB::transaction(function () use ($by, $run, $paidOn): void {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'paid') {
                throw ValidationException::withMessages(['run' => 'This payroll is already marked paid.']);
            }

            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['run' => 'Approve this payroll before marking it paid.']);
            }

            [$first] = PayrollRoster::bounds($locked->month);
            if ($paidOn < $first) {
                throw ValidationException::withMessages(['paid_on' => 'The payment date cannot be before '
                    .(new DateTimeImmutable($first))->format('j M Y').'.']);
            }

            if ($paidOn > app(AttendanceClock::class)->attendanceToday()) {
                throw ValidationException::withMessages(['paid_on' => 'The payment date cannot be in the future.']);
            }

            $locked->forceFill([
                'status' => 'paid',
                'paid_by' => $by->id,
                'paid_at' => now(),
                'paid_on' => $paidOn,
            ])->save();

            Audit::record('payroll', 'Payroll marked paid', $locked, [
                'month' => $locked->month,
                'paid_on' => $paidOn,
            ], $by);
        }, 3);
    }
}
