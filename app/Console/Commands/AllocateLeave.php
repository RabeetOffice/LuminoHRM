<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\Leavers;
use App\Support\Audit\Audit;
use App\Support\Leave\LeaveBalances;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * hrm:leave-allocate {year?} (routes/console.php, hourly at :40; plan open decision 4). Creates the missing balance rows
 * of a leave year: one per active paid type for everyone employed at some point in that year (joined on or before
 * 31 December; last working day empty or on / after 1 January; never a leaver without a last working day). Each row
 * starts with LeaveBalances::allocationFor (pro-rated for a joiner) through LeaveBalances::ensureRow, which never
 * changes a row that exists: HR's allocated, adjustment and opening used stay as they are.
 *
 * Idempotent, so a missed 1 January is caught up by the next run. Cheap when there is nothing to do: one query for the
 * active paid types and one for the people still missing a row. At most MAX_EMPLOYEES people per run (the next run
 * continues); only their missing (person, type) pairs are written. One audit entry "Leave allocated" (no subject,
 * properties {year, rows}) when the run created any row.
 *
 * The year defaults to the current leave year (AttendanceClock::attendanceToday()). A year more than one year ahead is
 * refused, and so is anything that is not a four-digit year.
 */
class AllocateLeave extends Command
{
    protected $signature = 'hrm:leave-allocate {year? : The leave year (YYYY); default the current one}';

    protected $description = 'Create the missing leave balance rows of a year for everyone employed in it';

    /** People handled per run; the next run continues with the rest. */
    public const MAX_EMPLOYEES = 1000;

    public function handle(LeaveBalances $balances, AttendanceClock $clock): int
    {
        $current = (int) substr($clock->attendanceToday(), 0, 4);
        $argument = $this->argument('year');
        $year = $current;

        if (is_string($argument) && $argument !== '') {
            if (preg_match('/^\d{4}$/', $argument) !== 1) {
                $this->error('The year must be four digits, for example 2027.');

                return self::FAILURE;
            }

            $year = (int) $argument;
        }

        if ($year > $current + 1) {
            $this->error("Only the current leave year ({$current}) or the next one can be allocated.");

            return self::FAILURE;
        }

        $typeIds = array_values(LeaveType::query()->active()->where('is_paid', true)->orderBy('sort_order')->orderBy('id')->pluck('id')
            ->map(fn ($id): int => (int) $id)->all());

        if ($typeIds === []) {
            $this->output->writeln("leave_allocate year={$year} rows=0 remaining=0");

            return self::SUCCESS;
        }

        $missing = self::missingRows(self::employedIn(Employee::query(), $year), $year, $typeIds);
        $people = (clone $missing)->orderBy('id')->limit(self::MAX_EMPLOYEES)->get();
        $created = 0;

        if ($people->isNotEmpty()) {
            $existing = [];

            foreach (LeaveBalance::query()->toBase()
                ->whereIn('employee_id', $people->modelKeys())->where('year', $year)->whereIn('leave_type_id', $typeIds)
                ->get(['employee_id', 'leave_type_id']) as $row) {
                $existing[(int) $row->employee_id][(int) $row->leave_type_id] = true;
            }

            DB::transaction(function () use ($people, $typeIds, $existing, $balances, $year, &$created): void {
                // A shared lock on each type allocated: a quota / paid-flag edit waits until these rows are written.
                $types = LeaveType::query()->whereIn('id', $typeIds)->sharedLock()->get()->keyBy('id');
                $typeIds = array_values(array_filter($typeIds, fn (int $id): bool => isset($types[$id]) && $types[$id]->is_active && $types[$id]->is_paid));

                $before = LeaveBalance::query()->where('year', $year)->whereIn('employee_id', $people->modelKeys())->count();

                foreach ($people as $employee) {
                    foreach ($typeIds as $typeId) {
                        if (! isset($existing[$employee->id][$typeId])) {
                            $balances->ensureRow($employee, $types[$typeId], $year);
                        }
                    }
                }

                $created = LeaveBalance::query()->where('year', $year)->whereIn('employee_id', $people->modelKeys())->count() - $before;

                if ($created > 0) {
                    Audit::record('leave', 'Leave allocated', null, ['year' => $year, 'rows' => $created]);
                }
            });
        }

        $remaining = $people->count() < self::MAX_EMPLOYEES ? 0 : (clone $missing)->count();

        $this->output->writeln("leave_allocate year={$year} rows={$created} remaining={$remaining}");

        if ($remaining > 0) {
            $this->line("{$remaining} people still need rows; the next run continues.");
        }

        return self::SUCCESS;
    }

    /**
     * Narrows an employee query to the people employed at some point in $year (the allocation's population): joined on
     * or before 31 December, last working day empty or on / after 1 January, and not a leaver without a last working day.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public static function employedIn(Builder $query, int $year): Builder
    {
        return Leavers::exceptWithoutLastDay(
            $query
                ->where('joining_date', '<=', sprintf('%04d-12-31', $year))
                ->where(fn (Builder $q) => $q->whereNull('last_working_day')->orWhere('last_working_day', '>=', sprintf('%04d-01-01', $year))),
        );
    }

    /**
     * People of $query with fewer balance rows in $year than there are active paid types (a subquery count).
     *
     * @param  Builder<Employee>  $query
     * @param  list<int>  $typeIds
     * @return Builder<Employee>
     */
    private static function missingRows(Builder $query, int $year, array $typeIds): Builder
    {
        return $query->has(
            'leaveBalances',
            '<',
            count($typeIds),
            'and',
            fn (Builder $q) => $q->where('year', $year)->whereIn('leave_type_id', $typeIds),
        );
    }
}
