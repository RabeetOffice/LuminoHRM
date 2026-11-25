<?php

namespace App\Http\Controllers\Leave;

use App\Actions\Leave\ApplyForLeave;
use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveApplicationRequest;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\EmployeeFiles;
use App\Support\Leave\LeaveRouting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Applying for leave, its live preview, the attachment and cancelling while open. Any signed-in user; the rights are
 * checked here and in ApplyForLeave:
 *
 *   POST /leave/requests                              own record, or employee_id of someone LeaveRouting::mayAdminister allows
 *   GET  /leave/requests/preview?employee=&type=&from=&to=&half=   same rule; JSON; throttled 60/min
 *   GET  /leave/requests/{request}/attachment         LeaveRouting::findVisible (requester, their approvers, HR)
 *   POST /leave/requests/{request}/cancel             the requester, or HR / Admin (canCancelOpen), while open
 *
 * An employee id or request id out of the user's reach and one that does not exist look alike: 403 (never 404).
 * The decisions and the request screens are LeaveRequestController (Task 6).
 */
class LeaveApplicationController extends Controller
{
    public function __construct(private readonly LeaveRouting $routing) {}

    public function store(LeaveApplicationRequest $request, ApplyForLeave $apply): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $employee = $this->employeeFor($user, $request->validated('employee_id'), $apply);
        $type = LeaveType::query()->findOrFail((int) $request->validated('leave_type_id'));

        $created = $apply->handle(
            $user,
            $employee,
            $type,
            (string) $request->validated('from_date'),
            (string) $request->validated('to_date'),
            $request->validated('half'),
            (string) $request->validated('reason'),
            $request->file('attachment'),
        );

        return back()->with('success', $this->routing->isRequester($user, $created)
            ? 'Leave request sent.'
            : "Leave request sent for {$employee->full_name}.");
    }

    public function preview(Request $request, ApplyForLeave $apply): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'employee' => ['bail', 'nullable', 'integer'],
            'type' => ['bail', 'required', 'integer'],
            'from' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'to' => ['bail', 'nullable', 'string', 'date_format:Y-m-d'],
            'half' => ['bail', 'nullable', 'string', 'in:am,pm'],
        ]);

        $employee = $this->employeeFor($user, $data['employee'] ?? null, $apply);
        $type = LeaveType::query()->find((int) $data['type']);
        $half = $data['half'] ?? null;
        $from = $data['from'];
        $to = $half !== null ? $from : ($data['to'] ?? $from);

        if ($type === null) {
            throw ValidationException::withMessages(['type' => 'Choose a leave type.']);
        }

        if ($to < $from) {
            return response()->json(['days' => [], 'total' => 0, 'years' => [], 'refusal' => 'The end date is before the start date.', 'overlap' => null]);
        }

        return response()->json($apply->preview($user, $employee, $type, $from, $to, $half));
    }

    public function attachment(Request $request, int $leaveRequest): BinaryFileResponse
    {
        /** @var User $user */
        $user = $request->user();
        $found = $this->routing->findVisible($user, $leaveRequest);
        abort_if($found === null, 403);

        $path = $found->attachment_path;
        abort_unless(ApplyForLeave::isAttachmentPath($found->employee_id, $path) && EmployeeFiles::disk()->exists((string) $path), 404);

        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg'][$extension];

        $response = response()->download(EmployeeFiles::disk()->path((string) $path), "leave-{$found->id}.{$extension}", [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function cancel(Request $request, ApplyForLeave $apply, int $leaveRequest): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $found = $this->routing->findVisible($user, $leaveRequest);
        abort_if($found === null, 403);

        try {
            $apply->cancelOpen($user, $found);
        } catch (ValidationException $e) {
            return back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Leave request cancelled.');
    }

    /** The record applied for: the user's own, or (employee id given) someone they may administer; else 403. */
    private function employeeFor(User $user, mixed $employeeId, ApplyForLeave $apply): Employee
    {
        if ($employeeId === null || (int) $employeeId === (int) $user->employee_id) {
            abort_if($user->employee_id === null, 403, 'Your login is not linked to an employee record.');

            return Employee::query()->findOrFail($user->employee_id);
        }

        $employee = Employee::query()->find((int) $employeeId);
        abort_if($employee === null || ! $apply->mayApply($user, $employee), 403);

        return $employee;
    }
}
