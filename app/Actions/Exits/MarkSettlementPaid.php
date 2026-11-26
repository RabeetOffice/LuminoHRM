<?php

namespace App\Actions\Exits;

use App\Models\FinalSettlement;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitRouting;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR marks an approved settlement paid (or settled, when the net is zero or below) with the paid-on date: informational,
 * nothing else changes. exit.clearance and ExitRouting::mayManage (not one's own exit) else 403.
 *
 * Locks: employee FOR UPDATE, resignation FOR UPDATE, settlement FOR UPDATE. Refusals: [settlement] NOT_APPROVED (a
 * draft or already paid); [paid_on] BAD_DATE (not a date, after today, or before the approval day in the company
 * zone). Audited "Settlement marked paid".
 */
class MarkSettlementPaid
{
    public const NOT_APPROVED = 'Only an approved settlement can be marked paid.';

    public const BAD_DATE = 'The paid date cannot be in the future or before the approval.';

    /** @throws AuthorizationException|ValidationException */
    public function handle(User $by, FinalSettlement $settlement, string $paidOn): FinalSettlement
    {
        return DB::transaction(function () use ($by, $settlement, $paidOn): FinalSettlement {
            $employee = ExitGuards::lockEmployee((int) $settlement->employee_id);
            $resignation = ExitGuards::lockResignation((int) $settlement->resignation_id, $employee);
            $locked = FinalSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if (! (new ExitRouting)->mayManage($by, $resignation)) {
                throw new AuthorizationException;
            }

            if ($locked->status !== 'approved') {
                ExitGuards::refuse(self::NOT_APPROVED, 'settlement');
            }

            $clock = app(AttendanceClock::class);
            $approvedOn = $locked->approved_at === null
                ? null
                : CarbonImmutable::instance($locked->approved_at)->setTimezone($clock->zone())->format('Y-m-d');

            if (! ExitGuards::isDate($paidOn) || $paidOn > $clock->attendanceToday() || ($approvedOn !== null && $paidOn < $approvedOn)) {
                ExitGuards::refuse(self::BAD_DATE, 'paid_on');
            }

            $locked->forceFill(['status' => 'paid', 'paid_on' => $paidOn, 'paid_by' => $by->id])->save();

            Audit::record('exit', 'Settlement marked paid', $resignation, [
                ...ExitGuards::context($resignation, $employee),
                'settlement' => $locked->id,
                'month' => $locked->month,
                'paid_on' => $paidOn,
                'net' => (int) $locked->net,
            ], $by);

            return $locked->refresh();
        }, attempts: 3);
    }
}
