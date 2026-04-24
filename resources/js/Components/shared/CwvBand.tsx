import { cn } from '@/lib/utils';

export type CwvMetric = 'lcp' | 'inp' | 'cls';
export type CwvBandLevel = 'good' | 'needs-improvement' | 'poor' | 'unknown';

/**
 * §M3 — CWV three-band classification.
 * Thresholds match Google's CWV standards (same colors as GSC CWV report).
 *
 * LCP:  ≤2500ms good  | ≤4000ms needs-improvement  | >4000ms poor
 * INP:  ≤200ms  good  | ≤500ms  needs-improvement  | >500ms  poor
 * CLS:  ≤0.10   good  | ≤0.25   needs-improvement  | >0.25   poor
 */
export function cwvBand(metric: CwvMetric, value: number): CwvBandLevel {
    if (metric === 'lcp') {
        if (value <= 2500) return 'good';
        if (value <= 4000) return 'needs-improvement';
        return 'poor';
    }
    if (metric === 'inp') {
        if (value <= 200) return 'good';
        if (value <= 500) return 'needs-improvement';
        return 'poor';
    }
    // cls
    if (value <= 0.10) return 'good';
    if (value <= 0.25) return 'needs-improvement';
    return 'poor';
}

const BAND_STYLES: Record<CwvBandLevel, string> = {
    'good':             'bg-green-100 text-green-700',
    'needs-improvement':'bg-amber-100 text-amber-700',
    'poor':             'bg-red-100   text-red-700',
    'unknown':          'bg-zinc-100  text-zinc-400',
};

const BAND_LABELS: Record<CwvBandLevel, string> = {
    'good':             'Good',
    'needs-improvement':'Needs Improvement',
    'poor':             'Poor',
    'unknown':          '—',
};

interface CwvBandProps {
    metric: CwvMetric;
    value: number | null;
    showLabel?: boolean;
    className?: string;
}

/**
 * Colored pill showing the §M3 three-band assessment for LCP, INP, or CLS.
 * Use this everywhere CWV values are displayed to ensure consistent vocabulary
 * across Home, Performance, and Organic pages.
 */
export function CwvBand({ metric, value, showLabel = true, className }: CwvBandProps) {
    const level = value !== null ? cwvBand(metric, value) : 'unknown';
    const label = BAND_LABELS[level];
    const style = BAND_STYLES[level];

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                style,
                className,
            )}
        >
            {showLabel ? label : null}
        </span>
    );
}
