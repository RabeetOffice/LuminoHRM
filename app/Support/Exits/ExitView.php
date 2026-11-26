<?php

namespace App\Support\Exits;

use App\Actions\Exits\CancelExit;
use App\Actions\Exits\ChangeExitLastDay;
use App\Actions\Exits\ExitGuards;
use App\Models\FinalSettlement;
use App\Models\FinalSettlementLine;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\Leavers;
use App\Support\Payroll\PayrollAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The props of one exit, built for the viewer. A key the viewer may not have is ABSENT (not null, not empty):
 *
 *   resignation   always: the person, type, dates, reason, notice, stage, the rejection or cancel note
 *   flow          always: submitted -> manager -> HR, with who and when
 *   can           what the viewer may do now (decide: "manager" | "final" | null, change_day, cancel, withdraw)
 *   reasons       why an action the viewer would expect is unavailable (change_day, cancel, decide)
 *   clearance     the checklist. Exit.clearance + payroll.view people (and the person themself, read-only, once the exit is
 *                 approved). NEVER for a manager.
 *   settlement    the figures, lines, checks and actions: only exit.clearance + payroll.view. NEVER for a manager, and
 *                 never for the employee (they get statement_url once the settlement is approved).
 *   letter        exit.clearance only: issued or not, the text, issue / download urls
 *   statement_url / letter_url
 *                 the person's OWN downloads (my/resignation/...), only on their own exit and only once available
 *   status_line   what the nightly job did ("Status changed to Resigned on ..." / the stored error) for exit.clearance
 *
 * Money is paisa (the page formats it). Dates are "Y-m-d" strings. Nothing here writes.
 */
final class ExitView
{
    /**
     * @return array<string, mixed>
     */
    public static function for(User $user, Resignation $resignation): array
    {
        $resignation->loadMissing(['employee.department:id,name', 'employee.designation:id,name', 'employee.manager:id,first_name,last_name']);
        $routing = new ExitRouting;
        $routing->prime([$resignation]);

        $own = $routing->isOwn($user, $resignation);
        $admin = $user->can('exit.clearance');
        $money = $admin && $user->can('payroll.view');
        $manage = $routing->mayManage($user, $resignation);
        $approved = $resignation->status === ExitStates::APPROVED;

        $settlement = $money || ($own && $approved)
            ? FinalSettlement::query()->where('resignation_id', $resignation->id)->first(['id', 'resignation_id', 'status', 'month'])
            : null;

        $props = [
            'resignation' => self::row($resignation, $routing, $money, $money ? $settlement?->status : null),
            'flow' => self::flow($resignation, $routing),
            'can' => [
                'decide' => $routing->decidableStage($user, $resignation),
                'change_day' => false,
                'cancel' => false,
                'withdraw' => $own && $resignation->type === 'resignation'
                    && in_array($resignation->status, [ExitStates::PENDING, ExitStates::MANAGER_APPROVED], true),
            ],
            'reasons' => [],
            'own' => $own,
        ];

        $stageNow = $routing->stage($resignation);

        if ($stageNow !== null && $props['can']['decide'] === null && $admin) {
            $props['reasons']['decide'] = $routing->finalRefusal($user, $resignation) ?? ExitRouting::NOT_WAITING_MESSAGE;
        }

        if ($approved) {
            $frozen = $settlement?->isFrozen()
                ?? FinalSettlement::query()->where('resignation_id', $resignation->id)->whereIn('status', (array) config('hrm.exit.frozen_statuses'))->exists();
            $left = $resignation->status_applied_at !== null || Leavers::isLeaverStatus($resignation->employee->status);

            if ($manage && $frozen) {
                $props['reasons']['change_day'] = ExitGuards::SETTLEMENT_FROZEN;
                $props['reasons']['cancel'] = ExitGuards::SETTLEMENT_FROZEN;
            } elseif ($manage) {
                $props['can']['change_day'] = true;
                $props['can']['cancel'] = ! $left;

                if ($left) {
                    $props['reasons']['cancel'] = CancelExit::alreadyLeft($resignation->employee->full_name, (string) $resignation->employee->status);
                    $props['reasons']['change_day'] = ChangeExitLastDay::STATUS_APPLIED;
                }
            }

            $props['status_applied'] = $left;
        }

        if ($approved && ($money || $own)) {
            $props['clearance'] = self::clearance($resignation, $manage && $money && ! ($settlement?->isFrozen() ?? false));
        }

        if ($money) {
            $props['settlement'] = $approved ? self::settlement($user, $resignation, $routing, $manage) : null;
        }

        if ($admin && $approved) {
            $props['letter'] = self::letter($resignation, $manage);
            $props['status_line'] = self::statusLine($resignation);
        }

        if ($own) {
            if ($settlement !== null && $settlement->isFrozen()) {
                $props['statement_url'] = "/my/resignation/{$resignation->id}/statement";
            }

            if ($resignation->letter_issued_at !== null && $resignation->letter_text !== null) {
                $props['letter_url'] = "/my/resignation/{$resignation->id}/letter";
            }
        }

        return $props;
    }

    /**
     * One exit as a row / request card. `settlement` (its status) and the finer stage ("Clearance", "Settlement draft", ...)
     * only when $money: the viewer may see settlements (exit.clearance + payroll.view).
     *
     * @return array<string, mixed>
     */
    public static function row(Resignation $resignation, ExitRouting $routing, bool $money = false, ?string $settlementStatus = null): array
    {
        $employee = $resignation->employee;
        $given = ExitNotice::given($resignation->submitted_on->format('Y-m-d'), $resignation->last_working_day->format('Y-m-d'));
        $required = (int) $resignation->notice_period_days;

        $row = [
            'id' => (int) $resignation->id,
            'employee' => [
                'id' => (int) $employee->id,
                'code' => $employee->employee_code,
                'name' => $employee->full_name,
                'department' => $employee->department?->name,
                'designation' => $employee->designation?->name,
                'manager' => $employee->manager?->full_name,
                'status' => $employee->status,
            ],
            'type' => $resignation->type,
            'status' => $resignation->status,
            'stage' => $resignation->stageLabel(),
            'waiting_at' => $routing->stage($resignation),
            'detail' => self::detail($resignation, $money, $settlementStatus),
            'submitted_on' => $resignation->submitted_on->format('Y-m-d'),
            'last_working_day' => $resignation->last_working_day->format('Y-m-d'),
            'reason' => $resignation->reason,
            'notice' => [
                'given' => $given,
                'required' => $required,
                'short' => ExitNotice::shortBy($required, $given),
                'label' => ExitNotice::label($resignation),
            ],
            'recorded_by_hr' => $resignation->recorded_by !== null,
            'decision_note' => in_array($resignation->status, [ExitStates::REJECTED], true) ? $resignation->decision_note : null,
            'cancel_reason' => $resignation->status === ExitStates::CANCELLED ? $resignation->cancel_reason : null,
            'url' => '/exit/'.$resignation->id,
        ];

        if ($money) {
            $row['settlement'] = $settlementStatus;
        }

        return $row;
    }

    /** The finer stage of an approved exit ("Clearance", "Settlement draft", "Settlement approved", "Paid", "Left"), else null. */
    private static function detail(Resignation $resignation, bool $money, ?string $settlementStatus): ?string
    {
        if ($resignation->status !== ExitStates::APPROVED) {
            return null;
        }

        if ($resignation->status_applied_at !== null) {
            return 'Left';
        }

        if (! $money) {
            return null;
        }

        return match ($settlementStatus) {
            'paid' => 'Paid',
            'approved' => 'Settlement approved',
            'draft' => 'Settlement draft',
            default => 'Clearance',
        };
    }

    /**
     * The flow: submitted, the manager step, the HR step. Each step has a state: done, current, skipped, pending, rejected,
     * withdrawn or cancelled-over (n/a).
     *
     * @return list<array<string, mixed>>
     */
    public static function flow(Resignation $resignation, ExitRouting $routing): array
    {
        $names = self::names([
            $resignation->recorded_by, $resignation->manager_decided_by, $resignation->final_decided_by, $resignation->cancelled_by,
        ]);
        $day = fn ($at) => self::day($at);
        $employee = $resignation->employee;
        $status = $resignation->status;
        $managerName = $employee->manager?->full_name;

        $steps = [[
            'key' => 'submitted',
            'label' => $resignation->recorded_by !== null ? 'Recorded by HR' : 'Submitted',
            'state' => 'done',
            'who' => $resignation->recorded_by !== null ? ($names[$resignation->recorded_by] ?? null) : $employee->full_name,
            'on' => $resignation->submitted_on->format('Y-m-d'),
        ]];

        // Manager step.
        $managerSkipped = $resignation->manager_skipped || ($resignation->manager_decided_by === null && $status !== ExitStates::PENDING);
        $manager = ['key' => 'manager', 'label' => 'Manager', 'who' => $managerName, 'on' => null, 'note' => null];

        if ($resignation->manager_decided_by !== null) {
            $manager['who'] = $names[$resignation->manager_decided_by] ?? $managerName;
            $manager['on'] = $day($resignation->manager_decided_at);
            $manager['state'] = $status === ExitStates::REJECTED && $resignation->final_decided_by === null ? 'rejected' : 'done';
            $manager['note'] = $manager['state'] === 'rejected' ? $resignation->decision_note : null;
        } elseif ($managerSkipped) {
            $manager['state'] = 'skipped';
            $manager['who'] = null;
        } elseif ($status === ExitStates::PENDING) {
            $manager['state'] = $routing->stage($resignation) === ExitRouting::STAGE_MANAGER ? 'current' : 'skipped';
        } else {
            $manager['state'] = $status === ExitStates::WITHDRAWN ? 'withdrawn' : 'pending';
        }

        $steps[] = $manager;

        // Final step.
        $final = ['key' => 'final', 'label' => 'HR', 'who' => null, 'on' => null, 'note' => null];

        if ($resignation->final_decided_by !== null) {
            $final['who'] = $names[$resignation->final_decided_by] ?? null;
            $final['on'] = $day($resignation->final_decided_at);
            $final['state'] = $status === ExitStates::REJECTED ? 'rejected' : 'done';
            $final['note'] = $status === ExitStates::REJECTED ? $resignation->decision_note : null;
        } elseif (in_array($status, [ExitStates::MANAGER_APPROVED], true) || ($status === ExitStates::PENDING && $manager['state'] === 'skipped')) {
            $final['state'] = 'current';
        } else {
            $final['state'] = $status === ExitStates::WITHDRAWN ? 'withdrawn' : 'pending';
        }

        $steps[] = $final;

        if ($status === ExitStates::WITHDRAWN) {
            $steps[] = ['key' => 'withdrawn', 'label' => 'Withdrawn', 'state' => 'withdrawn', 'who' => $employee->full_name, 'on' => $day($resignation->withdrawn_at)];
        }

        if ($status === ExitStates::CANCELLED) {
            $steps[] = [
                'key' => 'cancelled', 'label' => 'Cancelled', 'state' => 'cancelled',
                'who' => $names[$resignation->cancelled_by] ?? null, 'on' => $day($resignation->cancelled_at), 'note' => $resignation->cancel_reason,
            ];
        }

        return $steps;
    }

    /** A stored moment as the company-zone calendar day "Y-m-d" (null stays null). */
    private static function day(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone(app(AttendanceClock::class)->zone())->format('Y-m-d');
    }

    /**
     * @param  list<int|null>  $ids
     * @return array<int, string> user id => name
     */
    private static function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : User::query()->whereKey($ids)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /**
     * @return array{items: list<array<string, mixed>>, complete: bool, missing: list<string>, editable: bool}
     */
    private static function clearance(Resignation $resignation, bool $editable): array
    {
        $status = ClearanceStatus::for($resignation);
        $names = self::names(array_map(fn (array $item) => $item['cleared_by'] ?? null, $status['items']));

        return [
            'items' => array_map(function (array $item) use ($names): array {
                $item['cleared_by'] = $item['cleared_by'] === null ? null : ($names[$item['cleared_by']] ?? null);

                return $item;
            }, $status['items']),
            'complete' => $status['complete'],
            'missing' => $status['missing'],
            'editable' => $editable,
        ];
    }

    /** @return array<string, mixed> */
    private static function settlement(User $user, Resignation $resignation, ExitRouting $routing, bool $manage): array
    {
        $settlement = FinalSettlement::query()->with('lines')->where('resignation_id', $resignation->id)->first();
        $employee = $resignation->employee;
        $mayEditSalary = $manage && PayrollAccess::mayEditSalary($user, $employee);

        if ($settlement === null) {
            return ['exists' => false, 'can' => ['prepare' => $mayEditSalary, 'edit' => false, 'approve' => false, 'paid' => false]];
        }

        $draft = $settlement->status === 'draft';
        $names = self::names([$settlement->approved_by, $settlement->paid_by]);
        $breakdown = (array) ($settlement->breakdown ?? []);
        $detail = (array) ($breakdown['settlement'] ?? []);
        $figures = [];

        foreach (FinalSettlement::FIGURES as $figure) {
            $figures[$figure] = (int) $settlement->{$figure};
        }

        $edit = $draft && SettlementAccess::mayEdit($user, $settlement);

        return [
            'exists' => true,
            'id' => (int) $settlement->id,
            'status' => $settlement->status,
            'month' => $settlement->month,
            'stale' => (bool) $settlement->stale,
            'figures' => $figures,
            'encashment' => [
                'on' => ($detail['encashment'] ?? null) !== null,
                'type_name' => $detail['encashment']['type_name'] ?? null,
                'halves' => (int) $settlement->encashment_days,
                'amount' => (int) $settlement->encashment_amount,
            ],
            'loans' => array_values((array) ($detail['loans'] ?? [])),
            'loan_shortfall' => (int) $settlement->loan_shortfall,
            'owed_by_employee' => max(0, -1 * (int) $settlement->net),
            'lines' => $settlement->lines->map(fn (FinalSettlementLine $line) => [
                'id' => (int) $line->id,
                'kind' => $line->kind,
                'type' => $line->type,
                'label' => $line->label,
                'amount' => (int) $line->amount,
                'is_taxable' => (bool) $line->is_taxable,
            ])->values()->all(),
            'tax' => [
                'overridden' => (bool) $settlement->tax_overridden,
                'amount' => $settlement->tax_overridden ? (int) $settlement->tax_override : null,
                'reason' => $settlement->tax_overridden ? $settlement->tax_override_reason : null,
            ],
            'checks' => $draft ? array_values(SettlementChecks::for($settlement)) : [],
            'approved_at' => self::day($settlement->approved_at),
            'approved_by' => $names[$settlement->approved_by] ?? null,
            'own_settlement' => (bool) $settlement->own_settlement,
            'paid_on' => $settlement->paid_on?->format('Y-m-d'),
            'paid_by' => $names[$settlement->paid_by] ?? null,
            'statement_url' => $settlement->isFrozen() ? "/exit/settlements/{$settlement->id}/statement" : null,
            'can' => [
                'prepare' => false,
                'edit' => $edit,
                'approve' => $draft && SettlementAccess::mayApprove($user, $settlement),
                'paid' => $settlement->status === 'approved' && $manage,
            ],
            'paid_label' => $settlement->net > 0 ? 'Mark paid' : 'Mark settled',
        ];
    }

    /** @return array<string, mixed> */
    private static function letter(Resignation $resignation, bool $manage): array
    {
        $issued = $resignation->letter_issued_at !== null && $resignation->letter_text !== null;
        $by = $resignation->letter_issued_by === null ? null : (self::names([$resignation->letter_issued_by])[$resignation->letter_issued_by] ?? null);

        return [
            'issued' => $issued,
            'issued_on' => $issued ? self::day($resignation->letter_issued_at) : null,
            'issued_by' => $issued ? $by : null,
            'text' => $issued ? $resignation->letter_text : null,
            'url' => $issued ? "/exit/{$resignation->id}/letter" : null,
            'can_issue' => $manage,
        ];
    }

    /** @return array{applied: bool, text: string|null, error: string|null} */
    private static function statusLine(Resignation $resignation): array
    {
        $applied = $resignation->status_applied_at !== null;

        return [
            'applied' => $applied,
            'on' => $applied ? self::day($resignation->status_applied_at) : null,
            'status' => $resignation->employee->status,
            'error' => $resignation->status_error,
        ];
    }

    /**
     * Rows of exits (for the list) keyed by id, with the settlement status of each when the viewer may see it.
     *
     * @param  Collection<int, Resignation>  $resignations
     * @return array<int, string>
     */
    public static function settlementStatuses(Collection $resignations): array
    {
        if ($resignations->isEmpty()) {
            return [];
        }

        return FinalSettlement::query()->whereIn('resignation_id', $resignations->modelKeys())->pluck('status', 'resignation_id')
            ->mapWithKeys(fn ($status, $id) => [(int) $id => (string) $status])->all();
    }
}
