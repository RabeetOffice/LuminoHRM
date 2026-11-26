<?php

namespace App\Actions\Leave;

use App\Mail\LeaveRequestDecided;
use App\Mail\LeaveRequestSubmitted;
use App\Models\Employee;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use App\Support\EmployeeFiles;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveDays;
use App\Support\Leave\LeavePlan;
use App\Support\Leave\LeaveRouting;
use App\Support\PayrollLock;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applying for leave (own record, or on someone's behalf by a user LeaveRouting::mayAdminister allows) and cancelling
 * a request nobody has decided yet.
 *
 * One rule set for the live preview and the submit (assess()): the type is active; the dates lie in the window
 * (attendanceToday() − 30 days up to 31 December of next year, default, owner may change); no past date falls in a
 * locked payroll month; LeaveDays::plan counts at least one day (it refuses offs-only ranges, an end before the start,
 * a half day over two dates and dates outside the employment window); no counted day overlaps a counted day of the
 * employee's open requests (re-planned now) or approved leave (their leave_days), where an "am" and a "pm" half on one
 * date may coexist but a full day and any half may not (the database does not enforce this, so it is checked here);
 * for a paid type, with the request's per-year shares (LeavePlan::byYear) counted as pending, every year from its
 * first year up to LeaveBalances::lastYearInUse() keeps an available balance of at least zero (rows created first with
 * LeaveBalances::ensureYear) — so a share in Y cannot also be used again as carry into Y+1. Unpaid leave is never
 * limited.
 *
 * Submit runs in one transaction whose first statement locks the employee row, so two applications by (or for) the
 * same person run one after the other and the second sees the first's pending days. Every check is made inside it.
 * The attachment is stored inside the transaction and removed again when it fails. The mail to the first approver is
 * queued after the commit; a mail failure is reported, never undoes the request.
 */
class ApplyForLeave
{
    /** Attachments: PDF, PNG or JPG by detected content, up to this size. */
    public const ATTACHMENT_MAX_KB = 5120;

    /** Detected content type => stored extension. */
    public const ATTACHMENT_TYPES = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'];

    /** How far back a request may start (days before attendanceToday()). */
    public const DAYS_BACK = 30;

    public function __construct(
        private readonly AttendanceClock $clock,
        private readonly LeaveBalances $balances,
        private readonly LeaveRouting $routing,
    ) {}

    /**
     * @throws ValidationException a refused request (keys: leave_type_id, from_date, days, attachment)
     */
    public function handle(User $by, Employee $employee, LeaveType $type, string $from, string $to, ?string $half, string $reason, ?UploadedFile $attachment): LeaveRequest
    {
        abort_unless($this->mayApply($by, $employee), 403);

        $extension = $attachment !== null ? self::attachmentExtension($attachment) : null;

        if ($attachment !== null && $extension === null) {
            throw ValidationException::withMessages(['attachment' => 'Attach a PDF, PNG or JPG file.']);
        }

        $stored = null;

        try {
            $request = DB::transaction(function () use ($by, $employee, $type, $from, $to, $half, $reason, $attachment, $extension, &$stored): LeaveRequest {
                // Two applications for the same person wait here, and the second sees the first's pending days. There
                // is no database rule behind overlaps or balances, so this locking read must stay the first statement.
                $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

                if ($stored !== null) { // a retried attempt: the file of the failed one goes
                    EmployeeFiles::disk()->delete($stored);
                    $stored = null;
                }

                // A shared lock on the type: it cannot be made inactive (or paid / unpaid) while this request is written.
                $type = LeaveType::query()->whereKey($type->id)->sharedLock()->firstOrFail();

                $assessment = $this->assess($locked, $type, $from, $to, $half);

                if ($assessment['problem'] !== null) {
                    [$key, $message] = $assessment['problem'];

                    throw ValidationException::withMessages([match ($key) {
                        'overlap' => 'from_date',
                        'carry' => 'days',
                        default => $key,
                    } => $message]);
                }

                /** @var LeavePlan $plan */
                $plan = $assessment['plan'];

                $attachmentName = null;

                if ($attachment !== null) {
                    $stored = $this->storeAttachment($locked, $attachment, $extension);
                    $attachmentName = EmployeeFiles::cleanName($attachment->getClientOriginalName()) ?: null;
                }

                $request = LeaveRequest::query()->create([
                    'employee_id' => $locked->id,
                    'leave_type_id' => $type->id,
                    'from_date' => $from,
                    'to_date' => $half !== null ? $from : $to,
                    'is_half_day' => $half !== null,
                    'half' => $half,
                    'days' => $plan->total,
                    'reason' => $reason,
                    'attachment_path' => $stored,
                    'attachment_name' => $attachmentName,
                    'balance_before' => $type->is_paid
                        ? collect($assessment['years'])->mapWithKeys(fn (array $y) => [(string) $y['year'] => $y['available']])->all()
                        : null,
                    'status' => 'pending',
                    'applied_by' => $by->id,
                ]);

                $onBehalf = ! $this->routing->isRequester($by, $request);

                Audit::record('leave', $onBehalf ? 'Leave requested on behalf' : 'Leave requested', $locked, [
                    'request' => $request->id,
                    'type' => $type->name,
                    'from' => $from,
                    'to' => $half !== null ? $from : $to,
                    'half' => $half,
                    'days' => $plan->total,
                    'reason' => Audit::clean($reason, 500),
                ], $by);

                $request->setRelation('employee', $locked);
                $request->setRelation('type', $type);

                return $request;
            }, 3); // retried on a deadlock
        } catch (Throwable $e) {
            if ($stored !== null) {
                EmployeeFiles::disk()->delete($stored);
            }

            throw $e;
        }

        $this->mailApprovers($by, $request);

        return $request;
    }

    /**
     * What the apply dialog shows while dates are typed (GET leave.requests.preview): the same checks as handle(),
     * without the lock or any request written. Balance rows of the years touched are created (ensureYear) so the
     * figures match what the submit will check. This GET writing rows is deliberate: ensureYear is idempotent
     * (insertOrIgnore, never changes an existing row) and the rows would be created by the submit anyway.
     *
     * @return array{days: list<array{date: string, label: string, amount: float, half: string}>, total: float, years: list<array{year: int, asked: float, available: float|null}>, refusal: string|null, overlap: string|null}
     */
    public function preview(User $by, Employee $employee, LeaveType $type, string $from, string $to, ?string $half): array
    {
        abort_unless($this->mayApply($by, $employee), 403);

        $assessment = $this->assess($employee, $type, $from, $to, $half);
        $plan = $assessment['plan'];
        [$key, $message] = $assessment['problem'] ?? [null, null];

        return [
            'days' => $plan === null ? [] : array_map(fn (array $d): array => [
                'date' => $d['date'],
                'label' => self::dateLabel($d['date']),
                'amount' => $d['amount'],
                'half' => $d['half'],
            ], $plan->days),
            'total' => $plan === null ? 0.0 : $plan->total,
            'years' => $assessment['years'],
            // A short balance is shown per year from `years`; refusal (including a later year left short through
            // carry, key "carry") and overlap are the other problems.
            'refusal' => $key !== null && $key !== 'days' && $key !== 'overlap' ? $message : null,
            'overlap' => $key === 'overlap' ? $message : null,
        ];
    }

    /**
     * The requester (or someone who may administer the employee's leave) withdraws a request nobody has decided yet.
     * No leave days exist yet, so nothing is refunded.
     *
     * Someone other than the requester (HR / Admin) withdrawing it: the employee is mailed after the commit (outcome
     * `withdrawn`, owner ruling M6) with $reason as the note (stored as cancel_reason; 5–500 characters when given, else
     * "Withdrawn by {name}.").
     *
     * @throws ValidationException a request that was decided meanwhile (key: decision); a too short or long reason (note)
     */
    public function cancelOpen(User $by, LeaveRequest $request, ?string $reason = null): void
    {
        $reason = $reason === null ? null : trim($reason);

        if ($reason !== null && (mb_strlen($reason) < 5 || mb_strlen($reason) > 500)) {
            throw ValidationException::withMessages(['note' => 'Say why the request is withdrawn (5 to 500 characters). The employee will see this.']);
        }

        $withdrawnFor = DB::transaction(function () use ($by, $request, $reason): ?LeaveRequest {
            $locked = LeaveRequest::query()->with(['employee', 'type'])->whereKey($request->id)->lockForUpdate()->firstOrFail();

            abort_unless($this->routing->isRequester($by, $locked) || $this->routing->mayAdminister($by, $locked->employee), 403);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['decision' => 'This request was already decided.']);
            }

            abort_unless($this->routing->canCancelOpen($by, $locked), 403);

            $own = $this->routing->isRequester($by, $locked);

            $locked->fill([
                'status' => 'cancelled',
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'cancel_reason' => $own ? $reason : ($reason ?? "Withdrawn by {$by->name}."),
            ])->save();

            Audit::record('leave', 'Leave request cancelled', $locked->employee, [
                'request' => $locked->id,
                'type' => $locked->type->name,
                'from' => $locked->from_date->format('Y-m-d'),
                'to' => $locked->to_date->format('Y-m-d'),
                'half' => $locked->half,
                'days' => (float) $locked->days,
                'step' => 'open',
                ...array_filter([
                    'on_behalf' => $own ? null : true,
                    'note' => $locked->cancel_reason !== null ? Audit::clean($locked->cancel_reason, 500) : null,
                ], fn ($v): bool => $v !== null),
            ], $by);

            return $own ? null : $locked;
        });

        if ($withdrawnFor !== null) {
            $this->mailWithdrawn($withdrawnFor);
        }
    }

    /** The employee's active login is told that HR / Admin withdrew their request (a queue failure is only reported). */
    private function mailWithdrawn(LeaveRequest $request): void
    {
        try {
            $user = User::query()->where('employee_id', $request->employee_id)->where('is_active', true)->first();

            if ($user !== null) {
                Mail::to($user)->queue(new LeaveRequestDecided($request, $user->name, 'withdrawn', $request->cancel_reason));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Own record, or someone whose leave this user may administer (HR / Admin, LeaveRouting::mayAdminister). */
    public function mayApply(User $by, Employee $employee): bool
    {
        return ($by->employee_id !== null && (int) $by->employee_id === (int) $employee->id)
            || $this->routing->mayAdminister($by, $employee);
    }

    /**
     * The dates a request may cover: attendanceToday() − 30 days up to 31 December of next year (default, owner may
     * change).
     *
     * @return array{min: string, max: string}
     */
    public function window(): array
    {
        $today = $this->clock->attendanceToday();

        return [
            'min' => (new DateTimeImmutable($today))->modify('-'.self::DAYS_BACK.' days')->format('Y-m-d'),
            'max' => sprintf('%04d-12-31', (int) substr($today, 0, 4) + 1),
        ];
    }

    /** The stored extension of an acceptable attachment (PDF, PNG or JPG by its content), else null. */
    public static function attachmentExtension(UploadedFile $file): ?string
    {
        $extension = self::ATTACHMENT_TYPES[EmployeeFiles::detectedMime($file) ?? ''] ?? null;

        if ($extension === null) {
            return null;
        }

        if ($extension === 'pdf') {
            $path = $file->getRealPath();
            $head = $path === false ? '' : (string) @file_get_contents($path, false, null, 0, 5);

            return $head === '%PDF-' ? 'pdf' : null;
        }

        return EmployeeFiles::imageSize($file) !== null ? $extension : null;
    }

    /** Whether $path is an attachment name this class generated for this employee. */
    public static function isAttachmentPath(int $employeeId, mixed $path): bool
    {
        return is_string($path)
            && preg_match('#^'.preg_quote(EmployeeFiles::directory($employeeId), '#').'/leave/[A-Za-z0-9]{40}\.(?:pdf|png|jpg)\z#', $path) === 1;
    }

    /** "Tue 1 Dec 2026". */
    public static function dateLabel(string $date): string
    {
        return (new DateTimeImmutable($date))->format('D j M Y');
    }

    /**
     * Every check of a request, in order; the first failing one is the problem ([error key, message]; key "overlap"
     * for an overlap, reported under from_date). Years lists each year touched with the days asked and the available
     * balance (null for an unpaid type).
     *
     * @return array{plan: LeavePlan|null, years: list<array{year: int, asked: float, available: float|null}>, problem: array{0: string, 1: string}|null}
     */
    private function assess(Employee $employee, LeaveType $type, string $from, string $to, ?string $half): array
    {
        $none = fn (string $key, string $message): array => ['plan' => null, 'years' => [], 'problem' => [$key, $message]];

        if (! $type->is_active) {
            return $none('leave_type_id', 'This leave type is not available any more.');
        }

        $window = $this->window();
        $last = $half !== null ? $from : $to;

        if ($from < $window['min'] || $last > $window['max']) {
            return $none('from_date', 'Leave can be requested from '.self::shortDate($window['min']).' up to '.self::shortDate($window['max']).'.');
        }

        $today = $this->clock->attendanceToday();
        $plan = LeaveDays::plan($employee, $from, $last, $half);

        if ($plan->isRefused()) {
            return $none('from_date', (string) $plan->refusal);
        }

        // Only counted days matter: a weekend or holiday in a locked month gets no leave day, so it does not block.
        $locked = $this->firstLockedMonth(array_values(array_filter($plan->dates(), fn (string $d): bool => $d < $today)));

        if ($locked !== null) {
            return $none('from_date', PayrollLock::message($locked));
        }

        $years = [];
        $problem = null;
        $overlap = $this->overlap($employee, $plan);

        if ($overlap !== null) {
            $problem = ['overlap', 'You already have leave on '.self::dateLabel($overlap).'.'];
        }

        $shares = $plan->byYear();

        if (! $type->is_paid) {
            foreach ($shares as $year => $asked) {
                $years[] = ['year' => $year, 'asked' => $asked, 'available' => null];
            }

            return ['plan' => $plan, 'years' => $years, 'problem' => $problem];
        }

        $current = (int) substr($today, 0, 4);
        $firstYear = (int) array_key_first($shares);
        $lastYear = (int) array_key_last($shares);

        // Rows for every year touched and the years between now and them, so carry projections use real rows.
        for ($y = min($current, $firstYear); $y <= $lastYear; $y++) {
            $this->balances->ensureYear($employee, $y);
        }

        // With this request's shares counted as pending (also in the carry walk), every year from its first year up
        // to the last year in use must stay at or above zero: a share in Y lowers what carries into Y+1, which may
        // already be booked by this request's own Y+1 share or by another request there.
        $checkTo = max($lastYear, $this->balances->lastYearInUse($employee, $type) ?? $lastYear);

        for ($year = $firstYear; $year <= $checkTo; $year++) {
            $after = $this->balances->line($employee, $type, $year, extraPending: $shares)->available;
            $asked = $shares[$year] ?? null;

            if ($asked !== null) {
                // What this request may use in this year, its own earlier shares already taken out of the carry.
                $available = LeaveDays::fromHalves(LeaveDays::halves($after) + LeaveDays::halves($asked));
                $years[] = ['year' => $year, 'asked' => $asked, 'available' => $available];

                if ($problem === null && LeaveDays::halves($after) < 0) {
                    $problem = ['days', sprintf(
                        'Not enough %s leave for %d: %s left, %s asked. Apply for the extra days as Unpaid leave.',
                        $type->name,
                        $year,
                        self::amount($available),
                        self::amount($asked),
                    )];
                }
            } elseif ($problem === null && LeaveDays::halves($after) < 0) {
                $problem = ['carry', sprintf(
                    'Not enough %s leave: these days would be taken from what carries into %d, and %d would end %s short. Apply for fewer days or as Unpaid leave.',
                    $type->name,
                    $year,
                    $year,
                    self::amount(-$after),
                )];
            }
        }

        return ['plan' => $plan, 'years' => $years, 'problem' => $problem];
    }

    /**
     * The first counted date of $plan that clashes with the employee's open requests (counted as they stand now) or
     * approved leave days: the same date, unless both are halves and the halves differ.
     */
    private function overlap(Employee $employee, LeavePlan $plan): ?string
    {
        $dates = $plan->dates();
        $from = $dates[0];
        $to = $dates[count($dates) - 1];
        $taken = []; // date => list of halves (full|am|pm)

        foreach (LeaveDay::query()->where('employee_id', $employee->id)->betweenDates($from, $to)->toBase()->get(['date', 'half']) as $day) {
            $taken[substr((string) $day->date, 0, 10)][] = (string) $day->half;
        }

        /** @var Collection<int, LeaveRequest> $open */
        $open = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', (array) config('hrm.leave.open_statuses'))
            // Plain "Y-m-d" values: LeaveRequest stores its dates without a time on every driver.
            ->where('from_date', '<=', $to)
            ->where('to_date', '>=', $from)
            ->orderBy('id')
            ->get(['id', 'from_date', 'to_date', 'half']);

        if ($open->isNotEmpty()) {
            $plans = LeaveDays::planMany(array_values($open->map(fn (LeaveRequest $r): array => [
                $employee, $r->from_date->format('Y-m-d'), $r->to_date->format('Y-m-d'), $r->half,
            ])->all()));

            foreach ($plans as $i => $other) {
                // A request that no longer counts (refused now) still holds its dates: be safe and block them all.
                $days = $other->isRefused()
                    ? array_map(fn (string $d): array => ['date' => $d, 'half' => $open[$i]->half ?? 'full'], self::datesBetween($open[$i]->from_date->format('Y-m-d'), $open[$i]->to_date->format('Y-m-d')))
                    : $other->days;

                foreach ($days as $day) {
                    $taken[$day['date']][] = $day['half'];
                }
            }
        }

        foreach ($plan->days as $day) {
            foreach ($taken[$day['date']] ?? [] as $half) {
                if ($half === 'full' || $day['half'] === 'full' || $half === $day['half']) {
                    return $day['date'];
                }
            }
        }

        return null;
    }

    /**
     * The first locked payroll month among these past dates (PayrollLock::firstLocked). A seam so tests can lock a
     * month (bind a subclass in the container) while PayrollLock itself locks nothing until Phase 5.
     *
     * @param  list<string>  $dates
     */
    protected function firstLockedMonth(array $dates): ?string
    {
        return PayrollLock::firstLocked($dates);
    }

    private function storeAttachment(Employee $employee, UploadedFile $file, string $extension): string
    {
        $folder = EmployeeFiles::directory($employee->id).'/leave';
        $name = Str::random(40).'.'.$extension;

        if (EmployeeFiles::disk()->putFileAs($folder, $file, $name) === false) {
            throw new \RuntimeException('The attachment could not be stored.');
        }

        return $folder.'/'.$name;
    }

    /** Mail the first approver(s): the manager when the request waits for them, else every final approver. */
    private function mailApprovers(User $by, LeaveRequest $request): void
    {
        try {
            $approvers = $this->routing->stage($request) === LeaveRouting::STAGE_MANAGER
                ? collect([$this->routing->managerApprover($request->employee)])->filter()
                : $this->routing->finalApprovers($request->employee);

            foreach ($approvers as $approver) {
                // Someone applying on another's behalf already knows; never the requester (routing excludes them).
                if ($approver->is($by)) {
                    continue;
                }

                Mail::to($approver)->queue(new LeaveRequestSubmitted($request, $approver->name, false));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return list<string> every date from $from to $to inclusive ("Y-m-d"); empty when $to is before $from */
    private static function datesBetween(string $from, string $to): array
    {
        $dates = [];

        for ($d = new DateTimeImmutable($from); ($date = $d->format('Y-m-d')) <= $to; $d = $d->modify('+1 day')) {
            $dates[] = $date;
        }

        return $dates;
    }

    private static function shortDate(string $date): string
    {
        return (new DateTimeImmutable($date))->format('j M Y');
    }

    private static function amount(float $days): string
    {
        return LeaveRequestSubmitted::days($days);
    }
}
