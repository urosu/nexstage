import { Link } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Props {
    score: number | null;
    lcp_ms: number | null;
    uptime_pct: number | null;
    performanceHref: string;
}

/** Lighthouse three-band: 0–49 red / 50–89 amber / 90+ green */
function lighthouseColor(score: number): string {
    if (score >= 90) return 'text-green-600';
    if (score >= 50) return 'text-amber-600';
    return 'text-red-600';
}

function lighthouseBand(score: number): string {
    if (score >= 90) return 'good';
    if (score >= 50) return 'amber';
    return 'poor';
}

/** §M3: LCP ≤2500ms green / ≤4000ms amber / >4000ms red */
function lcpColor(ms: number): string {
    if (ms <= 2500) return 'text-green-600';
    if (ms <= 4000) return 'text-amber-600';
    return 'text-red-600';
}

/** Uptime: ≥99.5% green / ≥99% amber / <99% red */
function uptimeColor(pct: number): string {
    if (pct >= 99.5) return 'text-green-600';
    if (pct >= 99)   return 'text-amber-600';
    return 'text-red-600';
}

/**
 * Compact one-row site health summary for the Home page.
 *
 * Shows Lighthouse performance score, LCP, and 30-day uptime — all linked to
 * the Performance page. Colors per §M3 three-band vocabulary so users who know
 * GSC's own CWV color scheme immediately recognize the encoding.
 *
 * All metrics are null-safe: omits any metric that has no data.
 * @see PROGRESS.md §Phase 3.6 — Home rebuild — SiteHealthStrip
 * @see PROGRESS.md §M3 — CWV three-band color
 */
export function SiteHealthStrip({ score, lcp_ms, uptime_pct, performanceHref }: Props) {
    const hasAny = score !== null || lcp_ms !== null || uptime_pct !== null;
    if (!hasAny) return null;

    const metrics: { label: string; value: string; colorClass: string }[] = [];

    if (score !== null) {
        metrics.push({
            label:      'Lighthouse',
            value:      `${score} (${lighthouseBand(score)})`,
            colorClass: lighthouseColor(score),
        });
    }

    if (lcp_ms !== null) {
        metrics.push({
            label:      'LCP',
            value:      `${(lcp_ms / 1000).toFixed(1)}s`,
            colorClass: lcpColor(lcp_ms),
        });
    }

    if (uptime_pct !== null) {
        metrics.push({
            label:      'Uptime 30d',
            value:      `${uptime_pct.toFixed(1)}%`,
            colorClass: uptimeColor(uptime_pct),
        });
    }

    return (
        <Link
            href={performanceHref}
            className="flex items-center gap-1.5 rounded-lg border border-zinc-100 bg-zinc-50 px-4 py-2.5 text-xs transition-colors hover:border-zinc-200 hover:bg-zinc-100"
        >
            <Activity className="h-3.5 w-3.5 flex-shrink-0 text-teal-600" />
            <span className="mr-1 font-medium text-zinc-500">Site health</span>

            {metrics.map((m, i) => (
                <span key={i} className="flex items-center gap-1">
                    {i > 0 && <span className="text-zinc-300">·</span>}
                    <span className="text-zinc-400">{m.label}</span>
                    <span className={cn('font-semibold tabular-nums', m.colorClass)}>{m.value}</span>
                </span>
            ))}

            <span className="ml-auto text-zinc-400">View Performance →</span>
        </Link>
    );
}
