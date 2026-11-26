<?php

namespace App\Actions\Exits;

use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\Resignation;
use App\Support\Attendance\AttendanceClock;
use App\Support\Exits\ExitStates;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks and locks every exit action shares, so the refusal texts and the lock order live in one place.
 *
 * Lock order (a rule for every exit and settlement write): the employee row FOR UPDATE first, then the resignation row,
 * then the settlement row, then loans by id. Everything that decides something is read after the locks.
 *
 * Refusals are ValidationException keyed by the field they belong to (last_working_day, reason, note) or "exit" when they
 * belong to the whole action; a person who may not act at all is an AuthorizationException (403) raised by the caller.
 */
final class ExitGuards
{
    public const ENDED = 'This exit has ended and cannot change.';

    public const SETTLEMENT_FROZEN = 'The final settlement is approved, so this exit can no longer change.';

    public const BAD_DATE = 'Enter a valid date.';

    public const BEFORE_TODAY = 'The last working day cannot be before today.';

    public const TOO_FAR = 'The last working day can be at most 365 days ahead.';

    public static function today(): string
    {
        return app(AttendanceClock::class)->attendanceToday();
    }

    /** "Y-m-d" that is a real calendar date. */
    public static function isDate(string $value): bool
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value;
        } catch (Throwable) {
            return false;
        }
    }

    public static function beforeJoiningMessage(Employee $employee): string
    {
        return 'The last working day cannot be before the joining date ('
            .$employee->joining_date->format((string) settings('company.date_format', 'd M Y')).').';
    }

    /**
     * The last working day rules. $minToday true is the employee's own resignation (not before today); HR may record a
     * past day (the payroll lock check decides). Always: a real date, not before the joining date, at most 365 days
     * ahead of today.
     *
     * @throws ValidationException
     */
    public static function assertLastDay(Employee $employee, string $day, bool $minToday): void
    {
        if (! self::isDate($day)) {
            throw ValidationException::withMessages(['last_working_day' => self::BAD_DATE]);
        }

        $today = self::today();

        if ($minToday && $day < $today) {
            throw ValidationException::withMessages(['last_working_day' => self::BEFORE_TODAY]);
        }

        if ($day < $employee->joining_date->format('Y-m-d')) {
            throw ValidationException::withMessages(['last_working_day' => self::beforeJoiningMessage($employee)]);
        }

        $limit = CarbonImmutable::createFromFormat('!Y-m-d', $today)->addDays((int) config('hrm.exit.max_days_ahead'))->format('Y-m-d');

        if ($day > $limit) {
            throw ValidationException::withMessages(['last_working_day' => self::TOO_FAR]);
        }
    }

    /** @throws ValidationException */
    public static function refuse(string $message, string $field = 'exit'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** The employee row FOR UPDATE: always the first lock. */
    public static function lockEmployee(int $id): Employee
    {
        return Employee::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** The resignation row FOR UPDATE (after the employee row), with the locked employee set as its relation. */
    public static function lockResignation(int $id, Employee $employee): Resignation
    {
        $resignation = Resignation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        $resignation->setRelation('employee', $employee);

        return $resignation;
    }

    /** The exit's settlement row FOR UPDATE (after the resignation row), or null. */
    public static function lockSettlement(int $resignationId): ?FinalSettlement
    {
        return FinalSettlement::query()->where('resignation_id', $resignationId)->lockForUpdate()->first();
    }

    /** Refuses an ended exit. */
    public static function assertNotEnded(Resignation $resignation): void
    {
        if (ExitStates::isEnded((string) $resignation->status)) {
            self::refuse(self::ENDED);
        }
    }

    /**
     * What every audit entry of an exit carries.
     *
     * @return array<string, mixed>
     */
    public static function context(Resignation $resignation, Employee $employee): array
    {
        return [
            'resignation' => $resignation->id,
            'employee_id' => $employee->id,
            'employee' => $employee->full_name,
            'type' => $resignation->type,
        ];
    }
}
