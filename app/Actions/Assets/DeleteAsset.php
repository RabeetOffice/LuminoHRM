<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete an asset that was never handed out (assets.manage: Admin). An asset with any assignment, open or returned, keeps
 * its history and is retired instead. The asset row is locked first; the model hook and the foreign key are the backstop.
 * Audited in the same transaction (area assets), keeping the code, name and category.
 */
class DeleteAsset
{
    public const HAS_HISTORY = 'This asset has been assigned before, so it cannot be deleted. Retire it instead.';

    public function handle(User $by, Asset $asset): void
    {
        if (! $by->can('assets.manage')) {
            throw new AuthorizationException('Only an Admin can delete an asset.');
        }

        try {
            DB::transaction(function () use ($by, $asset): void {
                $locked = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

                if (AssetAssignment::query()->where('asset_id', $locked->id)->exists()) {
                    throw ValidationException::withMessages(['asset' => self::HAS_HISTORY]);
                }

                $locked->loadMissing('category');
                $kept = ['code' => $locked->asset_code, 'name' => $locked->name, 'category' => $locked->category?->name];

                $locked->delete();

                Audit::record('assets', 'Asset deleted', $locked, ['old' => $kept], $by);
            }, 3);
        } catch (QueryException $e) {
            // Only a foreign key refusal (SQLSTATE 23000): an assignment appeared after the check above.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            throw ValidationException::withMessages(['asset' => self::HAS_HISTORY]);
        }
    }
}
