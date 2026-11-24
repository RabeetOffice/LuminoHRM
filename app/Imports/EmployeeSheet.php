<?php

namespace App\Imports;

/**
 * The employee spreadsheet's columns, shared by the template, the import and the export (an export can be edited and
 * imported again because all three use this list).
 *
 * Header names are exact; a "*" marks a column the file must have. On import, headers are matched case-insensitively,
 * ignoring surrounding spaces and the "*". The value of each key is the field it fills (the employee form's names, plus
 * `department`, `designation` and `manager_email`, which the import resolves to ids).
 */
final class EmployeeSheet
{
    /** header => key, in the template's order */
    public const COLUMNS = [
        'First Name*' => 'first_name',
        'Last Name*' => 'last_name',
        'Work Email*' => 'email',
        'Phone' => 'phone',
        'Date of Birth' => 'dob',
        'Gender' => 'gender',
        'CNIC' => 'cnic',
        'Department*' => 'department',
        'Designation*' => 'designation',
        'Manager Email' => 'manager_email',
        'Joining Date*' => 'joining_date',
        'Employment Type*' => 'employment_type',
        'Status*' => 'status',
        'Machine User ID' => 'device_user_id',
        'Employee Code' => 'employee_code',
        'Address' => 'address',
        'Emergency Contact Name' => 'emergency_name',
        'Emergency Contact Phone' => 'emergency_phone',
        'Bank Name' => 'bank_name',
        'Account Title' => 'account_title',
        'Account Number' => 'account_number',
    ];

    /** Columns holding a date (written as YYYY-MM-DD text). */
    public const DATE_KEYS = ['dob', 'joining_date'];

    /** Columns whose values come from config (hrm.employees.*), with the config key. */
    public const ENUMS = ['gender' => 'genders', 'employment_type' => 'employment_types', 'status' => 'statuses'];

    /**
     * A row whose First Name starts with this word is the template's example row and is skipped (case-insensitive).
     */
    public const EXAMPLE_MARKER = 'EXAMPLE';

    public const MAX_ROWS = 2000;

    public const MAX_KILOBYTES = 5120;

    /** @return list<string> */
    public static function headers(): array
    {
        return array_keys(self::COLUMNS);
    }

    /** The header without its "*": what messages call the column. */
    public static function label(string $key): string
    {
        $header = (string) array_search($key, self::COLUMNS, true);

        return rtrim($header, '*');
    }

    public static function isRequired(string $key): bool
    {
        return str_ends_with((string) array_search($key, self::COLUMNS, true), '*');
    }

    /** How a header is compared: lower case, no "*", single spaces. */
    public static function normaliseHeader(string $header): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', str_replace('*', '', $header))));
    }

    /** A stored enum value as a person reads it: full_time => "Full Time". */
    public static function enumLabel(?string $value): string
    {
        return $value === null || $value === '' ? '' : ucwords(str_replace('_', ' ', $value));
    }

    /** @return list<string> the allowed values of an enum column */
    public static function enumValues(string $key): array
    {
        return config('hrm.employees.'.self::ENUMS[$key]);
    }

    /** A typed enum value as stored: " Full-time " => "full_time". */
    public static function enumValue(string $typed): string
    {
        return trim((string) preg_replace('/[\s_-]+/u', '_', mb_strtolower(trim($typed))), '_');
    }

    /** "12345-1234567-1" for a 13-digit CNIC, anything else unchanged. */
    public static function formatCnic(?string $cnic): string
    {
        if ($cnic === null) {
            return '';
        }

        return preg_match('/^\d{13}$/', $cnic) === 1
            ? substr($cnic, 0, 5).'-'.substr($cnic, 5, 7).'-'.substr($cnic, 12)
            : $cnic;
    }
}
