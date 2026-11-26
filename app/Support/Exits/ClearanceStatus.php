<?php

namespace App\Support\Exits;

use App\Models\AssetAssignment;
use App\Models\Clearance;
use App\Models\FinalSettlement;
use App\Models\Resignation;
use App\Support\Assets\AssetHoldings;

/**
 * An exit's clearance checklist as it stands now (design 5.4).
 *
 *   ClearanceStatus::for($resignation);
 *   // ['items' => [[id, kind, label, cleared, cleared_by, cleared_at, automatic, held?], ...],
 *   //  'complete' => bool, 'missing' => ['System access removed', ...]]
 *
 * The "assets" item is never ticked by hand: until the settlement is approved it is worked out live from the person's
 * open asset assignments (AssetHoldings::openFor): cleared when nothing is held, else not cleared with `held` = the
 * held asset codes. Once the settlement is approved (frozen) the stored tick is shown as it was written then.
 * cleared_by is the user id (null for the automatic item), cleared_at "Y-m-d H:i" or null.
 *
 * Complete = every hand item ticked and nothing held. missing = the labels of what is not cleared, in order.
 */
final class ClearanceStatus
{
    /**
     * @return array{items: list<array{id: int, kind: string, label: string, cleared: bool, cleared_by: int|null, cleared_at: string|null, automatic: bool, held?: list<string>}>, complete: bool, missing: list<string>}
     */
    public static function for(Resignation $resignation): array
    {
        $rows = Clearance::query()->where('resignation_id', $resignation->id)->orderBy('sort_order')->orderBy('id')->get();
        $frozen = FinalSettlement::query()->where('resignation_id', $resignation->id)
            ->whereIn('status', (array) config('hrm.exit.frozen_statuses'))->exists();
        $held = null;

        $items = [];
        $missing = [];

        foreach ($rows as $row) {
            $item = [
                'id' => (int) $row->id,
                'kind' => (string) $row->kind,
                'label' => (string) $row->label,
                'cleared' => $row->isCleared(),
                'cleared_by' => $row->cleared_by === null ? null : (int) $row->cleared_by,
                'cleared_at' => $row->cleared_at?->format('Y-m-d H:i'),
                'automatic' => $row->kind === 'assets',
            ];

            if ($row->kind === 'assets' && ! $frozen) {
                $held ??= array_values(AssetHoldings::openFor($resignation->employee)
                    ->map(fn (AssetAssignment $a): string => (string) $a->asset?->asset_code)
                    ->all());
                $item['cleared'] = $held === [];
                $item['cleared_by'] = null;
                $item['cleared_at'] = null;
                $item['held'] = $held;
            }

            if (! $item['cleared']) {
                $missing[] = $item['label'];
            }

            $items[] = $item;
        }

        return ['items' => $items, 'complete' => $missing === [], 'missing' => $missing];
    }
}
