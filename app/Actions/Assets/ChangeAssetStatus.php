<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\User;
use App\Support\Assets\AssetRules;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move an asset between available, in repair and retired by hand (the matrix lives in AssetRules). One transaction,
 * audited in it (area assets). The asset row is locked first and its status and open assignment are read after that,
 * so a hand-over that committed first is seen ("held by ... Return it first.") even when the caller's model is stale.
 * A refusal is a ValidationException on `status`.
 */
class ChangeAssetStatus
{
    public function handle(User $by, Asset $asset, string $to): Asset
    {
        return DB::transaction(function () use ($by, $asset, $to): Asset {
            $locked = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            return $this->within($by, $locked, $to);
        });
    }

    /**
     * For a caller that already holds a transaction and has $locked read FOR UPDATE (the import).
     */
    public function within(User $by, Asset $locked, string $to): Asset
    {
        $locked->load('openAssignment.employee');
        $from = $locked->status;

        $refusal = AssetRules::statusRefusal($by, $from, $to, $locked->openAssignment !== null, $locked);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['status' => $refusal]);
        }

        $locked->status = $to;
        $locked->save();

        Audit::record('assets', 'Asset status changed', $locked, [
            'old' => ['status' => $from],
            'attributes' => ['status' => $to],
        ], $by);

        return $locked;
    }
}
