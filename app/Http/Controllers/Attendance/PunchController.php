<?php

namespace App\Http\Controllers\Attendance;

use App\Actions\Employees\UpdateEmployee;
use App\Exceptions\AccountRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\EmployeeAccess;
use App\Support\EmployeeRoles;
use App\Support\ListQuery;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance > Raw Punches: every punch the machines sent, newest first (attendance.view-all). An unknown machine user ID
 * (a punch no employee was matched to) can be given to an employee here (attendance.edit + employees.manage). That goes
 * through UpdateEmployee, exactly like the employee form, so the stored punches are relinked, their days marked for a
 * rebuild and the change audited in one place.
 *
 * Filters are applied in SQL on indexed columns: punch_date (the attendance day), device_id, employee_id (null for
 * "Unknown IDs only") and device_user_id.
 */
class PunchController extends Controller
{
    /** What a machine's verify code means (ZKTeco push protocol). Anything else is shown as its number. */
    public const VERIFY_TYPES = [
        '0' => 'Password',
        '1' => 'Fingerprint',
        '2' => 'Card',
        '3' => 'Password',
        '4' => 'Card',
        '15' => 'Face',
        '25' => 'Palm',
    ];

    public function index(Request $request, AttendanceClock $clock): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $this->filters($request);
        $perPage = ListQuery::perPage($request);

        $query = DevicePunch::query()
            ->select(['id', 'device_id', 'device_user_id', 'employee_id', 'punch_time', 'punch_date', 'verify_type', 'status_code', 'source'])
            ->with(['device:id,name', 'employee:id,first_name,last_name,employee_code'])
            ->when($filters['from'] !== '', fn (Builder $q) => $q->where('punch_date', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $q) => $q->where('punch_date', '<=', $filters['to']))
            ->when($filters['device'] !== null, fn (Builder $q) => $q->where('device_id', $filters['device']))
            ->when($filters['unknown'], fn (Builder $q) => $q->whereNull('employee_id'))
            ->when(! $filters['unknown'] && $filters['employee'] !== null, fn (Builder $q) => $q->where('employee_id', $filters['employee']))
            ->when($filters['user'] !== '', fn (Builder $q) => $q->where('device_user_id', $filters['user']));

        // attendance.view-all is Admin / HR, who see everyone; should a role ever hold it without seeing every employee,
        // punches of people outside their scope stay out (unknown IDs belong to no one and are kept).
        if (! $user->can('employees.view-all')) {
            $visible = (new EmployeeAccess($user))->scope(Employee::query())->select('id');
            $query->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhereIn('employee_id', $visible));
        }

        $paginator = $query
            ->orderByDesc('punch_time')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', ListQuery::page($request));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        $access = new EmployeeAccess($user);

        return Inertia::render('attendance/punches', [
            'punches' => [
                'data' => $paginator->getCollection()->map(fn (DevicePunch $p) => $this->row($p))->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [...$filters, 'per_page' => $perPage],
            'devices' => Device::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])->all(),
            'employees' => $access->scope(Employee::query())
                ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
                ->get(['id', 'first_name', 'last_name', 'employee_code', 'device_user_id'])
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->full_name,
                    'code' => $e->employee_code,
                    'device_user_id' => $e->device_user_id,
                ])
                ->values()
                ->all(),
            'today' => $clock->attendanceToday(),
            'can' => [
                'assign' => $user->can('attendance.edit') && $access->canManage(),
            ],
        ]);
    }

    /** Gives an unknown machine user ID to an employee, through the employee update path. */
    public function assign(Request $request, UpdateEmployee $update): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $access = new EmployeeAccess($user);

        // Writing an employee record needs the employee-management permission, as on the employee form.
        abort_unless($access->canManage(), 403);

        $request->merge(['device_user_id' => is_string($request->input('device_user_id')) ? trim($request->input('device_user_id')) : $request->input('device_user_id')]);

        $data = $request->validate([
            'device_user_id' => ['required', 'string', 'max:20'],
            'employee_id' => ['required', 'integer'],
        ], [], ['device_user_id' => 'machine user ID', 'employee_id' => 'employee']);

        $id = $data['device_user_id'];
        $employee = Employee::query()->with('user')->find((int) $data['employee_id']);

        if ($employee === null || ! $access->canView($employee)) {
            throw ValidationException::withMessages(['employee_id' => 'Choose an employee from the list.']);
        }

        if (! DevicePunch::query()->whereNull('employee_id')->where('device_user_id', $id)->exists()) {
            throw ValidationException::withMessages(['device_user_id' => 'No unknown punches have this machine user ID.']);
        }

        $holder = Employee::query()->where('device_user_id', $id)->first(['id', 'first_name', 'last_name']);

        if ($holder !== null) {
            throw ValidationException::withMessages([
                'device_user_id' => "This machine user ID already belongs to {$holder->full_name}. Their punches from before the joining date stay unlinked.",
            ]);
        }

        if ($employee->device_user_id !== null && $employee->device_user_id !== '') {
            throw ValidationException::withMessages([
                'employee_id' => "{$employee->full_name} already has machine user ID {$employee->device_user_id}. Change it on their employee form.",
            ]);
        }

        // A privileged account (Admin or HR) is only edited by an Admin, as on the employee form.
        if (! EmployeeRoles::mayTouchAccount($user, $employee->user)) {
            throw ValidationException::withMessages(['employee_id' => EmployeeRoles::PRIVILEGED_MESSAGE]);
        }

        // A refusal from the update path itself (for example the login rules when an account is created) comes back as a
        // validation error on its own field, as on the employee form.
        try {
            $update->handle($employee, ['device_user_id' => $id], $user);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['device_user_id' => 'This machine user ID is already used by another employee.']);
        } catch (AccountRuleViolation $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Machine user ID {$id} assigned to {$employee->full_name}. Their punches will be worked into attendance within a minute.";

        if ($update->createdAccount()) {
            $message .= $update->linkSent()
                ? ' A login was created for them and a link to set a password was emailed.'
                : ' A login was created for them, but the set-password email could not be sent.';
        }

        return back()->with('success', $message);
    }

    /**
     * The filters as applied: dates are valid "Y-m-d" or '', ids are positive ints or null.
     *
     * @return array{from: string, to: string, device: int|null, employee: int|null, unknown: bool, user: string}
     */
    private function filters(Request $request): array
    {
        $from = $this->dateParam($request, 'from');
        $to = $this->dateParam($request, 'to');

        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from,
            'to' => $to,
            'device' => $this->idParam($request, 'device'),
            'employee' => $this->idParam($request, 'employee'),
            'unknown' => in_array($request->query('unknown'), ['1', 'true'], true),
            'user' => mb_substr(ListQuery::stringParam($request, 'user'), 0, 20),
        ];
    }

    private function dateParam(Request $request, string $key): string
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return '';
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : '';
    }

    private function idParam(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_string($value) && ctype_digit($value) && strlen($value) <= 9 && (int) $value > 0 ? (int) $value : null;
    }

    /** @return array<string, mixed> */
    private function row(DevicePunch $punch): array
    {
        // The stored wall-clock string, read as it is (never shifted).
        $time = (string) $punch->getRawOriginal('punch_time');
        $date = substr($time, 0, 10);
        $verify = $punch->verify_type;

        return [
            'id' => $punch->id,
            'date' => $date,
            'time' => substr($time, 11, 8),
            // The attendance day it counts for (before the day cutoff: the previous day).
            'punch_date' => $punch->punch_date,
            'device_user_id' => $punch->device_user_id,
            'employee' => $punch->employee === null ? null : [
                'id' => $punch->employee->id,
                'name' => $punch->employee->full_name,
                'code' => $punch->employee->employee_code,
            ],
            'device' => $punch->device?->name,
            'verify' => $verify === null || $verify === '' ? null : (self::VERIFY_TYPES[$verify] ?? "Code {$verify}"),
            'source' => $punch->source,
        ];
    }
}
