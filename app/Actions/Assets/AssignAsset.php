<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\Assets\AssetRules;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hand an asset to an employee. ONE transaction (DB::transaction(fn, 3)) whose first statement locks the employee row by id
 * and whose second locks the asset row by id (plan lock order: employee first, then asset; never the other way). Every
 * decision is read after both locks, with locking reads only, so a stale model passed in by the caller never decides
 * anything (MariaDB REPEATABLE READ: a plain read after the lock wait could still see the snapshot).
 *
 *   app(AssignAsset::class)->handle($hr, $asset, $employee, '2026-11-26', 'Charger included');
 *
 * within() holds the checks and writes without a transaction or locks; the caller (the import) must hold both locks.
 *
 * Refusals (ValidationException): [asset] not available / already held / lost the race to the unique open_asset_id index;
 * [employee_id] not active or on probation; [assigned_on] not a date, in the future (after AttendanceClock::attendanceToday())
 * or before the joining date; [note] longer than 500 characters.
 *
 * Audit `Asset assigned` (area assets, subject the employee, causer $by): asset_id, code, asset_name, assigned_on,
 * condition, note.
 */
class AssignAsset
{
    public const JUST_ASSIGNED = 'This asset was just assigned to someone else.';

    public const FUTURE = 'The assigned date cannot be in the future.';

    public const BAD_DATE = 'Enter the date the asset was handed over.';

    public const NOTE_TOO_LONG = 'The note cannot be longer than 500 characters.';

    /** Employee statuses that may receive an asset. */
    public const ASSIGNABLE_EMPLOYEE_STATUSES = ['active', 'probation'];

    public const NOTE_MAX = 500;

    public static function notAvailableMessage(string $status): string
    {
        return 'This asset is not available. It is '.(AssetRules::STATUS_LABELS[$status] ?? $status).'.';
    }

    public static function heldMessage(string $code, string $holder): string
    {
        return "{$code} is already assigned to {$holder}. Return it first.";
    }

    public static function notActiveMessage(string $name): string
    {
        return "{$name} is not an active employee, so assets cannot be given to them.";
    }

    public static function beforeJoiningMessage(string $name, string $joinedYmd): string
    {
        return "The assigned date cannot be before {$name} joined (".self::human($joinedYmd).').';
    }

    public function handle(User $by, Asset $asset, Employee $employee, string $assignedOn, ?string $note): AssetAssignment
    {
        $employeeId = $employee->getKey();
        $assetId = $asset->getKey();

        return DB::transaction(function () use ($by, $employeeId, $assetId, $assignedOn, $note): AssetAssignment {
            $lockedEmployee = Employee::query()->whereKey($employeeId)->lockForUpdate()->firstOrFail();
            $lockedAsset = Asset::query()->whereKey($assetId)->lockForUpdate()->firstOrFail();

            return $this->within($by, $lockedAsset, $lockedEmployee, $assignedOn, $note);
        }, 3);
    }

    /**
     * The checks and writes. No transaction; the caller holds the employee lock and then the asset lock, and passes the
     * rows it read under them.
     */
    public function within(User $by, Asset $lockedAsset, Employee $lockedEmployee, string $assignedOn, ?string $note): AssetAssignment
    {
        $note = self::cleanNote($note, 'note');

        // The open hand-over, read under the asset lock (locking read: sees what committed while we waited).
        $open = AssetAssignment::query()
            ->where('asset_id', $lockedAsset->id)
            ->open()
            ->lockForUpdate()
            ->first();

        if ($open !== null || $lockedAsset->status === 'assigned') {
            // The holder's name is only message text: a plain read, never a lock (an employee row is never locked after
            // an asset row).
            $holder = $open === null ? null : Employee::query()->whereKey($open->employee_id)->first()?->full_name;
            $holder ??= 'an employee';

            throw ValidationException::withMessages(['asset' => self::heldMessage($lockedAsset->asset_code, $holder)]);
        }

        if ($lockedAsset->status !== 'available') {
            throw ValidationException::withMessages(['asset' => self::notAvailableMessage($lockedAsset->status)]);
        }

        if (! in_array($lockedEmployee->status, self::ASSIGNABLE_EMPLOYEE_STATUSES, true)) {
            throw ValidationException::withMessages(['employee_id' => self::notActiveMessage($lockedEmployee->full_name)]);
        }

        $assignedOn = trim($assignedOn);

        if (! self::isDate($assignedOn)) {
            throw ValidationException::withMessages(['assigned_on' => self::BAD_DATE]);
        }

        if ($assignedOn > app(AttendanceClock::class)->attendanceToday()) {
            throw ValidationException::withMessages(['assigned_on' => self::FUTURE]);
        }

        $joined = $lockedEmployee->joining_date->format('Y-m-d');

        if ($assignedOn < $joined) {
            throw ValidationException::withMessages(['assigned_on' => self::beforeJoiningMessage($lockedEmployee->full_name, $joined)]);
        }

        $assignment = new AssetAssignment;
        $assignment->forceFill([
            'asset_id' => $lockedAsset->id,
            'employee_id' => $lockedEmployee->id,
            'assigned_on' => $assignedOn,
            'condition_out' => $lockedAsset->condition,
            'note' => $note,
            'assigned_by' => $by->id,
        ]);

        try {
            $assignment->save();
        } catch (UniqueConstraintViolationException $e) {
            // The database guard: another open row for this asset was written by someone who did not take our locks.
            if (! str_contains($e->getMessage(), 'open_asset_id')) {
                throw $e;
            }

            throw ValidationException::withMessages(['asset' => self::JUST_ASSIGNED]);
        }

        $lockedAsset->status = 'assigned';
        $lockedAsset->save();

        Audit::record('assets', 'Asset assigned', $lockedEmployee, [
            'asset_id' => $lockedAsset->id,
            'code' => $lockedAsset->asset_code,
            'asset_name' => $lockedAsset->name,
            'assigned_on' => $assignedOn,
            'condition' => $lockedAsset->condition,
            'note' => $note,
        ], $by);

        return $assignment;
    }

    /** A real calendar date written "Y-m-d". */
    public static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** "1 Dec 2026" for a "Y-m-d". */
    public static function human(string $ymd): string
    {
        return Carbon::createFromFormat('!Y-m-d', $ymd)->format('j M Y');
    }

    /** Trimmed note, blank to null; over 500 characters refused on $key. */
    public static function cleanNote(?string $note, string $key): ?string
    {
        $note = $note === null ? null : trim($note);

        if ($note === null || $note === '') {
            return null;
        }

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages([$key => self::NOTE_TOO_LONG]);
        }

        return $note;
    }
}
