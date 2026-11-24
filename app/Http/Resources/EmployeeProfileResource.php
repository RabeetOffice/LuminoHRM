<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Support\EmployeeAccess;
use App\Support\EmployeeFiles;
use App\Support\EmployeeRoles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An employee as the profile page (and, read-only, My Profile) receives it. This is the ONLY way a whole employee
 * reaches a page: it asks EmployeeAccess::fieldGroups() what the viewer may see and writes explicit keys for each
 * allowed group. A group the viewer may not see is ABSENT from the result (not null, not empty), its relation is
 * never even loaded, and the matching tab is not in tabs().
 *
 *   id, code, name, photo_url            always (a viewer who can open the record may see who it is)
 *   basic      first_name, last_name, email, phone, gender
 *   identity   cnic (formatted 12345-1234567-1), dob         -> shown inside the Basic Info tab
 *   employment department, designation, manager {id|null, name}|null, joining_date, probation_end_date,
 *              employment_type, status, last_working_day, reports [{id, name, code, designation}],
 *              shift (the shift's name; the default shift's name when the employee has none) and shift_is_default,
 *              and for view-all viewers device_user_id, for employees.manage viewers role, login and attendance_exempt
 *   contact    address, emergency_name, emergency_phone
 *   banking    bank_name, account_title, account_number
 *   documents  items [{id, type, title, original_name, size_bytes, uploaded_at, expiry_date, download_url}]
 *
 * Never add a field to a group without asking whether the roles that see that group may see it, and never return
 * the model or a relation as it is.
 *
 * @property Employee $resource
 */
class EmployeeProfileResource extends JsonResource
{
    /** Which tab shows which group. `identity` has no tab of its own: it sits inside Basic Info. */
    public const TABS = [
        'basic' => 'basic',
        'employment' => 'employment',
        'contact' => 'contact',
        'banking' => 'banking',
        'documents' => 'documents',
    ];

    /** @var list<string> */
    private array $groups;

    private readonly bool $viewAll;

    private readonly bool $manage;

    public function __construct(Employee $resource, private readonly EmployeeAccess $access, User $viewer)
    {
        parent::__construct($resource);

        $this->groups = $access->fieldGroups($resource);
        $this->viewAll = $viewer->can('employees.view-all');
        $this->manage = $access->canManage();
    }

    /**
     * The tab keys the viewer gets, in display order.
     *
     * @return list<string>
     */
    public function tabs(): array
    {
        return array_keys(array_filter(self::TABS, fn (string $group) => $this->allows($group)));
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $employee = $this->resource;

        $data = [
            'id' => $employee->id,
            'code' => $employee->employee_code,
            'name' => $employee->full_name,
            // The authorised photo route with a version, or null (initials). Anyone who may open the record may see it.
            'photo_url' => EmployeeFiles::photoUrl($employee),
        ];

        if ($this->allows('basic')) {
            $data['basic'] = [
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'gender' => $employee->gender,
            ];
        }

        if ($this->allows('identity')) {
            $data['identity'] = [
                'cnic' => self::formatCnic($employee->cnic),
                'dob' => $employee->dob?->format('Y-m-d'),
            ];
        }

        if ($this->allows('employment')) {
            $data['employment'] = $this->employment();
        }

        if ($this->allows('contact')) {
            $contact = $employee->contact()->first();

            $data['contact'] = [
                'address' => $contact?->address,
                'emergency_name' => $contact?->emergency_name,
                'emergency_phone' => $contact?->emergency_phone,
            ];
        }

        if ($this->allows('banking')) {
            $bank = $employee->bankAccount()->first();

            $data['banking'] = [
                'bank_name' => $bank?->bank_name,
                'account_title' => $bank?->account_title,
                'account_number' => $bank?->account_number,
            ];
        }

        if ($this->allows('documents')) {
            // Never the stored path: a document leaves the disk only through its download route.
            $data['documents'] = ['items' => EmployeeFiles::documentItems($employee)];
        }

        return $data;
    }

    /** 13 digits as 12345-1234567-1; anything else exactly as stored; null stays null. */
    public static function formatCnic(?string $cnic): ?string
    {
        if ($cnic === null || $cnic === '') {
            return null;
        }

        return preg_match('/^\d{13}$/', $cnic) === 1
            ? substr($cnic, 0, 5).'-'.substr($cnic, 5, 7).'-'.substr($cnic, 12, 1)
            : $cnic;
    }

    /** @return array<string, mixed> */
    private function employment(): array
    {
        $employee = $this->resource;
        $employee->loadMissing(['department:id,name', 'designation:id,name', 'manager:id,first_name,last_name,manager_id']);

        $manager = $employee->manager;

        $data = [
            'department' => $employee->department->name,
            'designation' => $employee->designation->name,
            // The id only when the viewer may open that person; otherwise a name with nothing to follow.
            'manager' => $manager === null ? null : [
                'id' => $this->access->canView($manager) ? $manager->id : null,
                'name' => $manager->full_name,
            ],
            'joining_date' => $employee->joining_date->format('Y-m-d'),
            'probation_end_date' => $employee->probation_end_date?->format('Y-m-d'),
            'employment_type' => $employee->employment_type,
            'status' => $employee->status,
            'last_working_day' => $employee->last_working_day?->format('Y-m-d'),
            'reports' => $this->reports(),
        ];

        // The shift's name only (no times or rules): every viewer of this group may see which shift someone works.
        $shift = Shift::forEmployee($employee);
        $data['shift'] = $shift->name;
        // True when the employee has no shift of their own and works the default one.
        $data['shift_is_default'] = $employee->shift_id === null || (int) $shift->getKey() !== (int) $employee->shift_id;

        // The attendance machine's ID is for people who see everyone (Admin, HR), not for a Manager, even on their own record.
        if ($this->viewAll) {
            $data['device_user_id'] = $employee->device_user_id;
        }

        // Role and login state are for the people who manage employees.
        if ($this->manage) {
            $account = $employee->user()->first(['id', 'employee_id', 'is_active']);

            $data['role'] = EmployeeRoles::currentRole($account);
            $data['login'] = match (true) {
                $account === null => EmployeeListResource::LOGIN_NONE,
                $account->is_active => EmployeeListResource::LOGIN_ACTIVE,
                default => EmployeeListResource::LOGIN_DISABLED,
            };
            $data['attendance_exempt'] = (bool) $employee->attendance_exempt;
        }

        return $data;
    }

    /**
     * Direct reports the viewer may themselves view (the same scope as the list): id, name, code and designation only.
     *
     * @return list<array{id: int, name: string, code: string, designation: string}>
     */
    private function reports(): array
    {
        $reports = $this->access->scope(Employee::query()->where('manager_id', $this->resource->getKey()))
            ->with('designation:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'designation_id']);

        return array_values($reports->map(fn (Employee $report) => [
            'id' => $report->id,
            'name' => $report->full_name,
            'code' => $report->employee_code,
            'designation' => $report->designation->name,
        ])->all());
    }

    private function allows(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }
}
