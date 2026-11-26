import { StatusPill } from '@/components/hrm/status-pill';
import { assetStatusLabel, assetStatusTone } from '@/lib/assets';

/** An asset's status as a coloured pill (colours and words come from lib/assets). */
export function AssetStatusPill({ status }: { status: string }) {
    return (
        <StatusPill tone={assetStatusTone(status)}>
            {assetStatusLabel(status)}
        </StatusPill>
    );
}
