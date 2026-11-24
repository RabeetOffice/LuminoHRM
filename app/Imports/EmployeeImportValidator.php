<?php

namespace App\Imports;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeRoles;
use App\Support\EmployeeRules;
use App\Support\Names;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Checks every row of an import against the CURRENT database, before anything is saved.
 *
 * Each row is turned into the employee form's data and validated with the form's own rules (EmployeeRules, import
 * flavour: any configured status), then the file-wide checks run: duplicates inside the file (email, CNIC, employee
 * code, machine user ID: both rows get the error), the department and designation by name, the manager (an existing
 * Active/Probation employee, or another row of the file), reporting loops among the rows, and the login rule that a
 * non-Admin may not link an existing Admin or HR account.
 *
 * Rows only create people, so a new row can never be the manager of an existing employee: a loop can only form among
 * the rows themselves.
 *
 * Exceptions to the form, by design: the status may be any configured status (inactive, resigned and terminated rows
 * are created with their login disabled and no last working day, which the template does not carry); the role is
 * always "employee"; a filled Employee Code from a non-Admin is an error (the form silently ignores it).
 */
final class EmployeeImportValidator
{
    private const SHOWN_LENGTH = 60;

    /**
     * Text columns where a number cell loses information (a leading 0 or "+", digits past the 15th). A number cell is
     * refused there, except: a CNIC that is exactly 13 digits, and a Machine User ID (a whole number up to 15 digits).
     * The Employee Code is refused as a number too (see normalise()).
     */
    private const DIGIT_TEXT = ['phone', 'cnic', 'device_user_id', 'emergency_phone', 'account_number'];

    /** Excel serials of 1940-01-01 and 2100-12-31: a plain number in a date column is only a date in this range. */
    private const FIRST_SERIAL = 14611;

    private const LAST_SERIAL = 73415;

    /** Field key => the column it came from (validation messages name the column). */
    private const ATTRIBUTES = [
        'department_id' => 'department',
        'designation_id' => 'designation',
        'manager_id' => 'manager_email',
    ];

    /** @var array<string, int> */
    private array $departments = [];

    /** @var array<int, array<string, int>> */
    private array $designations = [];

    /** @var array<int, string> */
    private array $departmentNames = [];

    /** @var array<string, array{id: int, status: string}|null> */
    private array $managers = [];

    /**
     * @param  list<array{line: int, cells: array<string, mixed>}>  $rows
     * @return list<array{line: int, display: array<string, string>, errors: list<string>, data: array<string, mixed>, manager_line: int|null}>
     */
    public function check(array $rows, User $by): array
    {
        $this->load();
        $isAdmin = $by->hasRole('admin');

        $checked = array_map(fn (array $row) => $this->normalise($row, $isAdmin), $rows);

        // Each email's first row, for managers named by email.
        $lines = [];
        foreach ($checked as $index => $row) {
            $email = $row['data']['email'] ?? null;
            if (is_string($email) && $email !== '' && ! isset($lines[$email])) {
                $lines[$email] = $index;
            }
        }

        foreach ($checked as $index => $row) {
            $checked[$index] = $this->resolveManager($row, $checked, $lines);
        }

        foreach ($checked as $index => $row) {
            $checked[$index]['errors'] = [...$row['errors'], ...$this->validate($row, $by)];
        }

        $this->duplicates($checked);
        $this->loops($checked);

        return array_map(fn (array $row) => [
            'line' => (int) $row['line'],
            'display' => $row['display'],
            'errors' => array_values(array_unique($row['errors'])),
            'data' => $row['data'],
            'manager_line' => $row['manager_line'],
        ], $checked);
    }

    private function load(): void
    {
        foreach (Department::query()->get(['id', 'name']) as $department) {
            $this->departments[Names::key($department->name)] ??= $department->id;
            $this->departmentNames[$department->id] = $department->name;
        }

        foreach (Designation::query()->get(['id', 'name', 'department_id']) as $designation) {
            $this->designations[$designation->department_id][Names::key($designation->name)] ??= $designation->id;
        }
    }

    /**
     * The row as the employee form's data, plus the errors found while reading its cells.
     *
     * @param  array{line: int, cells: array<string, mixed>}  $row
     * @return array{line: int, display: array<string, string>, errors: list<string>, data: array<string, mixed>, manager_line: int|null, manager_email: string|null, skip: list<string>}
     */
    private function normalise(array $row, bool $isAdmin): array
    {
        $cells = $row['cells'];
        $errors = [];
        $skip = []; // fields whose validation message would only repeat an error found here

        $data = ['role' => EmployeeRoles::DEFAULT];

        foreach (['first_name', 'last_name', 'email', 'phone', 'cnic', 'device_user_id', 'address', 'emergency_name', 'emergency_phone', 'bank_name', 'account_title', 'account_number'] as $key) {
            if (in_array($key, self::DIGIT_TEXT, true) && (is_int($cells[$key] ?? null) || is_float($cells[$key] ?? null))) {
                [$data[$key], $error] = self::numberAsText($key, $cells[$key]);

                if ($error !== null) {
                    $errors[] = $error;
                    $skip[] = $key;
                }

                continue;
            }

            $data[$key] = self::text($cells[$key] ?? null);
        }

        $data = [...$data, ...EmployeeRules::prepare(array_filter($data, 'is_string'))];

        foreach (EmployeeSheet::DATE_KEYS as $key) {
            [$data[$key], $error] = $this->date($cells[$key] ?? null, EmployeeSheet::label($key));
            if ($error !== null) {
                $errors[] = $error;
                $skip[] = $key;
            }
        }

        foreach (array_keys(EmployeeSheet::ENUMS) as $key) {
            $typed = self::text($cells[$key] ?? null);
            $data[$key] = $typed === null ? null : EmployeeSheet::enumValue($typed);

            if ($data[$key] !== null && ! in_array($data[$key], EmployeeSheet::enumValues($key), true)) {
                $allowed = implode(', ', array_map(EmployeeSheet::enumLabel(...), EmployeeSheet::enumValues($key)));
                $errors[] = EmployeeSheet::label($key).' "'.self::shown($typed).'" is not allowed. Use one of: '.$allowed.'.';
                $skip[] = $key;
            }
        }

        $department = self::text($cells['department'] ?? null);
        $designation = self::text($cells['designation'] ?? null);
        $data['department_id'] = null;
        $data['designation_id'] = null;

        if ($department !== null) {
            $data['department_id'] = $this->departments[Names::key($department)] ?? null;

            if ($data['department_id'] === null) {
                $errors[] = 'Department "'.self::shown($department).'" does not exist. Add it under Organization first.';
                $skip[] = 'department_id';
                $skip[] = 'designation_id';
            }
        }

        if ($designation !== null && $data['department_id'] !== null) {
            $data['designation_id'] = $this->designations[$data['department_id']][Names::key($designation)] ?? null;

            if ($data['designation_id'] === null) {
                $errors[] = 'Designation "'.self::shown($designation).'" does not exist in the '.$this->departmentNames[$data['department_id']].' department.';
                $skip[] = 'designation_id';
            }
        }

        $code = self::text($cells['employee_code'] ?? null);

        if (is_int($cells['employee_code'] ?? null) || is_float($cells['employee_code'] ?? null)) {
            $errors[] = self::numberMessage('employee_code');
        } elseif ($code !== null && ! $isAdmin) {
            $errors[] = 'Only an Admin can set the Employee Code. Leave it blank and a code is assigned.';
        } elseif ($code !== null) {
            $data['employee_code'] = $code;
        }

        $managerEmail = self::text($cells['manager_email'] ?? null);
        $data['manager_id'] = null;

        $status = $data['status'];

        return [
            'line' => $row['line'],
            'display' => [
                'name' => self::shown(trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''))),
                'email' => self::shown($data['email'] ?? ''),
                'department' => self::shown($department ?? ''),
                'designation' => self::shown($designation ?? ''),
                'manager' => self::shown($managerEmail ?? ''),
                'status' => is_string($status) && in_array($status, EmployeeSheet::enumValues('status'), true)
                    ? EmployeeSheet::enumLabel($status)
                    : self::shown(self::text($cells['status'] ?? null) ?? ''),
            ],
            'errors' => $errors,
            'data' => $data,
            'manager_line' => null,
            'manager_email' => $managerEmail === null ? null : mb_strtolower($managerEmail),
            'skip' => $skip,
        ];
    }

    /**
     * Sets manager_id (an existing employee) or manager_line (another row of the file), or adds an error.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $all
     * @param  array<string, int>  $lines  email => index in $all
     * @return array<string, mixed>
     */
    private function resolveManager(array $row, array $all, array $lines): array
    {
        $email = $row['manager_email'];
        unset($row['manager_email']);

        if ($email === null) {
            return $row;
        }

        $row['skip'][] = 'manager_id';

        if ($email === ($row['data']['email'] ?? null)) {
            $row['errors'][] = 'A person cannot be their own manager.';

            return $row;
        }

        if (isset($lines[$email])) {
            $manager = $all[$lines[$email]];
            $row['manager_line'] = $manager['line'];

            if (in_array($manager['data']['status'], EmployeeSheet::enumValues('status'), true)
                && ! in_array($manager['data']['status'], ['active', 'probation'], true)) {
                $row['errors'][] = "The manager in row {$manager['line']} is not Active or on Probation.";
            }

            return $row;
        }

        $existing = $this->managers[$email] ??= Employee::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first(['id', 'status'])
            ?->only(['id', 'status']);

        if ($existing === null) {
            $row['errors'][] = 'Manager Email "'.self::shown($email).'" does not match an existing employee or another row in this file.';
        } elseif (! in_array($existing['status'], ['active', 'probation'], true)) {
            $row['errors'][] = 'The manager '.self::shown($email).' is not Active or on Probation.';
        } else {
            $row['data']['manager_id'] = (int) $existing['id'];
            $row['skip'] = array_values(array_diff($row['skip'], ['manager_id']));
        }

        return $row;
    }

    /**
     * The employee form's rules for this row, with the columns' names in the messages.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validate(array $row, User $by): array
    {
        $data = $row['data'];
        $rules = new EmployeeRules(null, $by, fn (string $key) => $data[$key] ?? null, import: true);

        $attributes = [];
        foreach (array_keys($rules->rules()) as $key) {
            $column = self::ATTRIBUTES[$key] ?? $key;
            if (in_array($column, EmployeeSheet::COLUMNS, true)) {
                $attributes[$key] = EmployeeSheet::label($column);
            }
        }

        $validator = Validator::make($data, array_diff_key($rules->rules(), array_flip($row['skip'])), EmployeeRules::messages(), $attributes);
        $errors = $validator->errors()->all();

        // A non-Admin may not link an existing Admin or HR login (the form refuses the same; EmployeeRoles).
        $email = $data['email'] ?? null;

        if (is_string($email) && $email !== '' && ! $validator->errors()->has('email')) {
            $account = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($account !== null && $account->employee_id === null && ! EmployeeRoles::mayTouchAccount($by, $account)) {
                $errors[] = EmployeeRoles::PRIVILEGED_MESSAGE;
            }
        }

        return array_values($errors);
    }

    /**
     * Two rows with the same email, CNIC, employee code or machine user ID: both get an error naming the other.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function duplicates(array &$rows): void
    {
        $fields = ['email' => 'Work Email', 'cnic' => 'CNIC', 'employee_code' => 'Employee Code', 'device_user_id' => 'Machine User ID'];

        foreach ($fields as $key => $label) {
            $seen = [];

            foreach ($rows as $index => $row) {
                $value = $row['data'][$key] ?? null;

                if (is_string($value) && $value !== '') {
                    $seen[mb_strtolower($value)][] = $index;
                }
            }

            foreach ($seen as $indexes) {
                foreach ($indexes as $index) {
                    foreach ($indexes as $other) {
                        if ($other !== $index) {
                            $rows[$index]['errors'][] = "{$label} is also used in row {$rows[$other]['line']}.";
                        }
                    }
                }
            }
        }
    }

    /**
     * Rows whose managers (in this file) lead back to themselves: every row of the loop gets the error.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function loops(array &$rows): void
    {
        $byLine = [];
        foreach ($rows as $index => $row) {
            $byLine[$row['line']] ??= $index;
        }

        $done = [];

        foreach ($rows as $start => $row) {
            $path = [];
            $line = $row['line'];

            while ($line !== null && ! isset($done[$line]) && ! in_array($line, $path, true)) {
                $path[] = $line;
                $line = $rows[$byLine[$line]]['manager_line'];
            }

            if ($line !== null && in_array($line, $path, true)) {
                $loop = array_slice($path, (int) array_search($line, $path, true));
                sort($loop);
                $message = 'The reporting lines of rows '.implode(', ', $loop).' loop back on themselves.';

                foreach ($loop as $member) {
                    $rows[$byLine[$member]]['errors'][] = $message;
                }
            }

            foreach ($path as $visited) {
                $done[$visited] = true;
            }
        }
    }

    /**
     * A date cell as Y-m-d: YYYY-MM-DD text, DD/MM/YYYY text, or a real Excel date (a serial number).
     *
     * @return array{0: string|null, 1: string|null} the date, or an error
     */
    private function date(mixed $value, string $label): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }

        if (is_int($value) || is_float($value)) {
            // Data-only reading does not see number formats, so the value decides: a date between 1940 and 2100.
            // "2024" typed into a date column is NOT taken as day 2,024 of 1900.
            if ($value >= self::FIRST_SERIAL && $value < self::LAST_SERIAL + 1) {
                try {
                    return [ExcelDate::excelToDateTimeObject(floor((float) $value))->format('Y-m-d'), null];
                } catch (Throwable) {
                    // reported below
                }
            }
        } elseif (is_string($value)) {
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return [sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), null];
            }

            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return [sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]), null];
            }
        }

        return [null, $label.' "'.self::shown((string) $value).'" is not a valid date. Use YYYY-MM-DD (for example 2024-03-01), DD/MM/YYYY or an Excel date.'];
    }

    /**
     * A NUMBER cell in a column that should hold text: [the text, null] when nothing can have been lost, else [null, error].
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function numberAsText(string $key, int|float $value): array
    {
        // A whole number that round-trips exactly: no fraction, no exponent, at most 15 significant digits.
        $digits = null;

        if (is_int($value) && $value >= 0 && $value < 10 ** 15) {
            $digits = (string) $value;
        } elseif (is_float($value) && $value >= 0 && $value < 1e15 && floor($value) === $value) {
            $digits = sprintf('%.0f', $value);
        }

        if ($digits !== null && $key === 'device_user_id') {
            return [$digits, null];
        }

        if ($digits !== null && $key === 'cnic' && strlen($digits) === 13) {
            return [$digits, null];
        }

        return [null, self::numberMessage($key)];
    }

    private static function numberMessage(string $key): string
    {
        return EmployeeSheet::label($key)." was stored as a number in Excel, so leading zeros or '+' may be lost. Format the column as Text and re-enter it.";
    }

    /** A cell as text: numbers without exponent or ".0", empty as null. */
    private static function text(mixed $value): ?string
    {
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = sprintf('%.0f', $value);
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** At most 60 characters of a cell value, for the preview and its messages. */
    private static function shown(string $value): string
    {
        return mb_strimwidth($value, 0, self::SHOWN_LENGTH, '…');
    }
}
