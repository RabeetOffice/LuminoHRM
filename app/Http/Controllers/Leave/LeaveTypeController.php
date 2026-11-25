<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveTypeRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leave > Leave Types. Every route is behind can:settings.attendance-leave (Admin, HR). The model audits each change
 * (area leave, "Leave type created / updated / deleted") inside the same transaction as the change.
 *
 * Adding a paid type creates no balance rows: they appear on demand and through the allocation job. Making a type
 * inactive keeps its history; inactive types are not offered when applying. A type with any request or balance row
 * cannot be deleted, only made inactive. The system Unpaid type is never deleted, never made paid and never made
 * inactive (it is the fallback staff are pointed to), and a type's paid flag is fixed once it has requests. Every write
 * goes through the model instance (fill/save, delete) so the model's guards and audit always run.
 *
 * Carried days are worked out when read, so changing a type's carry-forward flag or cap changes the carried days of
 * this year and later years (plan open decision 6); nothing is rewritten here.
 */
class LeaveTypeController extends Controller
{
    public const HISTORY_MESSAGE = 'This type has leave history. Make it inactive instead.';

    public const SYSTEM_DELETE_MESSAGE = 'The Unpaid leave type cannot be deleted: it is the type staff use when their paid leave runs out.';

    public const SYSTEM_PAID_MESSAGE = 'The Unpaid leave type is always unpaid.';

    public const SYSTEM_ACTIVE_MESSAGE = 'The Unpaid leave type is always active: it is the type staff use when their paid leave runs out.';

    public const PAID_LOCKED_MESSAGE = 'This type already has leave requests, so it cannot change between paid and unpaid.';

    public function index(): Response
    {
        $withRequests = LeaveRequest::query()->distinct()->pluck('leave_type_id')->flip();
        $withBalances = LeaveBalance::query()->distinct()->pluck('leave_type_id')->flip();

        $types = LeaveType::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (LeaveType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'yearly_quota' => $t->yearly_quota,
                'is_paid' => $t->is_paid,
                'carry_forward' => $t->carry_forward,
                'carry_forward_cap' => $t->carry_forward_cap,
                'is_active' => $t->is_active,
                'is_system' => $t->is_system,
                'colour' => $t->colour,
                'has_requests' => $withRequests->has($t->id),
                'has_history' => $withRequests->has($t->id) || $withBalances->has($t->id),
            ])
            ->values()
            ->all();

        return Inertia::render('leave/types', [
            'types' => $types,
            'colours' => array_values((array) config('hrm.leave.colours')),
        ]);
    }

    public function store(LeaveTypeRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request): void {
                $next = (int) LeaveType::query()->max('sort_order') + 1;

                // is_system is never taken from a request: only the seeded Unpaid type has it (column default false).
                LeaveType::create([...$request->typeAttributes(), 'sort_order' => $next]);
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => 'A leave type with this name already exists.']);
        }

        return back()->with('success', 'Leave type added.');
    }

    public function update(LeaveTypeRequest $request, LeaveType $leaveType): RedirectResponse
    {
        $attributes = $request->typeAttributes();

        try {
            $refusal = DB::transaction(function () use ($leaveType, $attributes): ?string {
                $locked = LeaveType::query()->whereKey($leaveType->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->is_system && $attributes['is_paid']) {
                    return self::SYSTEM_PAID_MESSAGE;
                }

                if ($locked->is_system && ! $attributes['is_active']) {
                    return self::SYSTEM_ACTIVE_MESSAGE;
                }

                if ($locked->is_paid !== $attributes['is_paid'] && $locked->requests()->exists()) {
                    return self::PAID_LOCKED_MESSAGE;
                }

                $locked->fill($attributes)->save();

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => 'A leave type with this name already exists.']);
        }

        if ($refusal !== null) {
            return back()->withErrors([$refusal === self::SYSTEM_ACTIVE_MESSAGE ? 'is_active' : 'is_paid' => $refusal]);
        }

        return back()->with('success', 'Leave type updated.');
    }

    public function destroy(LeaveType $leaveType): RedirectResponse
    {
        // Asked inside the transaction, just before the delete. The foreign keys restrict the delete as a backstop.
        $reason = DB::transaction(function () use ($leaveType): ?string {
            $locked = LeaveType::query()->whereKey($leaveType->getKey())->lockForUpdate()->firstOrFail();

            if ($reason = $this->deleteBlocker($locked)) {
                return $reason;
            }

            $locked->delete();

            return null;
        });

        return $reason !== null
            ? back()->with('error', $reason)
            : back()->with('success', 'Leave type deleted.');
    }

    /** Why this type cannot be deleted right now, or null when it can. */
    private function deleteBlocker(LeaveType $type): ?string
    {
        if ($type->is_system) {
            return self::SYSTEM_DELETE_MESSAGE;
        }

        if ($type->requests()->exists() || $type->balances()->exists()) {
            return self::HISTORY_MESSAGE;
        }

        return null;
    }
}
