<?php

namespace App\Actions\Exits;

use App\Models\FinalSettlement;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitStates;
use App\Support\Exits\SettlementResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR prepares the settlement draft of an approved exit (design 5.5 "Prepare"): one per exit, month = the month of the
 * last working day, calculated straight away. exit.clearance + PayrollAccess::mayEditSalary, never one's own, an
 * Admin / HR person's only by an Admin.
 *
 * Locks: employee FOR UPDATE, resignation FOR UPDATE, the exit's settlement row FOR UPDATE (none yet; the unique
 * resignation_id index backs a race). Refusals [settlement]: own / privileged (SettlementAccess), NOT_APPROVED,
 * EXISTS. Audited "Settlement prepared" with the figures.
 */
class PrepareSettlement
{
    public const NOT_APPROVED = 'The exit must be approved before a settlement is prepared.';

    public const EXISTS = 'A settlement already exists for this exit.';

    /** @throws AuthorizationException|ValidationException */
    public function handle(User $by, Resignation $resignation): FinalSettlement
    {
        try {
            return DB::transaction(function () use ($by, $resignation): FinalSettlement {
                $employee = ExitGuards::lockEmployee((int) $resignation->employee_id);
                $locked = ExitGuards::lockResignation((int) $resignation->id, $employee);

                RecalculateSettlement::authorize($by, $locked);

                if ($locked->status !== ExitStates::APPROVED) {
                    ExitGuards::refuse(self::NOT_APPROVED, 'settlement');
                }

                if (ExitGuards::lockSettlement((int) $locked->id) !== null) {
                    ExitGuards::refuse(self::EXISTS, 'settlement');
                }

                $settlement = FinalSettlement::query()->create([
                    'resignation_id' => $locked->id,
                    'employee_id' => $employee->id,
                    'month' => $locked->last_working_day->format('Y-m'),
                    'status' => 'draft',
                    'prepared_by' => $by->id,
                ]);
                $settlement->setRelation('resignation', $locked);

                $result = RecalculateSettlement::store($settlement);

                Audit::record('exit', 'Settlement prepared', $locked, [
                    ...ExitGuards::context($locked, $employee),
                    'settlement' => $settlement->id,
                    'month' => $settlement->month,
                    'attributes' => SettlementResult::audited($result->figures),
                ], $by);

                return $settlement->refresh();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['settlement' => self::EXISTS]);
        }
    }
}
