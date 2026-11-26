<?php

namespace App\Support\Exits;

use App\Models\FinalSettlement;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use Illuminate\Database\Eloquent\Builder;

/**
 * The exits waiting on this user: the dashboard's "Exits waiting for you" count and the list's "Waiting for me" tab.
 *
 *   a decision      the manager step for the routed manager, the final step for a final approver (ExitRouting::waitingFor,
 *                   so the own-exit and Admin / HR rules apply)
 *   clearance       exit.clearance: an approved exit they may administer whose clearance is not finished (a hand item not
 *                   ticked, or an asset still held) and whose settlement is not yet approved
 *   a settlement    exit.manage: a draft settlement that is up to date, on an approved exit whose last working day has
 *                   passed and whose clearance is finished (not one's own, unless the sole active Admin)
 *
 * The clearance rule is worked out in SQL (the "assets" row is automatic, so it is judged by what is held, not by its
 * stored tick). Whether the settlement's other checks pass (salary profile, attendance, leave, previous run) is not
 * judged here: the count means "ready for you to look at".
 */
final class ExitsWaiting
{
    /** @return list<int> */
    public static function ids(User $user): array
    {
        $routing = new ExitRouting;
        $ids = $routing->waitingFor($user)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($user->can('exit.clearance')) {
            $candidates = self::incompleteClearance(Resignation::query())->with('employee:id,first_name,last_name')->get();

            foreach ($candidates as $resignation) {
                if ($routing->mayManage($user, $resignation)) {
                    $ids[] = (int) $resignation->id;
                }
            }
        }

        if ($user->can('exit.manage')) {
            $today = app(AttendanceClock::class)->attendanceToday();
            $sole = $routing->isSoleActiveAdmin($user);
            $own = $user->employee_id === null ? null : (int) $user->employee_id;

            $ready = Resignation::query()
                ->where('status', ExitStates::APPROVED)
                ->whereNull('status_applied_at')
                ->where('last_working_day', '<', $today)
                ->whereIn('id', FinalSettlement::query()->select('resignation_id')->where('status', 'draft')->where('stale', false))
                ->whereNotIn('id', self::incompleteClearance(Resignation::query())->select('id'))
                ->get(['id', 'employee_id']);

            foreach ($ready as $resignation) {
                if ($own === null || (int) $resignation->employee_id !== $own || $sole) {
                    $ids[] = (int) $resignation->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public static function count(User $user): int
    {
        return count(self::ids($user));
    }

    /**
     * Approved exits that have not left, whose settlement is not approved, and whose clearance is unfinished.
     *
     * @param  Builder<Resignation>  $query
     * @return Builder<Resignation>
     */
    private static function incompleteClearance(Builder $query): Builder
    {
        return $query
            ->where('resignations.status', ExitStates::APPROVED)
            ->whereNull('resignations.status_applied_at')
            ->whereNotIn('resignations.id', FinalSettlement::query()->select('resignation_id')->whereIn('status', (array) config('hrm.exit.frozen_statuses')))
            ->where(function (Builder $w) {
                $w->whereExists(fn ($c) => $c->selectRaw('1')->from('clearances')
                    ->whereColumn('clearances.resignation_id', 'resignations.id')
                    ->where('clearances.kind', '!=', 'assets')
                    ->whereNull('clearances.cleared_at'))
                    ->orWhereExists(fn ($a) => $a->selectRaw('1')->from('asset_assignments')
                        ->whereColumn('asset_assignments.employee_id', 'resignations.employee_id')
                        ->whereNull('asset_assignments.returned_on'));
            });
    }
}
