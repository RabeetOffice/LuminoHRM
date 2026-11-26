<?php

namespace App\Actions\Leave;

use App\Mail\LeaveRequestDecided;
use App\Mail\LeaveRequestSubmitted;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\RebuildMarks;
use App\Support\Audit\Audit;
use App\Support\Leave\BalanceLine;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveDays;
use App\Support\Leave\LeaveRouting;
use App\Support\PayrollLock;
use DateTimeImmutable;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Deciding leave requests (manager step, final step) and cancelling approved leave. Modelled on
 * App\Actions\Attendance\CorrectionRequests: every state change runs in one transaction with the request row locked
 * and its state checked again inside it, so two approvers at once (or an approval and the employee's cancellation at
 * once) give one result and a clear message for the other, never a double write. Who may decide which step comes from
 * LeaveRouting only. Every change is audited (area leave, subject the employee, properties.request = the id) in the
 * same transaction. Mails are queued after the commit; a mail that cannot be queued is reported and never undoes the
 * decision.
 *
 * Final approval (approveFinal) re-checks everything at that moment, with the employee row and their balance rows
 * locked: the day count (holidays or shifts may have changed since applying; a changed count is used and audited, a
 * count now refused refuses the approval), overlap with other approved leave days (a full day and a half day on one
 * date clash; an am and a pm half do not — the database does not enforce this), each year's balance for paid types
 * (the request itself left out of pending), and the payroll lock. Then it writes one leave_days row per counted day
 * and marks those attendance days for a rebuild.
 *
 * Refusals and stale decisions are ValidationExceptions with the key "decision" (the controller shows them as a flash
 * message); a bad cancel-from date uses "from_date" and a missing reason "note" (shown on the form).
 */
class LeaveDecisions
{
    public function __construct(
        private readonly LeaveRouting $routing,
        private readonly LeaveBalances $balances,
        private readonly LeaveDayRemoval $removal,
        private readonly AttendanceClock $clock,
    ) {}

    /**
     * Whether $by may cancel this approved request now ($earliest: the LeaveRouting::earliestCancellableDate value).
     * The rule lives in LeaveRouting::mayCancelApprovedFrom; kept here for older callers.
     */
    public static function mayCancelApproved(LeaveRouting $routing, User $by, LeaveRequest $request, ?string $earliest): bool
    {
        return $routing->mayCancelApprovedFrom($by, $request, $earliest);
    }

    /**
     * The reporting manager's decision. Approval moves the request to the final step, or approves it outright when
     * LeaveRouting::managerApprovalIsFinal (HR step off, requester not Admin/HR). Rejection is final.
     *
     * @return string the success message
     *
     * @throws ValidationException a stale or refused decision
     */
    public function decideAsManager(User $by, LeaveRequest $request, bool $approve, ?string $note): string
    {
        $note = $this->cleanNote($note, $approve);
        $final = false;
        $outcome = null;

        $result = DB::transaction(function () use ($by, $request, $approve, $note, &$final, &$outcome): LeaveRequest {
            // A deadlock retry runs this again: start from a clean slate.
            $final = false;
            $outcome = null;
            $locked = $this->lock($request);
            // Nobody decides their own request; anyone who is not one of its deciders gets 403 before any message.
            abort_unless($this->routing->isApprover($by, $locked), 403);

            if ($locked->status !== 'pending') {
                throw $this->stale($locked);
            }

            if ($this->routing->decidableStage($by, $locked) !== LeaveRouting::STAGE_MANAGER) {
                abort_if($this->routing->stage($locked) === LeaveRouting::STAGE_MANAGER, 403);

                throw ValidationException::withMessages(['decision' => 'This request is waiting for HR, not for the manager.']);
            }

            $locked->fill([
                'manager_decided_by' => $by->id,
                'manager_decided_at' => now(),
                'decision_note' => $note,
            ]);

            if (! $approve) {
                $locked->fill(['status' => 'rejected'])->save();
                $this->audit('Leave rejected', $locked, $by, ['step' => 'manager', 'note' => $note]);

                return $locked;
            }

            if (! $this->routing->managerApprovalIsFinal($locked)) {
                $locked->fill(['status' => 'manager_approved'])->save();
                $this->audit('Leave approved by manager', $locked, $by, ['step' => 'manager', 'note' => $note]);

                return $locked;
            }

            $final = true;
            $outcome = $this->approveFinal($by, $locked);
            $this->audit('Leave approved', $locked, $by, ['step' => 'manager', 'final' => true, ...$outcome['audit'], 'note' => $note]);

            return $locked;
        }, 3);

        $name = $result->employee->full_name;

        if (! $approve) {
            $this->mailEmployee($result, 'rejected', $note);

            return "The leave request of {$name} was rejected.";
        }

        if (! $final) {
            foreach ($this->routing->finalApprovers($result->employee, $by) as $approver) {
                $this->queueMail($approver, new LeaveRequestSubmitted($result, $approver->name, true));
            }

            // The only active Admin's own request: they alone may give the final decision, so they are told too
            // (finalApprovers never lists the requester).
            $requester = User::query()->where('employee_id', $result->employee_id)->where('is_active', true)->first();
            if ($requester !== null && $this->routing->mayDecideFinal($requester, $result)) {
                $this->queueMail($requester, new LeaveRequestSubmitted($result, $requester->name, true));
            }

            return "Approved. The leave request of {$name} now waits for HR.";
        }

        $this->mailEmployee($result, 'approved', $note);

        return $this->approvedMessage($result, $outcome ?? []);
    }

    /**
     * The final decision (HR / Admin, LeaveRouting::mayDecideFinal). Deciding a request still waiting for the manager
     * skips the manager (manager_skipped, audited). HR who applied on the employee's behalf may give it (plan open
     * decision 11; audited as such), never on their own record.
     *
     * @throws ValidationException a stale or refused decision
     */
    public function decideFinal(User $by, LeaveRequest $request, bool $approve, ?string $note): string
    {
        $note = $this->cleanNote($note, $approve);
        $outcome = null;

        $result = DB::transaction(function () use ($by, $request, $approve, $note, &$outcome): LeaveRequest {
            $outcome = null; // a deadlock retry starts clean
            $locked = $this->lock($request);
            abort_unless($this->routing->isApprover($by, $locked), 403);

            if (! $locked->isOpen()) {
                throw $this->stale($locked);
            }

            // Re-checked under the lock: leave.approve-all, not the requester (sole-Admin rule aside), not the manager
            // who approved it, an Admin for an Admin / HR requester.
            abort_unless($this->routing->decidableStage($by, $locked) === LeaveRouting::STAGE_FINAL, 403);
            $skipped = $this->routing->stage($locked) === LeaveRouting::STAGE_MANAGER;

            $locked->fill([
                'hr_decided_by' => $by->id,
                'hr_decided_at' => now(),
                'decision_note' => $note,
            ]);

            if ($skipped) {
                $locked->manager_skipped = true;
            }

            $extra = [
                'step' => 'final',
                'manager_skipped' => $skipped ? true : null,
                // HR who applied on the employee's behalf gives the final approval too (open decision 11).
                'decided_by_applicant' => $locked->applied_by !== null && (int) $locked->applied_by === (int) $by->id ? true : null,
                // The only active Admin deciding their own request (the one self-decision allowed).
                'own_request' => $this->routing->isRequester($by, $locked) ? true : null,
            ];

            if (! $approve) {
                $locked->fill(['status' => 'rejected'])->save();
                $this->audit('Leave rejected', $locked, $by, [...$extra, 'note' => $note]);

                return $locked;
            }

            $outcome = $this->approveFinal($by, $locked);
            $this->audit('Leave approved', $locked, $by, [...$extra, ...$outcome['audit'], 'note' => $note]);

            return $locked;
        }, 3);

        if (! $approve) {
            $this->mailEmployee($result, 'rejected', $note);

            return "The leave request of {$result->employee->full_name} was rejected.";
        }

        $this->mailEmployee($result, 'approved', $note);

        return $this->approvedMessage($result, $outcome ?? []);
    }

    /**
     * HR / Admin cancel approved leave from $from on (default, owner may change: the design removes from today only;
     * HR may pick an earlier date when the person actually worked). $from must be one of the request's remaining days'
     * span: not before LeaveRouting::earliestCancellableDate (after any locked payroll month), not after its last day,
     * not in a locked month. Days from $from go (LeaveDayRemoval: balance refunded by derivation, attendance re-marked);
     * earlier days stay. No day left → cancelled; otherwise it stays approved with cancelled_from = $from ("partly
     * cancelled").
     *
     * Without $from the default is max(first cancellable day, today's attendance day), worked out under the lock: a
     * leave that has not started yet is cancelled whole; one whose last day is already past is refused (pick a date).
     *
     * @param  string|null  $from  "Y-m-d", or null for the default
     *
     * @throws ValidationException a stale or refused cancellation
     */
    public function cancelApproved(User $by, LeaveRequest $request, ?string $from, string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['note' => 'Say why the leave is cancelled (at least 5 characters). The employee will see this.']);
        }

        if ($from !== null && ! $this->isDate($from)) {
            throw ValidationException::withMessages(['from_date' => 'Choose the first day to cancel.']);
        }

        $summary = [];
        $manual = [];

        $result = DB::transaction(function () use ($by, $request, &$from, $reason, &$summary, &$manual): LeaveRequest {
            $summary = []; // a deadlock retry starts clean
            $manual = [];
            $locked = $this->lock($request);
            $own = $this->routing->isRequester($by, $locked);

            // HR / Admin who may administer this employee's leave; on their own request only the sole active Admin.
            abort_if(! $this->routing->mayAdminister($by, $locked->employee) || ($own && ! $this->routing->mayDecideFinal($by, $locked)), 403);

            if ($locked->status !== 'approved') {
                throw $this->stale($locked);
            }

            $earliest = $this->routing->earliestCancellableDate($locked);

            if ($earliest === null) {
                throw ValidationException::withMessages(['decision' => 'No day of this leave can be cancelled any more.']);
            }

            $all = $this->dayDates($locked);
            $last = $all === [] ? $earliest : $all[count($all) - 1];
            $from ??= max($earliest, $this->clock->attendanceToday());

            if ($from < $earliest || $from > $last) {
                throw ValidationException::withMessages([
                    'from_date' => "Choose a day from {$this->dayLabel($earliest)} to {$this->dayLabel($last)}.",
                ]);
            }

            if (PayrollLock::isLockedFor((int) $locked->employee_id, substr($from, 0, 7))) {
                throw ValidationException::withMessages(['from_date' => PayrollLock::messageForEmployee((int) $locked->employee_id, substr($from, 0, 7))]);
            }

            abort_unless(self::mayCancelApproved($this->routing, $by, $locked, $earliest), 403);

            $dates = array_values(array_filter($all, fn (string $d): bool => $d >= $from));
            $removed = $this->removal->remove($locked, $dates, 'Cancelled by HR', $by);

            $remaining = LeaveDay::query()->toBase()->where('leave_request_id', $locked->id)->pluck('amount');
            $halves = 0;
            foreach ($remaining as $amount) {
                $halves += LeaveDays::halves($amount);
            }

            $whole = $remaining->count() === 0;
            $locked->fill([
                'status' => $whole ? 'cancelled' : 'approved',
                'cancelled_from' => $whole ? null : $from,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                // A wholly cancelled request keeps the days it was approved for (shown on the screens and in the mail);
                // a partly cancelled one shows what remains. Balances never read this: they come from leave_days.
                'days' => $whole ? $locked->days : LeaveDays::fromHalves($halves),
            ])->save();

            $summary = ['whole' => $whole, ...$removed];
            $manual = $removed['manual'];

            $this->audit($whole ? 'Leave cancelled' : 'Leave partly cancelled', $locked, $by, [
                'step' => 'cancel',
                'cancel_from' => $from,
                'removed' => $removed['removed'],
                'refunded' => $removed['amount'],
                'kept_locked' => $removed['kept_locked'] === [] ? null : $removed['kept_locked'],
                // Hand-corrected days among the removed ones keep their leave status: HR is told (flash, request page).
                'manual' => $removed['manual'] === [] ? null : $removed['manual'],
                'days' => $whole ? 0 : $locked->days,
                'own_request' => $own ? true : null,
                'note' => $reason,
            ]);

            return $locked;
        }, 3);

        $this->mailEmployee($result, 'cancelled', $reason);

        $name = $result->employee->full_name;
        $refunded = $this->daysText((float) $summary['amount']);

        $manualNote = LeaveDayRemoval::manualNote($manual);

        return ($summary['whole']
            ? "The leave of {$name} was cancelled; {$refunded} went back to the balance."
            : "The leave of {$name} was cancelled from {$this->dayLabel($from)}; {$refunded} went back to the balance.").$manualNote;
    }

    /**
     * Writes the approval inside the caller's transaction (the request row is locked): see the class comment.
     *
     * @return array{audit: array<string, mixed>, recounted: array{0: float, 1: float}|null}
     *
     * @throws ValidationException when the approval no longer holds
     */
    private function approveFinal(User $by, LeaveRequest $locked): array
    {
        // The employee row was locked first by lock() (serialises with an application, a balance edit or another
        // approval for the same person).
        $employee = $locked->employee;
        $type = LeaveType::query()->findOrFail($locked->leave_type_id);
        $name = $employee->full_name;

        $from = $locked->from_date->format('Y-m-d');
        $to = $locked->to_date->format('Y-m-d');
        $half = $locked->is_half_day ? $locked->half : null;

        $plan = LeaveDays::plan($employee, $from, $to, $half);

        if ($plan->refusal === LeaveDays::REFUSAL_NOTHING_TO_COUNT) {
            throw ValidationException::withMessages(['decision' => 'Nothing left to approve: these dates are now holidays or weekly offs.']);
        }

        if ($plan->isRefused()) {
            throw ValidationException::withMessages(['decision' => "This leave can no longer be approved: {$plan->refusal}"]);
        }

        $dates = $plan->dates();

        $lockedMonth = PayrollLock::firstLockedFor((int) $employee->id, $dates);
        if ($lockedMonth !== null) {
            throw ValidationException::withMessages(['decision' => PayrollLock::messageForEmployee((int) $employee->id, $lockedMonth)]);
        }

        // Other approved leave on these dates: a full day clashes with anything; a half only with the same half.
        $clash = LeaveDay::query()->toBase()
            ->where('employee_id', $employee->id)
            ->where('leave_request_id', '!=', $locked->id)
            ->whereIn('date', $dates)
            ->orderBy('date')
            ->get(['date', 'half'])
            ->first(fn (object $d): bool => $half === null || $d->half === 'full' || $d->half === $half);

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'decision' => "{$name} already has approved leave on {$this->dayLabel(substr((string) $clash->date, 0, 10))}.",
            ]);
        }

        $balanceBefore = null;

        if (! $type->isUnpaid()) {
            $shares = $plan->byYear();
            $years = array_keys($shares);

            foreach ($years as $year) {
                $this->balances->ensureYear($employee, $year);
                $this->balances->ensureRow($employee, $type, $year); // also for an inactive type ensureYear skips
            }

            // This request's shares count as pending (also in the carry walk), and every year up to the last year in
            // use must stay at or above zero: a share in Y lowers what carries into Y+1 (LeaveBalances::line()).
            $checkTo = max((int) end($years), $this->balances->lastYearInUse($employee, $type, $locked->id) ?? 0);

            LeaveBalance::query()->where('employee_id', $employee->id)->whereBetween('year', [$years[0], $checkTo])->lockForUpdate()->get();

            $balanceBefore = [];

            for ($year = $years[0]; $year <= $checkTo; $year++) {
                $after = $this->balances->line($employee, $type, $year, $locked->id, extraPending: $shares)->available;
                $asked = $shares[$year] ?? null;
                $short = LeaveDays::halves($after) < 0;

                if ($asked === null) {
                    if ($short) {
                        throw ValidationException::withMessages([
                            'decision' => "Not enough {$type->name} leave: these days would be taken from what carries into {$year}, and {$year} would end "
                                .$this->daysText(-$after).' short. Reject it, or ask for the extra days as Unpaid leave.',
                        ]);
                    }

                    continue;
                }

                $available = LeaveDays::fromHalves(LeaveDays::halves($after) + LeaveDays::halves($asked));
                $balanceBefore[(string) $year] = $available;

                if ($short) {
                    throw ValidationException::withMessages([
                        'decision' => "Not enough {$type->name} leave for {$year}: ".BalanceLine::format($available)
                            .' left, '.BalanceLine::format($asked).' needed. Reject it, or ask for the extra days as Unpaid leave.',
                    ]);
                }
            }
        }

        $stamp = now();
        $rows = array_map(fn (array $day): array => [
            'leave_request_id' => $locked->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'date' => $day['date'],
            'amount' => $day['amount'],
            'half' => $day['half'],
            'created_at' => $stamp,
        ], $plan->days);

        $inserted = LeaveDay::query()->insertOrIgnore($rows);

        if ($inserted !== count($rows)) {
            // A row with the same employee, date and half exists already: someone wrote these days first.
            throw ValidationException::withMessages(['decision' => 'This leave was changed by someone else meanwhile. Reload the page.']);
        }

        $old = (float) $locked->days;
        $recounted = LeaveDays::halves($old) !== LeaveDays::halves($plan->total) ? [$old, $plan->total] : null;

        // balance_before keeps what applying stored (the balance the employee saw); the available days right before
        // this approval go into the audit entry only.
        $locked->fill([
            'status' => 'approved',
            'days' => $plan->total,
        ])->save();

        RebuildMarks::forLeaveDays($employee->id, $dates);

        return [
            'audit' => [
                'days' => $plan->total,
                'dates' => $dates,
                'recounted' => $recounted === null ? null : ['old' => $recounted[0], 'new' => $recounted[1]],
                'balance_before' => $balanceBefore,
            ],
            'recounted' => $recounted,
        ];
    }

    /**
     * The first statements of every transaction here. MariaDB runs REPEATABLE READ: the first plain read fixes the
     * snapshot, so the employee row is locked FIRST (it serialises with applications, balance edits and other decisions
     * for the same person), then the request row; both are locking reads, so they see the latest committed state. The
     * employee relation is set from the locked row (no plain read before the locks).
     */
    private function lock(LeaveRequest $request): LeaveRequest
    {
        $employee = Employee::query()->whereKey($request->employee_id)->lockForUpdate()->firstOrFail();
        $locked = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        abort_if((int) $locked->employee_id !== (int) $employee->id, 403);
        $locked->setRelation('employee', $employee);

        return $locked;
    }

    private function stale(LeaveRequest $request): ValidationException
    {
        $what = match ($request->status) {
            'manager_approved' => 'This request was already approved by the manager.',
            'approved' => 'This request was already approved.',
            'rejected' => 'This request was already rejected.',
            'cancelled' => 'This request was cancelled.',
            default => 'This request has changed. Reload the page.',
        };

        return ValidationException::withMessages(['decision' => $what]);
    }

    /** A rejection needs a reason of 5 to 500 characters (the employee sees it); an approval note is optional. */
    private function cleanNote(?string $note, bool $approve): ?string
    {
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if (! $approve && ($note === null || mb_strlen($note) < 5)) {
            throw ValidationException::withMessages(['note' => 'Say why the request is rejected (at least 5 characters). The employee will see this note.']);
        }

        if ($note !== null && mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => 'The note may be at most 500 characters.']);
        }

        return $note;
    }

    /**
     * The request's leave days, "Y-m-d" ascending.
     *
     * @return list<string>
     */
    private function dayDates(LeaveRequest $request): array
    {
        return array_values(array_unique(array_map(
            fn ($d): string => substr((string) $d, 0, 10),
            LeaveDay::query()->toBase()->where('leave_request_id', $request->id)->orderBy('date')->pluck('date')->all(),
        )));
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $description, LeaveRequest $request, User $by, array $properties): void
    {
        $note = $properties['note'] ?? null;

        Audit::record('leave', $description, $request->employee, array_filter([
            'request' => $request->id,
            'type' => $request->type()->value('name'),
            'from' => $request->from_date->format('Y-m-d'),
            'to' => $request->to_date->format('Y-m-d'),
            'half' => $request->is_half_day ? $request->half : null,
            ...$properties,
            'note' => is_string($note) ? Audit::clean($note, 500) : null,
        ], fn ($v) => $v !== null), $by);
    }

    /** @param  array{audit?: array<string, mixed>, recounted?: array{0: float, 1: float}|null}  $outcome */
    private function approvedMessage(LeaveRequest $request, array $outcome): string
    {
        $message = "Approved. {$request->employee->full_name}'s leave of {$this->daysText((float) $request->days)} is recorded.";

        if (($outcome['recounted'] ?? null) !== null) {
            [$old, $new] = $outcome['recounted'];
            $message .= " The days were counted again because holidays or shifts changed since applying: {$this->daysText($new)} instead of {$this->daysText($old)}.";
        }

        return $message;
    }

    private function mailEmployee(LeaveRequest $request, string $outcome, ?string $note): void
    {
        $user = User::query()->where('employee_id', $request->employee_id)->where('is_active', true)->first();

        if ($user !== null) {
            $this->queueMail($user, new LeaveRequestDecided($request, $user->name, $outcome, $note));
        }
    }

    /** Queues one mail; a failure (no queue table, bad mail config) is reported and never undoes the decision. */
    private function queueMail(?User $to, Mailable $mail): void
    {
        if ($to === null || ! $to->is_active) {
            return;
        }

        try {
            Mail::to($to)->queue($mail);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function isDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** "Mon 1 Dec 2026" from "Y-m-d" (no time zone involved). */
    private function dayLabel(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed === false ? $date : $parsed->format('D j M Y');
    }

    private function daysText(float $days): string
    {
        return BalanceLine::format($days).($days === 1.0 || $days === 0.5 ? ' day' : ' days');
    }
}
