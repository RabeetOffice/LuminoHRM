<?php

namespace App\Support\Payroll;

use App\Models\PayrollRun;
use App\Support\Attendance\AttendanceClock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The dashboard's payroll figures, one query each:
 *
 *   thisMonth()      the "Payroll This Month" tile (payroll.view only, the controller decides): the run of the pay
 *                    month = the calendar month before the month of AttendanceClock::attendanceToday() (it is paid in
 *                    the first days of the current month). Status 'none' when no run exists for it.
 *   latestPayslip()  the viewer's own latest line of an approved or paid run (never a draft, never an excluded line,
 *                    which gets no payslip), or null.
 *
 * Links are built only when their route exists (the runs and My Payslips screens land in other tasks); otherwise null.
 */
final class DashboardPayroll
{
    /**
     * @return array{month: string, label: string, status: string, headcount: int, net: int, run_id: int|null, url: string|null}
     */
    public static function thisMonth(AttendanceClock $clock): array
    {
        $month = self::payMonth($clock->attendanceToday());
        $run = PayrollRun::query()->where('month', $month)->first(['id', 'month', 'status', 'headcount', 'net']);

        $url = null;
        if ($run !== null && Route::has('payroll.runs.show')) {
            $url = route('payroll.runs.show', ['run' => $run->id], false);
        } elseif (Route::has('payroll.runs.index')) {
            $url = route('payroll.runs.index', [], false);
        }

        return [
            'month' => $month,
            'label' => PayrollRun::monthLabel($month),
            'status' => $run === null ? 'none' : (string) $run->status,
            'headcount' => $run === null ? 0 : (int) $run->headcount,
            'net' => $run === null ? 0 : (int) $run->net,
            'run_id' => $run?->id,
            'url' => $url,
        ];
    }

    /**
     * @return array{month: string, label: string, net: int, download_url: string|null}|null
     */
    public static function latestPayslip(int $employeeId): ?array
    {
        $item = DB::table('payroll_items')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
            ->where('payroll_items.employee_id', $employeeId)
            ->where('payroll_items.excluded', false)
            ->whereIn('payroll_runs.status', (array) config('hrm.payroll.locked_statuses'))
            ->orderByDesc('payroll_runs.month')
            ->first(['payroll_items.id', 'payroll_items.net', 'payroll_runs.month']);

        if ($item === null) {
            return null;
        }

        $month = (string) $item->month;

        return [
            'month' => $month,
            'label' => PayrollRun::monthLabel($month),
            'net' => (int) $item->net,
            'download_url' => Route::has('my.payslips.download')
                ? route('my.payslips.download', ['item' => (int) $item->id], false)
                : null,
        ];
    }

    /** "2026-11" for any day of December 2026; "2026-12" for a day of January 2027. */
    public static function payMonth(string $today): string
    {
        $first = new DateTimeImmutable(substr($today, 0, 7).'-01 00:00:00', new DateTimeZone('UTC'));

        return $first->modify('-1 month')->format('Y-m');
    }
}
