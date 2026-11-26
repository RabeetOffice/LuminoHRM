<?php

namespace App\Http\Controllers\Performance;

use App\Actions\Performance\ChangeReviewer;
use App\Actions\Performance\CloseReview;
use App\Actions\Performance\ReopenReview;
use App\Actions\Performance\RestoreReview;
use App\Actions\Performance\SaveManagerRatings;
use App\Actions\Performance\SaveSelfRatings;
use App\Actions\Performance\SendBackReview;
use App\Actions\Performance\SkipReview;
use App\Actions\Performance\SubmitManagerReview;
use App\Actions\Performance\SubmitSelfReview;
use App\Actions\Performance\WaiveSelfReview;
use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\RatingsRequest;
use App\Http\Requests\Performance\ReviewerRequest;
use App\Http\Requests\Performance\SendBackRequest;
use App\Http\Requests\Performance\SkipRequest;
use App\Models\Review;
use App\Support\Performance\ReviewAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The review workflow's writes (Task 5 adds show). Routes are auth only: a review outside the viewer's ReviewAccess::scope
 * is 403, and each action checks the actor again under the locks (AuthorizationException -> 403). Refusals come back as
 * validation errors.
 */
class ReviewController extends Controller
{
    public function selfDraft(RatingsRequest $request, int $review, SaveSelfRatings $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $review), $request->ratings());

        return back()->with('success', 'Draft saved.');
    }

    public function selfSubmit(RatingsRequest $request, int $review, SubmitSelfReview $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $review), $request->ratings());

        return back()->with('success', 'Self-review submitted.');
    }

    public function waive(Request $request, int $review, WaiveSelfReview $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $review));

        return back()->with('success', 'Self-review waived.');
    }

    public function managerDraft(RatingsRequest $request, int $review, SaveManagerRatings $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $review), $request->ratings(), $request->overallComment());

        return back()->with('success', 'Draft saved.');
    }

    public function managerSubmit(RatingsRequest $request, int $review, SubmitManagerReview $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $review), $request->ratings(), $request->overallComment());

        return back()->with('success', 'Review submitted.');
    }

    public function sendBack(SendBackRequest $request, int $review, SendBackReview $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $review), (string) $request->validated('note'));

        return back()->with('success', 'Review sent back to the manager.');
    }

    public function close(Request $request, int $review, CloseReview $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $review));

        return back()->with('success', 'Review closed.');
    }

    public function reopen(Request $request, int $review, ReopenReview $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $review));

        return back()->with('success', 'Review reopened.');
    }

    public function skip(SkipRequest $request, int $review, SkipReview $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $review), (string) $request->validated('reason'));

        return back()->with('success', 'Review skipped.');
    }

    public function restore(Request $request, int $review, RestoreReview $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $review));

        return back()->with('success', 'Review restored.');
    }

    public function reviewer(ReviewerRequest $request, int $review, ChangeReviewer $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $review), $request->reviewerId());

        return back()->with('success', 'Reviewer changed.');
    }

    /** The review when the viewer may see it; 403 otherwise (never 404, which would reveal whether it exists). */
    private function visible(Request $request, int $id): Review
    {
        return ReviewAccess::findVisible($request->user(), $id) ?? abort(403);
    }
}
