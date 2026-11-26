<?php

namespace App\Http\Controllers\Assets;

use App\Actions\Assets\AssignAsset;
use App\Actions\Assets\EditAssignmentNote;
use App\Actions\Assets\ReturnAsset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\AssignAssetRequest;
use App\Http\Requests\Assets\AssignmentNoteRequest;
use App\Http\Requests\Assets\ReturnAssetRequest;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeAccess;
use Illuminate\Http\RedirectResponse;

/**
 * Hand-overs: assign, return and the notes. Every route is can:assets.assign (Admin, HR); the employee given to an
 * assign must also be in the user's EmployeeAccess scope (403 otherwise). The actions lock and decide; this answers
 * with a redirect back and a flash. There is no route that edits an assignment's dates or holder, and no delete.
 */
class AssignmentController extends Controller
{
    public function assign(AssignAssetRequest $request, Asset $asset, AssignAsset $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $employee = Employee::query()->findOrFail((int) $request->validated('employee_id'));

        abort_unless((new EmployeeAccess($user))->canView($employee), 403);

        $action->handle($user, $asset, $employee, (string) $request->validated('assigned_on'), $request->validated('note'));

        return back()->with('success', "{$asset->asset_code} assigned to {$employee->full_name}.");
    }

    public function return(ReturnAssetRequest $request, AssetAssignment $assignment, ReturnAsset $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle(
            $user,
            $assignment,
            (string) $request->validated('returned_on'),
            (string) $request->validated('return_condition'),
            (string) $request->validated('next_status'),
            $request->validated('return_note'),
        );

        $code = Asset::query()->whereKey($assignment->asset_id)->value('asset_code');

        return back()->with('success', "{$code} returned.");
    }

    public function note(AssignmentNoteRequest $request, AssetAssignment $assignment, EditAssignmentNote $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // A key the request did not send leaves that note as it is; a key sent empty clears it.
        $action->handle(
            $user,
            $assignment,
            $request->validated('note'),
            $request->validated('return_note'),
            $request->has('note'),
            $request->has('return_note'),
        );

        return back()->with('success', $action->changed() ? 'Note saved.' : 'Nothing changed.');
    }
}
