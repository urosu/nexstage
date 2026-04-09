import React from 'react';
import { TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { cn } from '@/lib/utils';
import { InfoTooltip } from './Tooltip';

interface MetricCardProps {
    label: string;
    value: string | null;
    /** Change percentage vs comparison period. Positive = good, negative = bad. */
    change?: number | null;
    /** When true, a negative change is good (e.g. cost metrics) */
    invertTrend?: boolean;
    /** Show skeleton state */
    loading?: boolean;
    /** Optional prefix icon or badge */
    prefix?: React.ReactNode;
    /** Optional subtext below value */
    subtext?: string;
    /** Balloon tooltip shown on hover of ⓘ icon next to the label */
    tooltip?: string;
}

const MetricCard = React.memo(function MetricCard({
    label,
    value,
    change,
    invertTrend = false,
    loading = false,
    prefix,
    subtext,
    tooltip,
}: MetricCardProps) {
    const hasChange = change !== undefined && change !== null;

    const isPositive = hasChange
        ? invertTrend
            ? change < 0
            : change > 0
        : false;
    const isNegative = hasChange
        ? invertTrend
            ? change > 0
            : change < 0
        : false;
    const isNeutral = hasChange ? change === 0 : false;

    const TrendIcon = isPositive ? TrendingUp : isNegative ? TrendingDown : Minus;

    if (loading) {
        return (
            <div className="rounded-xl border border-zinc-200 bg-white p-5 space-y-3">
                <div className="h-3.5 w-24 rounded bg-zinc-100 animate-pulse" />
                <div className="h-8 w-32 rounded bg-zinc-100 animate-pulse" />
                <div className="h-3 w-16 rounded bg-zinc-100 animate-pulse" />
            </div>
        );
    }

    return (
        <div className="rounded-xl border border-zinc-200 bg-white p-5 space-y-1">
            <div className="flex items-center justify-between">
                <span className="flex items-center gap-1.5 text-sm font-medium text-zinc-400">
                    {label}
                    {tooltip && <InfoTooltip content={tooltip} />}
                </span>
                {prefix}
            </div>

            <div className="text-2xl font-semibold text-zinc-900 tabular-nums">
                {value ?? 'N/A'}
            </div>

            <div className="flex items-center gap-1.5 min-h-[20px]">
                {hasChange && (
                    <>
                        <span
                            className={cn(
                                'flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-xs font-semibold',
                                isPositive && 'bg-green-50 text-green-700',
                                isNegative && 'bg-red-50 text-red-700',
                                isNeutral && 'bg-zinc-100 text-zinc-500',
                            )}
                        >
                            <TrendIcon className="h-3 w-3" />
                            {Math.abs(change).toFixed(1)}%
                        </span>
                        <span className="text-xs text-zinc-400">vs prior period</span>
                    </>
                )}
                {subtext && !hasChange && (
                    <span className="text-xs text-zinc-400">{subtext}</span>
                )}
            </div>
        </div>
    );
});

export { MetricCard };
export type { MetricCardProps };
