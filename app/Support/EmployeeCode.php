<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Hands out employee codes: LD-0001, LD-0002, ... (zero-padded to 4 digits, growing past LD-9999).
 *
 * How a caller uses it (the code is only reserved by saving the row, so read and save in ONE transaction):
 *
 *   $employee = DB::transaction(function () use ($data) {
 *       $code = EmployeeCode::next();                      // reads the existing codes FOR UPDATE
 *       return Employee::create([...$data, 'employee_code' => $code]);
 *   }, attempts: 3);                                       // retried by Laravel on a deadlock
 *
 * On MariaDB the lock makes a second request that calls next() wait until the first has committed, then see
 * its code and take the next one. The lock is the fast path; the unique index on employees.employee_code is the
 * backstop. If a request still loses the race (an empty table, where there is no row to lock, can deadlock or
 * insert twice), the database refuses the INSERT with a unique violation. Catch it, ask isCodeConflict(), and
 * run the whole transaction again once or twice:
 *
 *   try { ... } catch (UniqueConstraintViolationException $e) {
 *       if (! EmployeeCode::isCodeConflict($e)) { throw $e; }   // a duplicate email or CNIC is the user's to fix
 *       // run the transaction again: next() now sees the winner's code
 *   }
 *
 * SQLite (the test database) ignores row locks, so tests can only prove the backstop, not the waiting.
 */
class EmployeeCode
{
    public const PREFIX = 'LD-';

    /** What a typed code must look like (LD- and at least four digits); the form and the import validate with it. */
    public const PATTERN = '/^LD-\d{4,}$/';

    /** The next free code. Call it inside the transaction that will save the employee (see the class comment). */
    public static function next(): string
    {
        return DB::transaction(function () {
            $highest = 0;

            foreach (self::lockedCodes()->pluck('employee_code') as $code) {
                if (preg_match('/^LD-(\d+)$/', (string) $code, $m) === 1) {
                    $highest = max($highest, (int) $m[1]);
                }
            }

            return self::PREFIX.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Every existing code, read with FOR UPDATE so concurrent callers queue up behind the first.
     *
     * @return Builder<Employee>
     */
    public static function lockedCodes(): Builder
    {
        return Employee::query()->select('employee_code')->where('employee_code', 'like', self::PREFIX.'%')->lockForUpdate();
    }

    /**
     * True when the unique violation is on the employee code (not the email, CNIC or machine ID). Reads the driver's own
     * message ("UNIQUE constraint failed: employees.employee_code" on SQLite, "... for key 'employees_employee_code_unique'"
     * on MariaDB), not the exception text, which also quotes the whole INSERT.
     */
    public static function isCodeConflict(UniqueConstraintViolationException $e): bool
    {
        $driverMessage = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driverMessage, 'employees.employee_code') || str_contains($driverMessage, 'employees_employee_code_unique');
    }
}
