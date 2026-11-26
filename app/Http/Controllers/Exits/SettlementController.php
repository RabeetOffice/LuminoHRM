<?php

namespace App\Http\Controllers\Exits;

use App\Actions\Exits\ApproveSettlement;
use App\Actions\Exits\MarkSettlementPaid;
use App\Actions\Exits\OverrideSettlementTax;
use App\Actions\Exits\PrepareSettlement;
use App\Actions\Exits\RecalculateSettlement;
use App\Actions\Exits\RemoveSettlementLine;
use App\Actions\Exits\SaveSettlementLine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exits\SettlementLineRequest;
use App\Http\Requests\Exits\SettlementPaidRequest;
use App\Http\Requests\Exits\SettlementTaxRequest;
use App\Models\FinalSettlement;
use App\Models\FinalSettlementLine;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Exits\ExitRouting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The final settlement's writes. Every route needs exit.clearance and payroll.view (Admin, HR): a Manager or an Employee
 * is 403 before anything is read. The exit must be one the user may see (403 otherwise); each action then checks that the
 * user may edit or approve THIS settlement (SettlementAccess) under its locks. Refusals are validation errors keyed
 * `settlement` (the whole action) or by field; the screen shows them.
 */
class SettlementController extends Controller
{
    public function prepare(Request $request, int $resignation, PrepareSettlement $action): RedirectResponse
    {
        $action->handle($this->user($request), $this->exit($request, $resignation));

        return back()->with('success', 'Settlement prepared.');
    }

    public function recalculate(Request $request, int $settlement, RecalculateSettlement $action): RedirectResponse
    {
        $action->handle($this->user($request), $this->settlement($request, $settlement));

        return back()->with('success', 'Settlement recalculated.');
    }

    public function addLine(SettlementLineRequest $request, int $settlement, SaveSettlementLine $action): RedirectResponse
    {
        $action->handle($this->user($request), $this->settlement($request, $settlement), $request->line());

        return back()->with('success', 'Line added.');
    }

    public function removeLine(Request $request, int $settlement, int $line, RemoveSettlementLine $action): RedirectResponse
    {
        $found = $this->settlement($request, $settlement);
        $row = FinalSettlementLine::query()->where('final_settlement_id', $found->id)->whereKey($line)->first();

        abort_if($row === null, 403);

        $action->handle($this->user($request), $row);

        return back()->with('success', 'Line removed.');
    }

    public function tax(SettlementTaxRequest $request, int $settlement, OverrideSettlementTax $action): RedirectResponse
    {
        $amount = $request->validated('amount');

        $action->handle(
            $this->user($request),
            $this->settlement($request, $settlement),
            $amount === null ? null : (int) $amount,
            $request->validated('reason'),
        );

        return back()->with('success', $amount === null ? 'Tax override cleared.' : 'Tax override saved.');
    }

    public function approve(Request $request, int $settlement, ApproveSettlement $action): RedirectResponse
    {
        $action->handle($this->user($request), $this->settlement($request, $settlement));

        return back()->with('success', 'Settlement approved.');
    }

    public function paid(SettlementPaidRequest $request, int $settlement, MarkSettlementPaid $action): RedirectResponse
    {
        $action->handle($this->user($request), $this->settlement($request, $settlement), (string) $request->validated('paid_on'));

        return back()->with('success', 'Settlement marked paid.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('exit.clearance') && $user->can('payroll.view'), 403);

        return $user;
    }

    private function exit(Request $request, int $id): Resignation
    {
        $found = (new ExitRouting)->findVisible($this->user($request), $id);

        abort_if($found === null, 403);

        return $found;
    }

    private function settlement(Request $request, int $id): FinalSettlement
    {
        $found = FinalSettlement::query()->find($id);

        abort_if($found === null, 403);
        $this->exit($request, (int) $found->resignation_id);

        return $found;
    }
}
