<?php

namespace App\Actions\Attendance;

use App\Mail\CorrectionRequestDecided;
use App\Mail\CorrectionRequestSubmitted;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Attendance\CorrectionEligibility;
use App\Support\Attendance\CorrectionRouting;
use App\Support\Audit\Audit;
use App\Support\PayrollLock;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Submitting, deciding and cancelling attendance correction requests. Every state change runs in one transaction with
 * the request row locked and its state checked again inside it, so two approvers clicking at once (or an approval and
 * a cancellation at once) give one result and a clear message for the other, never a double apply. Every change is
 * audited (area attendance, subject the employee) in the same transaction. Mails are queued after the commit; a mail
 * that cannot be queued is reported and never undoes the request.
 *
 * Approval applies the requested times through ApplyManualCorrection (the same code path as a correction on the Daily
 * screen) with the note "Correction request #<id>", inside the same transaction as the status change.
 *
 * Payroll lock: a day in an approved (or paid) payroll month cannot be requested (CorrectionEligibility, at submit) and
 * an open request for one cannot be applied by HR ([decision] PayrollLock::message; the request stays open and can be
 * rejected). Manager decisions and rejections change no attendance and are not refused.
 */
class CorrectionRequests
{
    public function __construct(
        private readonly CorrectionEligibility $eligibility,
        private readonly CorrectionRouting $routing,
        private readonly ApplyManualCorrection $apply,
        private readonly AttendanceRebuilder $rebuilder,
    ) {}

    /**
     * A request by $by for their own employee record. A requested time left empty (null) means "keep what is
     * recorded": it is stored as null and resolved to the time recorded at the moment of approval, so a punch that
     * arrives after the request is not erased. The times are checked now against the recorded side they will be
     * combined with.
     *
     * @throws ValidationException
     */
    public function submit(User $by, Employee $employee, string $date, ?string $clockIn, ?string $clockOut, string $reason): AttendanceCorrection
    {
        $correction = DB::transaction(function () use ($by, $employee, $date, $clockIn, $clockOut, $reason): AttendanceCorrection {
            // One open request per day: two submissions at once wait here, and the second sees the first. There is no
            // unique index behind this rule, so this locking read must stay the first statement of the transaction.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $refused = $this->eligibility->reasonRefused($employee, $date);
            if ($refused !== null) {
                throw ValidationException::withMessages(['date' => $refused]);
            }

            if ($clockIn === null && $clockOut === null) {
                throw ValidationException::withMessages(['clock_in' => 'Enter the correct clock-in or clock-out time.']);
            }

            $row = Attendance::query()->where('employee_id', $employee->id)->onDate($date)->first();
            $errors = $this->eligibility->timeErrors(
                $clockIn ?? $this->hm($row?->getAttributes()['clock_in'] ?? null),
                $clockOut ?? $this->hm($row?->getAttributes()['clock_out'] ?? null),
            );
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $correction = AttendanceCorrection::create([
                'employee_id' => $employee->id,
                'date' => $date,
                'requested_in' => $clockIn,
                'requested_out' => $clockOut,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            Audit::record('attendance', 'Correction requested', $employee, [
                'request' => $correction->id,
                'date' => $date,
                'current' => [
                    'clock_in' => $this->naive($row?->getAttributes()['clock_in'] ?? null),
                    'clock_out' => $this->naive($row?->getAttributes()['clock_out'] ?? null),
                ],
                'requested' => ['clock_in' => $clockIn, 'clock_out' => $clockOut],
                'reason' => Audit::clean($reason),
            ], $by);

            return $correction;
        });

        $correction->setRelation('employee', $employee);
        $approvers = $this->routing->stage($correction) === CorrectionRouting::STAGE_MANAGER
            ? collect([$this->routing->managerApprover($employee)])
            : $this->routing->hrApprovers($employee);

        foreach ($approvers as $approver) {
            $this->queueMail($approver, new CorrectionRequestSubmitted($correction, $approver->name, false));
        }

        return $correction;
    }

    /**
     * The reporting manager's decision. Approval moves the request to HR; rejection is final.
     *
     * @return string the message for the flash (success)
     *
     * @throws ValidationException a stale or refused decision (key: decision)
     */
    public function decideAsManager(User $by, AttendanceCorrection $correction, bool $approve, ?string $note): string
    {
        $result = DB::transaction(function () use ($by, $correction, $approve, $note): AttendanceCorrection {
            $locked = $this->lock($correction);
            // Nobody decides their own request; anyone who is not one of its deciders gets 403 before any message.
            abort_unless($this->routing->isApprover($by, $locked), 403);

            if ($locked->status !== 'pending') {
                throw $this->stale($locked);
            }

            if ($this->routing->decidableStage($by, $locked) !== CorrectionRouting::STAGE_MANAGER) {
                abort_if($this->routing->stage($locked) === CorrectionRouting::STAGE_MANAGER, 403);

                throw ValidationException::withMessages(['decision' => 'This request is waiting for HR, not for the manager.']);
            }

            $locked->fill([
                'status' => $approve ? 'manager_approved' : 'rejected',
                'manager_id' => $by->id,
                'manager_decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            Audit::record('attendance', $approve ? 'Correction request approved by manager' : 'Correction request rejected', $locked->employee, array_filter([
                'request' => $locked->id,
                'date' => $locked->date->format('Y-m-d'),
                'step' => 'manager',
                'note' => $note !== null ? Audit::clean($note, 500) : null,
            ], fn ($v) => $v !== null), $by);

            return $locked;
        });

        if ($approve) {
            foreach ($this->routing->hrApprovers($result->employee, $by) as $approver) {
                $this->queueMail($approver, new CorrectionRequestSubmitted($result, $approver->name, true));
            }

            return "Approved. The request for {$result->employee->full_name} now waits for HR.";
        }

        $this->mailRequester($result);

        return "The request for {$result->employee->full_name} was rejected.";
    }

    /**
     * HR's decision (final). Approval applies the requested times as a manual correction; a requested side left
     * empty takes the time recorded now (read under the row lock). Deciding a request that still waits for the
     * manager skips the manager (audited as manager_skipped). Only a user who may correct this employee's record
     * (ApplyManualCorrection::mayCorrect) decides here: an HR or Admin requester needs an Admin.
     *
     * HR's note replaces the manager's in decision_note (the manager's note stays in the manager's audit entry), so
     * the column always holds the note of whoever decided last.
     *
     * @throws ValidationException a stale or refused decision (key: decision)
     */
    public function decideAsHr(User $by, AttendanceCorrection $correction, bool $approve, ?string $note): string
    {
        $result = DB::transaction(function () use ($by, $correction, $approve, $note): AttendanceCorrection {
            $locked = $this->lock($correction);
            // Nobody decides their own request; anyone who is not one of its deciders gets 403 before any message.
            abort_unless($this->routing->isApprover($by, $locked), 403);

            if (! in_array($locked->status, CorrectionEligibility::OPEN_STATUSES, true)) {
                throw $this->stale($locked);
            }

            // Re-checked under the lock: attendance.edit, may correct this record, not the manager who approved it.
            abort_unless($this->routing->decidableStage($by, $locked) === CorrectionRouting::STAGE_HR, 403);
            $skipped = $this->routing->stage($locked) === CorrectionRouting::STAGE_MANAGER;

            $date = $locked->date->format('Y-m-d');
            $applied = null;

            if ($approve) {
                // A month whose payroll was approved after the request was made: refused like a stale decision, the
                // request stays where it is (it can still be rejected). Shared lock on payroll_runs before the
                // attendance row lock (lock order: request row, payroll run, attendance row).
                $month = substr($date, 0, 7);
                if (PayrollLock::isLocked($month)) {
                    throw ValidationException::withMessages(['decision' => PayrollLock::message($month)]);
                }

                // The person's month frozen by an approved final settlement (shared lock on that row, before the
                // attendance row lock).
                if (PayrollLock::isSettled((int) $locked->employee_id, $month)) {
                    throw ValidationException::withMessages(['decision' => PayrollLock::messageFor($month, true)]);
                }

                $row = Attendance::query()->where('employee_id', $locked->employee_id)->onDate($date)->lockForUpdate()->first();

                // A day still pending calculation (status null: punches stored, times not worked out yet) is worked out
                // first, inside this transaction (the rebuild nests as a savepoint and takes the same row lock), so
                // an empty requested side is resolved against the real recorded times, never against null.
                if ($row !== null && ! $row->is_manual && $row->status === null) {
                    try {
                        $this->rebuilder->rebuild($locked->employee, $date);
                    } catch (Throwable) {
                        throw ValidationException::withMessages([
                            'decision' => 'This day is still being calculated. Try again in a minute.',
                        ]);
                    }

                    $row = Attendance::query()->where('employee_id', $locked->employee_id)->onDate($date)->lockForUpdate()->first();
                }

                // A request can only be made for a day that was not corrected by hand, so a manual day now means HR
                // corrected it after the request: never overwrite that silently.
                if ($row !== null && $row->is_manual) {
                    throw ValidationException::withMessages([
                        'decision' => 'This day was corrected by hand after the request was made. Reject the request, or remove the correction on the Daily screen first.',
                    ]);
                }

                // A side the employee left empty keeps what is recorded now, not what was recorded at submit.
                $applied = [
                    'clock_in' => $locked->requested_in ?? $this->hm($row?->getAttributes()['clock_in'] ?? null),
                    'clock_out' => $locked->requested_out ?? $this->hm($row?->getAttributes()['clock_out'] ?? null),
                ];

                try {
                    $this->apply->handle(
                        $locked->employee,
                        $date,
                        $applied['clock_in'],
                        $applied['clock_out'],
                        null,
                        $locked->reason,
                        $by,
                        "Correction request #{$locked->id}",
                    );
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages([
                        'decision' => 'The requested times cannot be applied: '.collect($e->errors())->flatten()->first(),
                    ]);
                }
            }

            $locked->fill([
                'status' => $approve ? 'approved' : 'rejected',
                'hr_id' => $by->id,
                'hr_decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            Audit::record('attendance', $approve ? 'Correction request approved' : 'Correction request rejected', $locked->employee, array_filter([
                'request' => $locked->id,
                'date' => $date,
                'step' => 'hr',
                'manager_skipped' => $skipped ? true : null,
                'applied' => $applied,
                'note' => $note !== null ? Audit::clean($note, 500) : null,
            ], fn ($v) => $v !== null), $by);

            return $locked;
        });

        $this->mailRequester($result);

        return $approve
            ? "Approved. Attendance for {$result->employee->full_name} on that day was corrected."
            : "The request for {$result->employee->full_name} was rejected.";
    }

    /**
     * The requester withdraws a request nobody has decided yet.
     *
     * @throws ValidationException a stale cancellation (key: decision)
     */
    public function cancel(User $by, AttendanceCorrection $correction): void
    {
        DB::transaction(function () use ($by, $correction): void {
            $locked = $this->lock($correction);

            abort_unless($this->routing->isRequester($by, $locked), 403);

            if ($locked->status !== 'pending') {
                throw $this->stale($locked, 'It can no longer be cancelled.');
            }

            $locked->fill(['status' => 'cancelled'])->save();

            Audit::record('attendance', 'Correction request cancelled', $locked->employee, [
                'request' => $locked->id,
                'date' => $locked->date->format('Y-m-d'),
            ], $by);
        });
    }

    private function lock(AttendanceCorrection $correction): AttendanceCorrection
    {
        return AttendanceCorrection::query()->with('employee')->whereKey($correction->id)->lockForUpdate()->firstOrFail();
    }

    private function stale(AttendanceCorrection $c, string $tail = ''): ValidationException
    {
        $what = match ($c->status) {
            'manager_approved' => 'This request was already approved by the manager.',
            'approved' => 'This request was already approved.',
            'rejected' => 'This request was already rejected.',
            'cancelled' => 'This request was cancelled by the employee.',
            default => 'This request has changed.',
        };

        return ValidationException::withMessages(['decision' => trim("{$what} {$tail}")]);
    }

    private function mailRequester(AttendanceCorrection $correction): void
    {
        $user = User::query()->where('employee_id', $correction->employee_id)->where('is_active', true)->first();

        if ($user !== null) {
            $this->queueMail($user, new CorrectionRequestDecided($correction, $user->name));
        }
    }

    /** Queues one mail; a failure (no queue table, bad mail config) is reported and never undoes the request. */
    private function queueMail(?User $to, Mailable $mail): void
    {
        if ($to === null) {
            return;
        }

        try {
            Mail::to($to)->queue($mail);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function hm(mixed $naive): ?string
    {
        return $naive === null ? null : substr((string) $naive, 11, 5);
    }

    private function naive(mixed $naive): ?string
    {
        return $naive === null ? null : substr((string) $naive, 0, 16);
    }
}
