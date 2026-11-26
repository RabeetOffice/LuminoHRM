<?php

namespace App\Http\Controllers\Exits;

use App\Actions\Exits\TickClearanceItem;
use App\Http\Controllers\Controller;
use App\Models\Clearance;
use App\Models\User;
use App\Support\Exits\ExitRouting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tick or untick one clearance item: PUT /exit/clearance/{clearance} with `cleared` (bool). The exit must be one the user
 * may see (403 otherwise); TickClearanceItem checks that they may administer it, that the item is not the automatic
 * "Assets returned", and that the settlement is not yet approved.
 */
class ClearanceController extends Controller
{
    public function update(Request $request, int $clearance, TickClearanceItem $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('exit.clearance'), 403);

        $item = Clearance::query()->find($clearance);
        abort_if($item === null || (new ExitRouting)->findVisible($user, (int) $item->resignation_id) === null, 403);

        $cleared = $request->boolean('cleared');
        $action->handle($user, $item, $cleared);

        return back()->with('success', $cleared ? "{$item->label}: ticked." : "{$item->label}: unticked.");
    }
}
