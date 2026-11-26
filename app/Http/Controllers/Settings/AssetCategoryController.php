<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Assets\SaveAssetCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\AssetCategoryRequest;
use App\Models\AssetCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Asset categories (assets.assign: Admin and HR). Every write goes through SaveAssetCategory (one transaction,
 * audited). A category that assets use cannot be deleted; its row says so and the server refuses it again under a lock.
 */
class AssetCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $categories = AssetCategory::query()
            ->withCount('assets')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (AssetCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'assets_count' => (int) $c->assets_count,
                'can_delete' => (int) $c->assets_count === 0,
            ])
            ->values()
            ->all();

        return Inertia::render('hrm-settings/asset-categories', [
            'groups' => HrmSettingsController::groupsFor($request->user()),
            'categories' => $categories,
        ]);
    }

    public function store(AssetCategoryRequest $request, SaveAssetCategory $save): RedirectResponse
    {
        $save->create($request->user(), $request->validated());

        return back()->with('success', 'Category added.');
    }

    public function update(AssetCategoryRequest $request, AssetCategory $category, SaveAssetCategory $save): RedirectResponse
    {
        $save->update($request->user(), $category, $request->validated());

        return back()->with('success', 'Category renamed.');
    }

    public function destroy(Request $request, AssetCategory $category, SaveAssetCategory $save): RedirectResponse
    {
        $save->delete($request->user(), $category);

        return back()->with('success', 'Category deleted.');
    }
}
