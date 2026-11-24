<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the employees list. The field list is explicit on purpose: only `basic` and `employment` data
 * (name, code, work email, department, designation, status, joining date) ever reaches the list, for every role.
 * Never add a field that is not in those groups here, and never return the model itself.
 *
 * Needs `department`, `designation` and `user` (id, employee_id, is_active) loaded, so a page of rows costs no
 * extra queries per row.
 *
 * @property Employee $resource
 */
class EmployeeListResource extends JsonResource
{
    /** What the Login pill shows: a linked account that can sign in, a linked account that cannot, or none. */
    public const LOGIN_ACTIVE = 'active';

    public const LOGIN_DISABLED = 'disabled';

    public const LOGIN_NONE = 'none';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $employee = $this->resource;

        return [
            'id' => $employee->id,
            'code' => $employee->employee_code,
            'name' => $employee->full_name,
            'email' => $employee->email,
            // The photo route arrives with the file handling; until then every row shows initials.
            'photo_url' => null,
            'department' => $employee->department->name,
            'designation' => $employee->designation->name,
            'status' => $employee->status,
            'joining_date' => $employee->joining_date->format('Y-m-d'),
            'login' => match (true) {
                $employee->user === null => self::LOGIN_NONE,
                $employee->user->is_active => self::LOGIN_ACTIVE,
                default => self::LOGIN_DISABLED,
            },
        ];
    }
}
