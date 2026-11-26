<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\Assets\AssetRules;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Take an asset back. ONE transaction (DB::transaction(fn, 3)):
 *   1. a plain read of the assignment's employee_id and asset_id (to know which rows to lock);
 *   2. the employee row FOR UPDATE, 3. the asset row FOR UPDATE (plan lock order: employee first, then asset);
 *   4. the assignment re-read FOR UPDATE: refused when it is no longer open or no longer the same holder and asset.
 * Every read after step 1 is a locking read: the plain read in step 1 fixes the MariaDB REPEATABLE READ snapshot, so a
 * plain re-read would not see a return that committed while we waited.
 *
 *   app(ReturnAsset::class)->handle($hr, $assignment, '2026-11-26', 'good', 'available', null);
 *
 * Allowed whatever the holder's employment status. within() holds the checks and writes; the caller holds the locks.
 *
 * Refusals (ValidationException): [assignment] already returned; [returned_on] not a date, before the hand-over, in the
 * future; [return_condition] not one of config('hrm.assets.conditions'); [next_status] not available / in_repair /
 * retired, or retired without assets.manage; [return_note] longer than 500 characters.
 *
 * Writes: returned_on, return_condition, return_note, returned_by (the model clears open_asset_id); the asset's condition
 * becomes the return condition and its status the next status. Audit `Asset returned` (area assets, subject the
 * employee): asset_id, code, asset_name, assigned_on, returned_on, condition_out, return_condition, next_status, note.
 */
class ReturnAsset
{
    public const ALREADY_RETURNED = 'This asset was already returned.';

    public const FUTURE = 'The return date cannot be in the future.';

    public const BAD_DATE = 'Enter the date the asset came back.';

    public const BAD_CONDITION = 'Choose the condition the asset came back in.';

    public const BAD_NEXT_STATUS = 'Choose what happens to the asset next.';

    /** What an asset may become on its return. */
    public const NEXT_STATUSES = ['available', 'in_repair', 'retired'];

    public static function beforeAssignedMessage(string $assignedYmd): string
    {
        return 'The return date cannot be before it was handed over ('.AssignAsset::human($assignedYmd).').';
    }

    public function handle(User $by, AssetAssignment $assignment, string $returnedOn, string $returnCondition, string $nextStatus, ?string $note): AssetAssignment
    {
        $id = $assignment->getKey();

        return DB::transaction(function () use ($by, $id, $returnedOn, $returnCondition, $nextStatus, $note): AssetAssignment {
            $ref = AssetAssignment::query()->whereKey($id)->firstOrFail(['id', 'employee_id', 'asset_id']);

            $lockedEmployee = Employee::query()->whereKey($ref->employee_id)->lockForUpdate()->firstOrFail();
            $lockedAsset = Asset::query()->whereKey($ref->asset_id)->lockForUpdate()->firstOrFail();
            $current = AssetAssignment::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($current->employee_id !== $ref->employee_id || $current->asset_id !== $ref->asset_id) {
                throw ValidationException::withMessages(['assignment' => self::ALREADY_RETURNED]);
            }

            return $this->within($by, $current, $lockedAsset, $lockedEmployee, $returnedOn, $returnCondition, $nextStatus, $note);
        }, 3);
    }

    /**
     * The checks and writes. No transaction; the caller holds the employee lock, then the asset lock, and re-read the
     * assignment under them.
     */
    public function within(
        User $by,
        AssetAssignment $lockedAssignment,
        Asset $lockedAsset,
        Employee $lockedEmployee,
        string $returnedOn,
        string $returnCondition,
        string $nextStatus,
        ?string $note,
    ): AssetAssignment {
        if (! $lockedAssignment->isOpen()
            || $lockedAssignment->asset_id !== $lockedAsset->id
            || $lockedAssignment->employee_id !== $lockedEmployee->id) {
            throw ValidationException::withMessages(['assignment' => self::ALREADY_RETURNED]);
        }

        $note = AssignAsset::cleanNote($note, 'return_note');
        $returnedOn = trim($returnedOn);

        if (! AssignAsset::isDate($returnedOn)) {
            throw ValidationException::withMessages(['returned_on' => self::BAD_DATE]);
        }

        $assignedOn = $lockedAssignment->assigned_on->format('Y-m-d');

        if ($returnedOn < $assignedOn) {
            throw ValidationException::withMessages(['returned_on' => self::beforeAssignedMessage($assignedOn)]);
        }

        if ($returnedOn > app(AttendanceClock::class)->attendanceToday()) {
            throw ValidationException::withMessages(['returned_on' => self::FUTURE]);
        }

        if (! in_array($returnCondition, config('hrm.assets.conditions'), true)) {
            throw ValidationException::withMessages(['return_condition' => self::BAD_CONDITION]);
        }

        if (! in_array($nextStatus, self::NEXT_STATUSES, true)) {
            throw ValidationException::withMessages(['next_status' => self::BAD_NEXT_STATUS]);
        }

        if ($nextStatus === 'retired' && ! $by->can('assets.manage')) {
            throw ValidationException::withMessages(['next_status' => AssetRules::ONLY_ADMIN_RETIRES]);
        }

        $lockedAssignment->forceFill([
            'returned_on' => $returnedOn,
            'return_condition' => $returnCondition,
            'return_note' => $note,
            'returned_by' => $by->id,
        ])->save();

        $lockedAsset->condition = $returnCondition;
        $lockedAsset->status = $nextStatus;
        $lockedAsset->save();

        Audit::record('assets', 'Asset returned', $lockedEmployee, [
            'asset_id' => $lockedAsset->id,
            'code' => $lockedAsset->asset_code,
            'asset_name' => $lockedAsset->name,
            'assigned_on' => $assignedOn,
            'returned_on' => $returnedOn,
            'condition_out' => $lockedAssignment->condition_out,
            'return_condition' => $returnCondition,
            'next_status' => $nextStatus,
            'note' => $note,
        ], $by);

        return $lockedAssignment;
    }
}
