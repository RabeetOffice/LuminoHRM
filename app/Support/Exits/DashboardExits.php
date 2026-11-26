<?php

namespace App\Support\Exits;

use App\Models\Resignation;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's exit cards. Names and counts only: no reason, no settlement figure, nothing about clearance.
 *
 *   waiting()   "Exits waiting for you": the count and up to five links, for whoever has something waiting (ExitsWaiting)
 *   upcoming()  "Upcoming leavers": approved exits whose last working day falls from today to $days ahead, for exit.clearance
 */
final class DashboardExits
{
    /** How many names the cards list. */
    public const NAMES = 5;

    /** @return array{count: int, items: list<array{id: int, label: string, url: string}>} */
    public static function waiting(User $user): array
    {
        $ids = ExitsWaiting::ids($user);

        if ($ids === []) {
            return ['count' => 0, 'items' => []];
        }

        $rows = Resignation::query()
            ->with('employee:id,first_name,last_name')
            ->whereKey($ids)
            ->orderBy('last_working_day')
            ->orderBy('id')
            ->limit(self::NAMES)
            ->get();

        return [
            'count' => count($ids),
            'items' => $rows->map(fn (Resignation $r) => [
                'id' => (int) $r->id,
                'label' => $r->employee->full_name.' · '.$r->stageLabel(),
                'url' => '/exit/'.$r->id,
            ])->values()->all(),
        ];
    }

    /** @return array{count: int, names: list<string>, more: int} */
    public static function upcoming(int $days = 30): array
    {
        $days = max(1, $days);
        $today = app(AttendanceClock::class)->attendanceToday();
        $until = CarbonImmutable::createFromFormat('!Y-m-d', $today)->addDays($days)->format('Y-m-d');

        $base = fn () => DB::table('resignations')
            ->join('employees', 'employees.id', '=', 'resignations.employee_id')
            ->where('resignations.status', ExitStates::APPROVED)
            ->whereNull('resignations.status_applied_at')
            ->where('resignations.last_working_day', '>=', $today)
            ->where('resignations.last_working_day', '<=', $until);

        $count = $base()->count();

        if ($count === 0) {
            return ['count' => 0, 'names' => [], 'more' => 0];
        }

        $names = $base()
            ->orderBy('resignations.last_working_day')
            ->orderBy('resignations.id')
            ->limit(self::NAMES)
            ->get(['employees.first_name', 'employees.last_name'])
            ->map(fn ($row) => trim($row->first_name.' '.$row->last_name))
            ->values()
            ->all();

        return ['count' => $count, 'names' => $names, 'more' => max(0, $count - self::NAMES)];
    }
}
