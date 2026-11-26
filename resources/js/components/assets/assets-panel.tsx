import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { StatusPill } from '@/components/hrm/status-pill';
import { assetConditionLabel } from '@/lib/assets';
import { formatMoney } from '@/lib/payroll';

/** The "My Assets" page (every signed-in user). */
export const MY_ASSETS_HREF = '/my/assets';

/** One asset on the profile's Assets tab. `cost` (paisa) is only sent with assets.assign. */
export type ProfileAsset = {
    /** The assignment row. */
    id: number;
    asset_id: number;
    code: string;
    name: string;
    category: string;
    assigned_on: string;
    condition_out: string;
    cost?: number | null;
    /** Only on history rows. */
    returned_on?: string;
    return_condition?: string | null;
};

/** The profile's Assets tab data: what the person holds and the last 20 returned. */
export type ProfileAssets = {
    holding: ProfileAsset[];
    history: ProfileAsset[];
    can_assign: boolean;
};

const linkClass =
    'inline-flex items-center gap-1 text-sm font-medium text-brand-ink hover:underline';

function AssetRow({
    asset,
    canOpen,
    date,
}: {
    asset: ProfileAsset;
    canOpen: boolean;
    date: (value: string) => string | null;
}) {
    const returned = asset.returned_on !== undefined;

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 text-sm font-medium">
                    {canOpen ? (
                        <Link
                            href={`/assets/${asset.asset_id}`}
                            className="truncate hover:underline"
                        >
                            {asset.name}
                        </Link>
                    ) : (
                        <span className="truncate">{asset.name}</span>
                    )}
                    <span className="rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
                        {asset.code}
                    </span>
                </div>
                <div className="mt-0.5 text-xs text-muted-foreground">
                    {asset.category} · Given {date(asset.assigned_on)}
                    {returned && <> · Returned {date(asset.returned_on!)}</>}
                    {typeof asset.cost === 'number' && (
                        <> · {formatMoney(asset.cost)}</>
                    )}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-1.5">
                <StatusPill tone="grey">
                    Out: {assetConditionLabel(asset.condition_out)}
                </StatusPill>
                {returned && asset.return_condition && (
                    <StatusPill tone="grey">
                        Back: {assetConditionLabel(asset.return_condition)}
                    </StatusPill>
                )}
            </div>
        </li>
    );
}

/** The Assets tab: what the person holds, the last returned, and (assets.assign) links to the asset pages. */
export function AssetsPanel({
    assets,
    date,
}: {
    assets: ProfileAssets;
    date: (value: string) => string | null;
}) {
    const empty = assets.holding.length === 0 && assets.history.length === 0;

    return (
        <div className="grid gap-6">
            {empty ? (
                <p className="text-sm text-muted-foreground">
                    Nothing assigned yet.
                </p>
            ) : (
                <>
                    <section className="grid gap-3">
                        <h3 className="text-sm font-semibold">Holding now</h3>
                        {assets.holding.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing held right now.
                            </p>
                        ) : (
                            <ul
                                role="list"
                                className="divide-y rounded-lg border"
                            >
                                {assets.holding.map((asset) => (
                                    <AssetRow
                                        key={asset.id}
                                        asset={asset}
                                        canOpen={assets.can_assign}
                                        date={date}
                                    />
                                ))}
                            </ul>
                        )}
                    </section>

                    {assets.history.length > 0 && (
                        <section className="grid gap-3">
                            <h3 className="text-sm font-semibold">
                                Returned (last {assets.history.length})
                            </h3>
                            <ul
                                role="list"
                                className="divide-y rounded-lg border"
                            >
                                {assets.history.map((asset) => (
                                    <AssetRow
                                        key={asset.id}
                                        asset={asset}
                                        canOpen={assets.can_assign}
                                        date={date}
                                    />
                                ))}
                            </ul>
                        </section>
                    )}
                </>
            )}

            {assets.can_assign && (
                <div>
                    <Link href="/assets" className={linkClass}>
                        Assign an asset
                        <ArrowRight aria-hidden="true" className="size-3.5" />
                    </Link>
                </div>
            )}
        </div>
    );
}

/** What the dashboard's "My assets" card receives. */
export type MyAssets = { count: number; names: string[]; more: number };

/** The dashboard card: how many assets the viewer holds and the first names. */
export function MyAssetsCard({ mine }: { mine: MyAssets }) {
    return (
        <div className="flex min-h-64 flex-col rounded-xl border bg-card shadow-xs">
            <div className="flex items-center justify-between gap-3 border-b px-5 py-4">
                <h3 className="text-base font-semibold">My assets</h3>
                <Link href={MY_ASSETS_HREF} className={linkClass}>
                    My Assets
                    <ArrowRight aria-hidden="true" className="size-3.5" />
                </Link>
            </div>
            {mine.count === 0 ? (
                <div className="flex flex-1 items-center justify-center p-6 text-center text-sm text-muted-foreground">
                    Nothing assigned to you.
                </div>
            ) : (
                <div className="p-5">
                    <p className="text-3xl font-semibold tabular-nums">
                        {mine.count}
                    </p>
                    <ul role="list" className="mt-3 grid gap-1.5 text-sm">
                        {mine.names.map((name, index) => (
                            <li key={`${index}-${name}`} className="truncate">
                                {name}
                            </li>
                        ))}
                    </ul>
                    {mine.more > 0 && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            and {mine.more} more
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
