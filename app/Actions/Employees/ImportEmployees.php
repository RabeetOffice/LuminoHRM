<?php

namespace App\Actions\Employees;

use App\Actions\SetUserActive;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\EmployeeCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Saves the checked rows of an import: all of them or none, in ONE database transaction.
 *
 * - Every row goes through CreateEmployee with $sendLink = false: the login is created with a random password nobody
 *   knows and NO email is sent (HR sends links later with "Send set-password links" on the list). The run hashes ONE
 *   random 64-character secret and gives that hash to every login it creates (bcrypt at 12 rounds costs ~0.2 s, so a
 *   hash per row would make 2,000 rows take minutes); the secret itself is dropped at once, never stored, logged or
 *   returned, so nobody can sign in with it and each person still sets their own password through the link.
 * - Rows are saved in dependency order: a row whose manager is another row is saved after that row.
 * - Codes: a code from the file (Admin only; the checks refuse it for anyone else) is kept; blank ones are numbered in
 *   FILE order from the next free code, skipping codes the file itself uses.
 * - Inactive, resigned and terminated rows get their login disabled (SetUserActive) in the same transaction. The
 *   template has no last working day, so it stays empty (the documented exception to the status dialog's rule).
 * - One `employees` audit entry for the run, counts only. (Each person also gets the usual "Employee created" entry.)
 *
 * The caller must have run EmployeeImportValidator on the current data and found no error.
 */
class ImportEmployees
{
    private const DISABLING = ['inactive', 'resigned', 'terminated'];

    public function __construct(private readonly SetUserActive $setActive) {}

    /**
     * @param  list<array{line: int, data: array<string, mixed>, manager_line: int|null, errors: list<string>}>  $rows
     * @return int how many employees were created
     */
    public function handle(array $rows, User $by): int
    {
        foreach ($rows as $row) {
            if ($row['errors'] !== []) {
                throw new InvalidArgumentException("Row {$row['line']} has errors.");
            }
        }

        // One server-made hash for the whole run; the plain secret only lives inside this expression.
        $passwordHash = Hash::make(Str::random(64));

        return DB::transaction(function () use ($rows, $by, $passwordHash) {
            $codes = $this->codes($rows);
            $ids = []; // line => employee id
            $disabled = 0;
            $linked = 0;

            foreach ($this->order($rows) as $row) {
                $data = $row['data'];

                if ($row['manager_line'] !== null) {
                    $data['manager_id'] = $ids[$row['manager_line']];
                }

                // Resolved from the container each time, so a test can stand in for it.
                $create = app(CreateEmployee::class);
                $employee = $create->handle($data, $by, false, $codes[$row['line']], $passwordHash);
                $ids[$row['line']] = $employee->getKey();
                $linked += $create->linkedExistingUser() ? 1 : 0;

                if (in_array($employee->status, self::DISABLING, true)) {
                    $account = $employee->user()->first();

                    if ($account !== null && $account->is_active) {
                        $this->setActive->handle($account, false, $by);
                        $disabled++;
                    }
                }
            }

            Audit::record('employees', 'Employees imported', null, [
                'created' => count($ids),
                'updated' => 0,
                'failed' => 0,
                'logins_disabled' => $disabled,
                'linked_existing' => $linked,
            ], $by);

            return count($ids);
        });
    }

    /**
     * Each row's code: the file's own, or the next free one in file order.
     *
     * @param  list<array{line: int, data: array<string, mixed>}>  $rows
     * @return array<int, string> line => code
     */
    private function codes(array $rows): array
    {
        $taken = [];
        foreach ($rows as $row) {
            if (filled($row['data']['employee_code'] ?? null)) {
                $taken[strtoupper((string) $row['data']['employee_code'])] = true;
            }
        }

        // Locks the existing codes for the rest of the transaction.
        $next = (int) substr(EmployeeCode::next(), 3);
        $codes = [];

        foreach ($rows as $row) {
            if (filled($row['data']['employee_code'] ?? null)) {
                $codes[$row['line']] = (string) $row['data']['employee_code'];

                continue;
            }

            while (isset($taken[sprintf('LD-%04d', $next)])) {
                $next++;
            }

            $codes[$row['line']] = sprintf('LD-%04d', $next);
            $taken[$codes[$row['line']]] = true;
        }

        return $codes;
    }

    /**
     * File order, except that a row whose manager is another row comes after it (the checks refused loops).
     *
     * @param  list<array{line: int, manager_line: int|null}>  $rows
     * @return list<array<string, mixed>>
     */
    private function order(array $rows): array
    {
        $byLine = [];
        foreach ($rows as $row) {
            $byLine[$row['line']] = $row;
        }

        $ordered = [];
        $placed = [];

        foreach ($rows as $row) {
            // Walk up to the first manager already placed (or the top), then place that chain top-down.
            $chain = [];
            $line = $row['line'];

            while ($line !== null && ! isset($placed[$line]) && ! in_array($line, $chain, true)) {
                $chain[] = $line;
                $line = $byLine[$line]['manager_line'];
            }

            foreach (array_reverse($chain) as $member) {
                $placed[$member] = true;
                $ordered[] = $byLine[$member];
            }
        }

        return $ordered;
    }
}
