<?php

namespace App\Http\Controllers\Assets;

use App\Actions\Assets\ChangeAssetStatus;
use App\Actions\Assets\DeleteAsset;
use App\Actions\Assets\SaveAsset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\AssetRequest;
use App\Http\Requests\Assets\AssetStatusRequest;
use App\Models\Asset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The asset register's writes (Task 4 adds the pages). add, edit and status: can:assets.assign (Admin, HR); delete:
 * can:assets.manage (Admin). Every write goes through its action (one transaction, audited); refusals come back as
 * validation errors on the field.
 */
class AssetController extends Controller
{
    public function store(AssetRequest $request, SaveAsset $save): RedirectResponse
    {
        $asset = $save->create($request->user(), $request->assetData());

        return back()->with('success', "Asset {$asset->asset_code} added.");
    }

    public function update(AssetRequest $request, Asset $asset, SaveAsset $save): RedirectResponse
    {
        $save->update($request->user(), $asset, $request->assetData());

        return back()->with('success', 'Asset updated.');
    }

    public function status(AssetStatusRequest $request, Asset $asset, ChangeAssetStatus $change): RedirectResponse
    {
        $change->handle($request->user(), $asset, $request->validated('status'));

        return back()->with('success', 'Status updated.');
    }

    public function destroy(Request $request, Asset $asset, DeleteAsset $delete): RedirectResponse
    {
        abort_unless($request->user()->can('assets.manage'), 403);

        $delete->handle($request->user(), $asset);

        return back()->with('success', 'Asset deleted.');
    }
}
