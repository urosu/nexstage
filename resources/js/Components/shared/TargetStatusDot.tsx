import React from 'react';
import { cn } from '@/lib/utils';

export type TargetStatusDirection = 'higher_better' | 'lower_better';

export type TargetStatusLevel =
    | 'excellent'
    | 'on_target'
    | 'fair'
    | 'poor'
    | 'above_peers'
    | 'at_peers'
    | 'below_peers'
    | 'no_baseline';

export interface TargetStatusResult {
    level: TargetStatusLevel;
    label: string;
    dotClass: string;
    valueClass: string;
}

const STATUS_CONFIG: Record<
    TargetStatusLevel,
    { label: string; dotClass: string; valueClass: string }
> = {
    excellent:   { label: 'Excellent',       dotClass: 'bg-green-500', valueClass: 'text-green-600' },
    on_target:   { label: 'On target',       dotClass: 'bg-green-500', valueClass: 'text-green-600' },
    fair:        { label: 'Fair',            dotClass: 'bg-amber-400', valueClass: 'text-amber-600' },
    poor:        { label: 'Poor',            dotClass: 'bg-red-500',   valueClass: 'text-red-600'   },
    above_peers: { label: 'Above peers',     dotClass: 'bg-green-500', valueClass: 'text-green-600' },
    at_peers:    { label: 'At peers',        dotClass: 'bg-amber-400', valueClass: 'text-amber-600' },
    below_peers: { label: 'Below peers',     dotClass: 'bg-red-500',   valueClass: 'text-red-600'   },
    no_baseline: { label: 'No baseline yet', dotClass: 'bg-zinc-300',  valueClass: ''               },
};

/**
 * Compute the target-status level per §M6 rubric.
 *
 * Priority: target (4 levels) → peerMedian (3 levels) → no_baseline.
 * direction='lower_better' inverts ratios so a lower actual value counts as better
 * (used for cost metrics: CPO, CPC, CPA, CPM).
 *
 * @see PROGRESS.md §M6
 */
export function computeTargetStatus(
    actual: number | null | undefined,
    target: number | null | undefined,
    peerMedian: number | null | undefined,
    direction: TargetStatusDirection = 'higher_better',
): TargetStatusResult {
    const noBaseline: TargetStatusResult = { level: 'no_baseline', ...STATUS_CONFIG.no_baseline };

    if (actual == null) return noBaseline;

    const invert = direction === 'lower_better';

    if (target != null && target !== 0) {
        const ratio = invert ? target / actual : actual / target;
        if (ratio >= 1.10) return { level: 'excellent',  ...STATUS_CONFIG.excellent  };
        if (ratio >= 0.95) return { level: 'on_target',  ...STATUS_CONFIG.on_target  };
        if (ratio >= 0.85) return { level: 'fair',       ...STATUS_CONFIG.fair       };
        return                     { level: 'poor',       ...STATUS_CONFIG.poor       };
    }

    if (peerMedian != null && peerMedian !== 0) {
        const ratio = invert ? peerMedian / actual : actual / peerMedian;
        if (ratio >= 1.15) return { level: 'above_peers', ...STATUS_CONFIG.above_peers };
        if (ratio >= 0.90) return { level: 'at_peers',    ...STATUS_CONFIG.at_peers    };
        return                     { level: 'below_peers', ...STATUS_CONFIG.below_peers };
    }

    return noBaseline;
}

interface TargetStatusDotProps {
    actual: number | null | undefined;
    target?: number | null;
    peerMedian?: number | null;
    direction?: TargetStatusDirection;
    /** Render the text label next to the dot. Default false. */
    showLabel?: boolean;
    className?: string;
}

/**
 * Small colored dot indicating how a metric compares against its target or peer median.
 * Green = on/above target, amber = fair/at peers, red = poor/below peers, gray = no baseline.
 * Tooltip on hover shows the label text ("Excellent", "Fair", etc.).
 *
 * @see PROGRESS.md §M6 for the full rubric
 */
export function TargetStatusDot({
    actual,
    target,
    peerMedian,
    direction = 'higher_better',
    showLabel = false,
    className,
}: TargetStatusDotProps) {
    const status = computeTargetStatus(actual, target, peerMedian, direction);

    return (
        <span
            className={cn('group/statusdot relative inline-flex items-center gap-1 cursor-default', className)}
            aria-label={status.label}
        >
            <span className={cn('h-2 w-2 rounded-full flex-shrink-0', status.dotClass)} />
            {showLabel && (
                <span className="text-xs text-zinc-500">{status.label}</span>
            )}
            <span className="pointer-events-none invisible absolute bottom-full left-1/2 -translate-x-1/2 z-50 mb-2 whitespace-nowrap rounded border border-zinc-200 bg-white px-2 py-1 text-xs text-zinc-600 opacity-0 shadow-md transition-opacity duration-150 group-hover/statusdot:visible group-hover/statusdot:opacity-100">
                {status.label}
            </span>
        </span>
    );
}
