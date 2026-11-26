import { StatusPill } from '@/components/hrm/status-pill';
import {
    cycleStateLabel,
    cycleStateTone,
    reviewStateLabel,
    reviewStateTone,
} from '@/lib/performance';

/** A review's state as a coloured pill: "Self-review", "With manager", "Ready to close", "Closed", "Skipped". */
export function ReviewStatePill({ state }: { state: string }) {
    return (
        <StatusPill tone={reviewStateTone(state)}>
            {reviewStateLabel(state)}
        </StatusPill>
    );
}

/** A cycle's state as a pill: "Open" or "Closed". */
export function CycleStatePill({ state }: { state: string }) {
    return (
        <StatusPill tone={cycleStateTone(state)}>
            {cycleStateLabel(state)}
        </StatusPill>
    );
}
