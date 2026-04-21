import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    TrendingUp,
    TrendingDown,
    Minus,
    ShoppingCart,
    Search,
    Activity,
    Lightbulb,
    ChevronDown,
    ChevronUp,
    ArrowRight,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { InfoTooltip } from './Tooltip';

// ---------------------------------------------------------------------------
// Source badge taxonomy
// ---------------------------------------------------------------------------
// | Badge    | Color  | Icon           | Meaning                               |
// |----------|--------|----------------|---------------------------------------|
// | store    | Green  | ShoppingCart   | From WooCommerce orders / snapshots   |
// | facebook | Blue   | F glyph        | From Meta Ads API                     |
// | google   | Blue   | G glyph        | From Google Ads API                   |
// | gsc      | Grey   | Search/mag     | From Google Search Console            |
// | site     | Teal   | Activity pulse | From Lighthouse / uptime              |
// | real     | Violet | Lightbulb      | Computed by Nexstage (multi-source)   |
//
// Design discipline: always show icon + label. Tooltip on hover for sources
// where the label alone may confuse newcomers (i.e. "real").
// See: PLANNING.md "Source-Tagged MetricCard — UI Primitive (Phase 1.1)"
// ---------------------------------------------------------------------------

export type MetricSource = 'store' | 'facebook' | 'google' | 'gsc' | 'site' | 'real';

// Dot strip: each dot = one day. nil = no data (gray), true = hit, false = missed.
// Phase 1.1: binary. Phase 2+: graded (hit/near-miss/missed).
export type TrendDot = boolean | null;

const SOURCE_CONFIG: Record<
    MetricSource,
    { label: string; icon: React.ReactNode; className: string; tooltip?: string }
> = {
    store: {
        label: 'Store',
        icon: <ShoppingCart className="h-3 w-3" />,
        className: 'bg-green-50 text-green-700 border-green-200',
    },
    facebook: {
        label: 'Facebook',
        // Custom F glyph — Lucide has no Facebook icon; inline SVG keeps the dependency clean.
        icon: (
            <svg className="h-3 w-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden>
                <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" />
            </svg>
        ),
        className: 'bg-blue-50 text-[#1877F2] border-blue-200',
    },
    google: {
        label: 'Google Ads',
        // "G" letterform — distinct from GSC's search-glass.
        icon: (
            <svg className="h-3 w-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden>
                <path d="M12 11h8.533C20.84 12.244 21 13.6 21 15c0 3.866-3.134 7-7 7a9 9 0 1 1 0-18c2.395 0 4.565.937 6.18 2.462l-2.55 2.55A5.5 5.5 0 1 0 12 17.5c2.3 0 4.27-1.41 5.157-3.5H12v-3z" />
            </svg>
        ),
        className: 'bg-blue-50 text-blue-600 border-blue-200',
    },
    gsc: {
        label: 'Search Console',
        icon: <Search className="h-3 w-3" />,
        className: 'bg-zinc-100 text-zinc-600 border-zinc-200',
    },
    site: {
        label: 'Site',
        icon: <Activity className="h-3 w-3" />,
        className: 'bg-teal-50 text-teal-700 border-teal-200',
    },
    real: {
        label: 'Real',
        icon: <Lightbulb className="h-3 w-3" />,
        // Violet — distinct from all channel colors (green/blue/teal/grey).
        // Amber was avoided: it reads as a warning signal.
        className: 'bg-violet-50 text-violet-500 border-violet-200',
        // Tooltip for newcomers — explains what "Real" means vs platform-reported numbers.
        tooltip: 'Cross-source estimate derived from your actual store orders and ad spend — not platform pixel attribution, which can over-report due to iOS14+ modeled conversions.',
    },
};

// ---------------------------------------------------------------------------
// SourceBadge
// ---------------------------------------------------------------------------
// Always shows icon + label. Sources with a tooltip field show an explanation
// balloon on hover (useful for "Real" which newcomers may find confusing).
function SourceBadge({ source }: { source: MetricSource }) {
    const { label, icon, className, tooltip } = SOURCE_CONFIG[source];
    return (
        <span className="group/badge relative inline-flex items-center">
            <span
                className={cn(
                    'inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px] font-semibold leading-none cursor-default',
                    className,
                )}
            >
                {icon}
                <span>{label}</span>
            </span>
            {tooltip && (
                <span className="pointer-events-none invisible absolute bottom-full right-0 z-50 mb-2 w-56 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs leading-relaxed text-zinc-600 opacity-0 shadow-lg transition-opacity duration-150 group-hover/badge:visible group-hover/badge:opacity-100">
                    {tooltip}
                    <span className="absolute top-full right-3 border-4 border-transparent border-t-zinc-200" />
                    <span className="absolute top-full right-3 border-[3px] border-transparent border-t-white" style={{ marginTop: '-1px' }} />
                </span>
            )}
        </span>
    );
}

// ---------------------------------------------------------------------------
// TrendDotStrip — 14-day binary target-relative dots
// ---------------------------------------------------------------------------
// Phase 1.1: binary (hit/missed). Phase 2+: graded (hit/near-miss/missed).
// States: teal = hit, red = missed, gray = no data.
// See: PLANNING.md "14-day trend dot strip"
function TrendDotStrip({ dots }: { dots: TrendDot[] }) {
    return (
        <div className="flex items-center gap-0.5" aria-label="14-day target trend">
            {dots.slice(0, 14).map((dot, i) => (
                <span
                    key={i}
                    className={cn('h-1.5 w-1.5 rounded-full', {
                        'bg-teal-400': dot === true,
                        'bg-red-400': dot === false,
                        'bg-zinc-200': dot === null,
                    })}
                    title={dot === null ? 'No data' : dot ? 'Hit target' : 'Missed target'}
                />
            ))}
        </div>
    );
}

// ---------------------------------------------------------------------------
// MetricCard props
// ---------------------------------------------------------------------------
export interface MetricCardProps {
    label: string;
    value: string | null;

    // Which data source produced this number — renders the source badge.
    source?: MetricSource;

    // Optional target notation: renders "value / target" when set.
    // targetDirection is REQUIRED when target is provided — there is no safe default.
    // 'above' = above target is good (e.g. ROAS). 'below' = below is good (CPO, marketing_pct).
    // See: PLANNING.md "targetDirection is not optional for any card with a target"
    target?: number | null;
    targetDirection?: 'above' | 'below';
    targetLabel?: string; // e.g. "3.60 target" — shown as subtext next to target value

    // 14 target-relative dots (index 0 = oldest day). nil = no data.
    trendDots?: TrendDot[];

    // When true, renders a "Show more / Show less" toggle button.
    // Slot for expanded content rendered as children.
    expandable?: boolean;
    children?: React.ReactNode;

    // Change vs comparison period. Positive = good unless invertTrend = true.
    change?: number | null;
    invertTrend?: boolean;

    loading?: boolean;
    prefix?: React.ReactNode;
    subtext?: string;
    tooltip?: string;
    helpLink?: string;
    /**
     * One-line interpretation/action copy rendered below the trend dots,
     * e.g. "Held at 1.8x — below target." When paired with `actionHref`
     * the line becomes a drill-down link with an arrow.
     * @see PLANNING.md section 12 design principle 7 (action language)
     */
    actionLine?: string;
    actionHref?: string;
}

// ---------------------------------------------------------------------------
// MetricCard
// ---------------------------------------------------------------------------
const MetricCard = React.memo(function MetricCard({
    label,
    value,
    source,
    target,
    targetDirection,
    targetLabel,
    trendDots,
    expandable = false,
    children,
    change,
    invertTrend = false,
    loading = false,
    prefix,
    subtext,
    tooltip,
    helpLink,
    actionLine,
    actionHref,
}: MetricCardProps) {
    const [expanded, setExpanded] = useState(false);

    if (loading) {
        return (
            <div className="rounded-xl border border-zinc-200 bg-white p-5 space-y-3 shadow-sm">
                <div className="h-3.5 w-24 rounded bg-zinc-100 animate-pulse" />
                <div className="h-8 w-32 rounded bg-zinc-100 animate-pulse" />
                <div className="h-3 w-16 rounded bg-zinc-100 animate-pulse" />
            </div>
        );
    }

    const hasChange = change !== undefined && change !== null;
    const isPositive = hasChange ? (invertTrend ? change < 0 : change > 0) : false;
    const isNegative = hasChange ? (invertTrend ? change > 0 : change < 0) : false;
    const isNeutral = hasChange ? change === 0 : false;

    const TrendIcon = isPositive ? TrendingUp : isNegative ? TrendingDown : Minus;

    // Target color coding — only active when target + targetDirection both present.
    // No target → bare number, no color. Never color-code without an explicit target.
    let targetColorClass = '';
    if (target !== null && target !== undefined && targetDirection && value !== null) {
        const numericValue = parseFloat(value.replace(/[^0-9.-]/g, ''));
        if (!isNaN(numericValue)) {
            const isGood =
                targetDirection === 'above' ? numericValue >= target : numericValue <= target;
            targetColorClass = isGood ? 'text-green-600' : 'text-red-600';
        }
    }

    return (
        <div className="rounded-xl border border-zinc-200 bg-white p-5 space-y-1 shadow-sm hover:shadow-md transition-shadow">
            {/* Header row: label + info tooltip + why-this-number trigger + source badge */}
            <div className="flex items-center justify-between gap-2">
                <span className="flex items-center gap-1.5 text-sm font-medium text-zinc-400">
                    {label}
                    {tooltip && <InfoTooltip content={tooltip} helpLink={helpLink} />}
                </span>
                <div className="flex items-center gap-1.5">
                    {prefix}
                    {source && <SourceBadge source={source} />}
                </div>
            </div>

            {/* Value row — with optional /target notation */}
            <div
                className={cn(
                    'text-3xl font-bold tabular-nums',
                    targetColorClass || 'text-zinc-900',
                )}
            >
                {value ?? 'N/A'}
                {target !== null && target !== undefined && (
                    <span className="ml-1.5 text-base font-normal text-zinc-400">
                        / {target}
                        {targetLabel && (
                            <span className="ml-1 text-xs text-zinc-400">{targetLabel}</span>
                        )}
                    </span>
                )}
            </div>

            {/* Change pill + subtext row */}
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

            {/* 14-day trend dot strip — only rendered when trendDots provided */}
            {trendDots && trendDots.length > 0 && (
                <div className="pt-1">
                    <TrendDotStrip dots={trendDots} />
                </div>
            )}

            {/* Action line — one-liner interpretation / drill-down link. */}
            {actionLine && (
                actionHref ? (
                    <Link
                        href={actionHref}
                        className="mt-1 inline-flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-800 transition-colors"
                    >
                        <span>{actionLine}</span>
                        <ArrowRight className="h-3 w-3" />
                    </Link>
                ) : (
                    <p className="mt-1 text-xs text-zinc-500">{actionLine}</p>
                )
            )}

            {/* Expandable slot */}
            {expandable && (
                <>
                    <button
                        onClick={() => setExpanded((v) => !v)}
                        className="mt-1 flex items-center gap-0.5 text-xs text-zinc-400 hover:text-zinc-600 transition-colors"
                    >
                        {expanded ? (
                            <>
                                <ChevronUp className="h-3 w-3" /> Show less
                            </>
                        ) : (
                            <>
                                <ChevronDown className="h-3 w-3" /> Show more
                            </>
                        )}
                    </button>
                    {expanded && <div className="pt-2">{children}</div>}
                </>
            )}
        </div>
    );
});

export { MetricCard, SourceBadge };
