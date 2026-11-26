<?php

namespace App\Http\Controllers\Performance;

use App\Actions\Performance\AddReviewPeople;
use App\Actions\Performance\ChangeCycleDueDate;
use App\Actions\Performance\CloseReviewCycle;
use App\Actions\Performance\OpenReviewCycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\AddPeopleRequest;
use App\Http\Requests\Performance\DueDateRequest;
use App\Http\Requests\Performance\OpenCycleRequest;
use App\Models\ReviewCycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Review cycles (performance.manage: Admin and HR, enforced by the routes): open, add people, change the due date, close.
 * Each write is one action (one transaction, audited); refusals come back as validation errors, successes as a plain flash.
 * The pages (show) are added by Task 4.
 */
class CycleController extends Controller
{
    public function store(OpenCycleRequest $request, OpenReviewCycle $open): RedirectResponse
    {
        $cycle = $open->handle($request->user(), (string) $request->validated('period'), $request->validated('due_on'));

        $reviews = $cycle->reviews()->count();
        $noKpis = $cycle->getRelation('no_kpis')->count();

        $message = "{$cycle->name} opened with ".self::count($reviews, 'review', 'reviews').'.';

        if ($noKpis > 0) {
            $message .= ' '.($noKpis === 1 ? '1 person has no KPIs.' : "{$noKpis} people have no KPIs.");
        }

        return back()->with('success', $message);
    }

    public function people(AddPeopleRequest $request, ReviewCycle $cycle, AddReviewPeople $add): RedirectResponse
    {
        $result = $add->handle($request->user(), $cycle, $request->employeeIds());

        $message = self::count($result['added'], 'person', 'people').' added.';

        if ($result['refused'] !== []) {
            $message .= ' '.implode(' ', $result['refused']);
        }

        return back()->with('success', $message);
    }

    public function dueDate(DueDateRequest $request, ReviewCycle $cycle, ChangeCycleDueDate $change): RedirectResponse
    {
        $change->handle($request->user(), $cycle, (string) $request->validated('due_on'));

        return back()->with('success', 'Due date changed.');
    }

    public function close(Request $request, ReviewCycle $cycle, CloseReviewCycle $close): RedirectResponse
    {
        $close->handle($request->user(), $cycle);

        return back()->with('success', 'Cycle closed.');
    }

    private static function count(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }
}
