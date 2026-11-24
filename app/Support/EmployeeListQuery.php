<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The employees list's filters and query, in one place: the list page (EmployeeController::index) and the Excel export
 * (EmployeeExportController) both read the same query parameters through this class, so an export is exactly the
 * rows the list shows (all pages of them).
 *
 * Query parameters: search (100 chars, trimmed), department_id, designation_id (digits only and must exist; a
 * designation must belong to the chosen department; otherwise ignored, never an error), status (one of
 * hrm.employees.statuses, else ignored). Rows always go through EmployeeAccess::scope.
 */
final class EmployeeListQuery
{
    private function __construct(
        private readonly EmployeeAccess $access,
        public readonly string $search,
        public readonly ?int $departmentId,
        public readonly ?int $designationId,
        public readonly string $status,
    ) {}

    public static function fromRequest(Request $request, User $viewer): self
    {
        $departmentId = self::existingId($request, 'department_id', Department::query());
        // With a department chosen, only that department's designations count.
        $designationId = self::existingId(
            $request,
            'designation_id',
            Designation::query()->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId)),
        );

        return new self(
            new EmployeeAccess($viewer),
            ListQuery::stringParam($request, 'search'),
            $departmentId,
            $designationId,
            self::statusFilter($request),
        );
    }

    /**
     * Everything the viewer may see, narrowed by every filter except the status tab (the tab counts use this).
     *
     * @return Builder<Employee>
     */
    public function base(): Builder
    {
        return $this->access->scope(Employee::query())
            ->search($this->search)
            ->when($this->departmentId !== null, fn (Builder $q) => $q->where('department_id', $this->departmentId))
            ->when($this->designationId !== null, fn (Builder $q) => $q->where('designation_id', $this->designationId));
    }

    /**
     * Every filter, the status tab included, in the list's order (last name, first name, id).
     *
     * @return Builder<Employee>
     */
    public function rows(): Builder
    {
        return $this->base()
            ->when($this->status !== '', fn (Builder $q) => $q->status($this->status))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');
    }

    /**
     * The filters as applied (for the page props and the export's audit entry).
     *
     * @return array{search: string, department_id: int|null, designation_id: int|null, status: string}
     */
    public function filters(): array
    {
        return [
            'search' => $this->search,
            'department_id' => $this->departmentId,
            'designation_id' => $this->designationId,
            'status' => $this->status,
        ];
    }

    /**
     * The id in the query parameter if it names a row of $rows; null for anything else (never an error).
     *
     * @param  Builder<Department>|Builder<Designation>  $rows
     */
    private static function existingId(Request $request, string $key, Builder $rows): ?int
    {
        $value = $request->query($key);

        if (! is_scalar($value) || ! ctype_digit((string) $value) || strlen((string) $value) > 9) {
            return null;
        }

        $id = (int) $value;

        return $rows->whereKey($id)->exists() ? $id : null;
    }

    private static function statusFilter(Request $request): string
    {
        $value = $request->query('status');

        return is_string($value) && in_array($value, config('hrm.employees.statuses'), true) ? $value : '';
    }
}
