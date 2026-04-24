import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    LineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip as RechartTooltip,
    ResponsiveContainer,
    ReferenceLine,
    ReferenceArea,
} from 'recharts';
import { AlertTriangle, ChevronDown } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { MetricCard } from '@/Components/shared/MetricCard';
import { PageHeader } from '@/Components/shared/PageHeader';
import { PageNarrative } from '@/Components/shared/PageNarrative';
import { CwvBand } from '@/Components/shared/CwvBand';
import type { CwvMetric } from '@/Components/shared/CwvBand';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
import { formatCurrency } from '@/lib/formatters';
import type { PageProps } from '@/types';
import type { HolidayOverlay, WorkspaceEventOverlay } from '@/Components/charts/MultiSeriesLineChart';

// ─── Types ────────────────────────────────────────────────────────────────────

interface StoreUrlItem {
    id: number;
    url: string;
    label: string | null;
    is_homepage: boolean;
    store_id: number;
    store_name: string | null;
    store_slug: string | null;
}

type CruxSource = 'url' | 'origin' | null;

interface LatestScores {
    performance_score: number | null;
    seo_score: number | null;
    accessibility_score: number | null;
    best_practices_score: number | null;
    lcp_ms: number | null;
    fcp_ms: number | null;
    cls_score: number | null;
    inp_ms: number | null;
    ttfb_ms: number | null;
    tbt_ms: number | null;
    crux_source: CruxSource;
    crux_lcp_p75_ms: number | null;
    crux_inp_p75_ms: number | null;
    crux_cls_p75: number | null;
    crux_fcp_p75_ms: number | null;
    crux_ttfb_p75_ms: number | null;
    checked_at: string | null;
}

interface HistoryPoint {
    date: string;
    checked_at: string;
    performance_score: number | null;
    seo_score: number | null;
    accessibility_score: number | null;
    best_practices_score: number | null;
    lcp_ms: number | null;
    cls_score: number | null;
    inp_ms: number | null;
    crux_source: CruxSource;
    crux_lcp_p75_ms: number | null;
    crux_inp_p75_ms: number | null;
    crux_cls_p75: number | null;
}

interface ScoreDelta {
    performance:    number | null;
    seo:            number | null;
    accessibility:  number | null;
    best_practices: number | null;
}

interface UrlSummaryRow extends StoreUrlItem {
    mobile_performance_score: number | null;
    mobile_seo_score: number | null;
    mobile_lcp_ms: number | null;
    mobile_inp_ms: number | null;
    desktop_performance_score: number | null;
    desktop_seo_score: number | null;
    desktop_lcp_ms: number | null;
    last_checked_at: string | null;
    monthly_orders: number;
    revenue_risk: number;
}

interface PerformanceAudit {
    id: string;
    title: string;
    description: string | null;
    score: number | null;
    weight: number;
    display_value: string | null;
}

interface PerformanceAlert {
    type: 'score_drop' | 'lcp_regression' | 'new_failing_audit';
    severity: 'warning' | 'critical';
    message: string;
    url_id: number;
    url_label: string;
    delta: number;
}

interface Props extends PageProps {
    store_urls: StoreUrlItem[];
    selected_url_id: number | null;
    mobile_latest: LatestScores | null;
    desktop_latest: LatestScores | null;
    mobile_history: HistoryPoint[];
    desktop_history: HistoryPoint[];
    mobile_score_delta: ScoreDelta | null;
    desktop_score_delta: ScoreDelta | null;
    url_summary: UrlSummaryRow[];
    holiday_overlays: HolidayOverlay[];
    workspace_event_overlays: WorkspaceEventOverlay[];
    from: string;
    to: string;
    revenue_at_risk: number;
    performance_audits: PerformanceAudit[];
    performance_alerts: PerformanceAlert[];
    narrative: string | null;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

type ScoreGrade = 'good' | 'needs-improvement' | 'poor' | 'unknown';

function scoreGrade(score: number | null): ScoreGrade {
    if (score === null) return 'unknown';
    if (score >= 90)   return 'good';
    if (score >= 50)   return 'needs-improvement';
    return 'poor';
}

function scoreColor(grade: ScoreGrade): string {
    switch (grade) {
        case 'good':              return 'text-green-600';
        case 'needs-improvement': return 'text-amber-600';
        case 'poor':              return 'text-red-600';
        default:                  return 'text-zinc-400';
    }
}

function scoreBg(grade: ScoreGrade): string {
    switch (grade) {
        case 'good':              return 'bg-green-50  border-green-200';
        case 'needs-improvement': return 'bg-amber-50  border-amber-200';
        case 'poor':              return 'bg-red-50    border-red-200';
        default:                  return 'bg-zinc-50   border-zinc-200';
    }
}

function fmtMs(ms: number | null): string {
    if (ms === null) return '—';
    if (ms >= 1000)  return `${(ms / 1000).toFixed(2)} s`;
    return `${ms} ms`;
}

function fmtCls(cls: number | null): string {
    if (cls === null) return '—';
    return cls.toFixed(3);
}

function fmtDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en', { month: 'short', day: 'numeric' });
}

// ─── Score card ───────────────────────────────────────────────────────────────

function ScoreCard({ label, score, delta }: { label: string; score: number | null; delta?: number | null }) {
    const grade = scoreGrade(score);
    return (
        <div className={cn('rounded-xl border p-4 space-y-1', scoreBg(grade))}>
            <div className="text-xs font-medium text-zinc-500">{label}</div>
            <div className={cn('text-2xl font-bold tabular-nums', scoreColor(grade))}>
                {score !== null ? score : '—'}
            </div>
            <div className="text-xs text-zinc-400">/ 100</div>
            {delta != null && (
                <div className={cn(
                    'flex w-fit items-center rounded-full px-1.5 py-0.5 text-xs font-semibold',
                    delta > 0  ? 'bg-green-100 text-green-700'
                    : delta < 0 ? 'bg-red-100 text-red-700'
                    :             'bg-zinc-100 text-zinc-500',
                )}>
                    {delta > 0 ? '+' : ''}{delta} pts
                </div>
            )}
        </div>
    );
}

// ─── CWV card ─────────────────────────────────────────────────────────────────

// Source badge config: Field (real Chrome users) > Origin (domain-level) > Lab (synthetic)
const CRUX_SOURCE_BADGE: Record<string, { label: string; className: string }> = {
    url:    { label: 'Field',   className: 'bg-emerald-50 text-emerald-700 border border-emerald-200' },
    origin: { label: 'Origin',  className: 'bg-blue-50 text-blue-700 border border-blue-200' },
    lab:    { label: 'Lab',     className: 'bg-zinc-100 text-zinc-500 border border-zinc-200' },
};

function CwvCard({
    label,
    value,
    metric,
    rawValue,
    description,
    source,
}: {
    label: string;
    value: string;
    metric: CwvMetric | null;
    rawValue: number | null;
    description: string;
    source?: string | null;
}) {
    const badge = source ? CRUX_SOURCE_BADGE[source] : null;
    return (
        <div className="rounded-xl border border-zinc-200 bg-white p-4 space-y-1">
            <div className="flex items-center justify-between gap-1">
                <span className="text-xs font-medium text-zinc-500">{label}</span>
                <div className="flex items-center gap-1.5">
                    {badge && (
                        <span className={cn('rounded-full px-1.5 py-0.5 text-xs font-medium', badge.className)}>
                            {badge.label}
                        </span>
                    )}
                    {metric !== null
                        ? <CwvBand metric={metric} value={rawValue} />
                        : <span className="rounded-full px-2 py-0.5 text-xs font-semibold bg-zinc-100 text-zinc-400">—</span>
                    }
                </div>
            </div>
            <div className="text-xl font-semibold text-zinc-900 tabular-nums">{value}</div>
            <div className="text-xs text-zinc-400">{description}</div>
        </div>
    );
}

// ─── Strategy column ──────────────────────────────────────────────────────────
// Renders scores + CWV for one strategy (mobile or desktop).

function StrategyColumn({
    label,
    scores,
    delta,
}: {
    label: string;
    scores: LatestScores | null;
    delta?: ScoreDelta | null;
}) {
    const lastChecked = scores?.checked_at
        ? new Date(scores.checked_at).toLocaleDateString('en', {
              month: 'short', day: 'numeric',
          })
        : null;

    return (
        <div className="space-y-4 flex-1 min-w-0">
            {/* Strategy label + checked timestamp */}
            <div className="flex items-baseline gap-3">
                <h2 className="text-sm font-semibold text-zinc-700">{label}</h2>
                {lastChecked && (
                    <span className="text-xs text-zinc-400">checked {lastChecked}</span>
                )}
            </div>

            {scores === null ? (
                <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-6 text-center text-sm text-zinc-400">
                    No data yet
                </div>
            ) : (
                <>
                    {/* Lighthouse score cards */}
                    <div className="grid grid-cols-2 gap-3">
                        <ScoreCard label="Performance"    score={scores.performance_score}    delta={delta?.performance} />
                        <ScoreCard label="Accessibility"  score={scores.accessibility_score}  delta={delta?.accessibility} />
                        <ScoreCard label="SEO"            score={scores.seo_score}            delta={delta?.seo} />
                        <ScoreCard label="Best Practices" score={scores.best_practices_score} delta={delta?.best_practices} />
                    </div>

                    {/* Core Web Vitals — prefer CrUX field data, fall back to lab */}
                    <div className="grid grid-cols-2 gap-3">
                        {(() => {
                            const cruxSource = scores.crux_source;
                            const lcpVal  = scores.crux_lcp_p75_ms ?? scores.lcp_ms;
                            const clsVal  = scores.crux_cls_p75    ?? scores.cls_score;
                            const inpVal  = scores.crux_inp_p75_ms ?? scores.inp_ms;
                            const ttfbVal = scores.crux_ttfb_p75_ms ?? scores.ttfb_ms;
                            const cwvSrc  = (_: number | null, cruxV: number | null) =>
                                cruxV != null ? (cruxSource ?? 'lab') : 'lab';
                            return (
                                <>
                                    <CwvCard
                                        label="LCP"
                                        value={fmtMs(lcpVal)}
                                        metric="lcp"
                                        rawValue={lcpVal}
                                        source={cwvSrc(scores.lcp_ms, scores.crux_lcp_p75_ms)}
                                        description="Largest Contentful Paint · ≤ 2.5 s"
                                    />
                                    <CwvCard
                                        label="CLS"
                                        value={fmtCls(clsVal)}
                                        metric="cls"
                                        rawValue={clsVal}
                                        source={cwvSrc(scores.cls_score, scores.crux_cls_p75)}
                                        description="Cumulative Layout Shift · ≤ 0.10"
                                    />
                                    <CwvCard
                                        label="INP"
                                        value={fmtMs(inpVal)}
                                        metric="inp"
                                        rawValue={inpVal}
                                        source={cwvSrc(scores.inp_ms, scores.crux_inp_p75_ms)}
                                        description={inpVal === null
                                            ? 'Interaction to Next Paint · no data (requires real users)'
                                            : 'Interaction to Next Paint · ≤ 200 ms'}
                                    />
                                    <CwvCard
                                        label="TTFB"
                                        value={fmtMs(ttfbVal)}
                                        metric={null}
                                        rawValue={ttfbVal}
                                        source={cwvSrc(scores.ttfb_ms, scores.crux_ttfb_p75_ms)}
                                        description="Time to First Byte"
                                    />
                                </>
                            );
                        })()}
                    </div>
                </>
            )}
        </div>
    );
}

// ─── Score trend chart ────────────────────────────────────────────────────────

type ChartStrategy = 'mobile' | 'desktop';

// Hex values mirror the --chart-* CSS variables (indigo, emerald, amber, rose).
const SCORE_SERIES = [
    { key: 'performance_score',    label: 'Performance',    color: '#4f46e5' },
    { key: 'seo_score',            label: 'SEO',            color: '#10b981' },
    { key: 'accessibility_score',  label: 'Accessibility',  color: '#f59e0b' },
    { key: 'best_practices_score', label: 'Best Practices', color: '#f43f5e' },
] as const;

type ScoreSeriesKey = typeof SCORE_SERIES[number]['key'];

function ScoreTrendChart({
    mobileHistory,
    desktopHistory,
    holidays,
    workspaceEvents,
}: {
    mobileHistory: HistoryPoint[];
    desktopHistory: HistoryPoint[];
    holidays: HolidayOverlay[];
    workspaceEvents: WorkspaceEventOverlay[];
}) {
    const [strategy, setStrategy] = useState<ChartStrategy>('mobile');
    const [visible, setVisible] = useState<Set<ScoreSeriesKey>>(
        () => new Set<ScoreSeriesKey>(['performance_score']),
    );
    const data = strategy === 'mobile' ? mobileHistory : desktopHistory;

    function toggleSeries(key: ScoreSeriesKey) {
        setVisible((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                if (next.size > 1) next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    }

    if (mobileHistory.length === 0 && desktopHistory.length === 0) {
        return (
            <div className="flex h-48 items-center justify-center text-sm text-zinc-400">
                No history data for the selected date range.
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {/* Controls row: strategy toggle + series pills */}
            <div className="flex flex-wrap items-center gap-3">
                <div className="flex rounded-lg border border-zinc-200 bg-white text-xs font-medium overflow-hidden">
                    {(['mobile', 'desktop'] as const).map((s) => (
                        <button
                            key={s}
                            onClick={() => setStrategy(s)}
                            className={cn(
                                'px-3 py-1.5 capitalize transition-colors',
                                strategy === s
                                    ? 'bg-zinc-800 text-white'
                                    : 'text-zinc-500 hover:bg-zinc-50'
                            )}
                        >
                            {s}
                        </button>
                    ))}
                </div>

                <div className="flex flex-wrap gap-1.5">
                    {SCORE_SERIES.map((s) => {
                        const on = visible.has(s.key);
                        return (
                            <button
                                key={s.key}
                                onClick={() => toggleSeries(s.key)}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                                    on
                                        ? 'text-white'
                                        : 'border-zinc-200 bg-white text-zinc-400 hover:text-zinc-600',
                                )}
                                style={on ? { backgroundColor: s.color, borderColor: s.color } : undefined}
                            >
                                <span
                                    className="h-1.5 w-1.5 rounded-full flex-shrink-0"
                                    style={{ backgroundColor: on ? 'rgba(255,255,255,0.7)' : s.color }}
                                />
                                {s.label}
                            </button>
                        );
                    })}
                </div>
            </div>

            {data.length === 0 ? (
                <div className="flex h-36 items-center justify-center text-sm text-zinc-400">
                    No {strategy} history in this date range.
                </div>
            ) : (
                <ResponsiveContainer width="100%" height={220}>
                    <LineChart data={data} margin={{ top: 4, right: 16, bottom: 0, left: 0 }}>
                        {/* PSI threshold bands: good ≥90, needs improvement 50-89, poor <50 */}
                        <ReferenceArea y1={90} y2={100} fill="#16a34a" fillOpacity={0.04} ifOverflow="hidden" />
                        <ReferenceArea y1={50} y2={90}  fill="#d97706" fillOpacity={0.04} ifOverflow="hidden" />
                        <ReferenceArea y1={0}  y2={50}  fill="#dc2626" fillOpacity={0.04} ifOverflow="hidden" />

                        <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" />
                        <XAxis
                            dataKey="date"
                            tick={{ fontSize: 11, fill: '#a1a1aa' }}
                            tickFormatter={fmtDate}
                            minTickGap={40}
                        />
                        <YAxis domain={[0, 100]} tick={{ fontSize: 11, fill: '#a1a1aa' }} width={32} />
                        <RechartTooltip
                            contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e4e4e7' }}
                            labelFormatter={(v) => fmtDate(String(v))}
                            formatter={(value: unknown, name: unknown) => [`${value} / 100`, name as string]}
                        />

                        {holidays.map((h) => (
                            <ReferenceLine key={`h-${h.date}`} x={h.date} stroke="#a1a1aa" strokeDasharray="4 2" strokeWidth={1} />
                        ))}
                        {workspaceEvents.map((e) => {
                            const isSingle = e.date_from === e.date_to;
                            return isSingle ? (
                                <ReferenceLine key={`e-${e.date_from}`} x={e.date_from} stroke="#3b82f6" strokeDasharray="4 2" strokeWidth={1} />
                            ) : (
                                <ReferenceArea key={`ea-${e.date_from}`} x1={e.date_from} x2={e.date_to} fill="#3b82f6" fillOpacity={0.06} />
                            );
                        })}

                        {SCORE_SERIES.filter((s) => visible.has(s.key)).map((s) => (
                            <Line
                                key={s.key}
                                type="monotone"
                                dataKey={s.key}
                                stroke={s.color}
                                strokeWidth={2}
                                dot={false}
                                connectNulls={false}
                                name={s.label}
                            />
                        ))}
                    </LineChart>
                </ResponsiveContainer>
            )}
        </div>
    );
}

// ─── CWV trend chart ──────────────────────────────────────────────────────────

type CwvMetricKey = 'lcp' | 'inp' | 'cls';

// Maps each metric to its CrUX (field) and lab data keys in HistoryPoint.
// CrUX is the primary series (real users, p75); lab is the secondary synthetic estimate.
const CWV_METRICS = [
    {
        key:        'lcp' as CwvMetricKey,
        label:      'LCP',
        unit:       'ms',
        cruxKey:    'crux_lcp_p75_ms' as keyof HistoryPoint,
        labKey:     'lcp_ms'          as keyof HistoryPoint,
        domain:     [0, 'auto'] as [number, 'auto'],
        // Threshold bands per Google CWV thresholds
        bands: [
            { y1: 0,    y2: 2500,  fill: '#16a34a' },
            { y1: 2500, y2: 4000,  fill: '#d97706' },
            { y1: 4000, y2: 12000, fill: '#dc2626' },
        ],
        fmt:  (v: number) => fmtMs(v),
        yFmt: (v: number) => v >= 1000 ? `${(v / 1000).toFixed(1)}s` : `${v}`,
    },
    {
        key:        'inp' as CwvMetricKey,
        label:      'INP',
        unit:       'ms',
        cruxKey:    'crux_inp_p75_ms' as keyof HistoryPoint,
        labKey:     'inp_ms'          as keyof HistoryPoint,
        domain:     [0, 'auto'] as [number, 'auto'],
        bands: [
            { y1: 0,   y2: 200,  fill: '#16a34a' },
            { y1: 200, y2: 500,  fill: '#d97706' },
            { y1: 500, y2: 2000, fill: '#dc2626' },
        ],
        fmt:  (v: number) => fmtMs(v),
        yFmt: (v: number) => `${v}ms`,
    },
    {
        key:        'cls' as CwvMetricKey,
        label:      'CLS',
        unit:       'score',
        cruxKey:    'crux_cls_p75' as keyof HistoryPoint,
        labKey:     'cls_score'    as keyof HistoryPoint,
        // Fixed domain — auto-scale on a healthy site (CLS ≈ 0.03) would compress the
        // axis so far that all three threshold bands sit above the visible area.
        domain:     [0, 0.5] as [number, number],
        bands: [
            { y1: 0,    y2: 0.1,  fill: '#16a34a' },
            { y1: 0.1,  y2: 0.25, fill: '#d97706' },
            { y1: 0.25, y2: 0.5,  fill: '#dc2626' },
        ],
        fmt:  (v: number) => v.toFixed(3),
        yFmt: (v: number) => v.toFixed(2),
    },
] as const;

function CwvTrendChart({
    mobileHistory,
    desktopHistory,
}: {
    mobileHistory: HistoryPoint[];
    desktopHistory: HistoryPoint[];
}) {
    const [strategy, setStrategy] = useState<ChartStrategy>('mobile');
    const [metric, setMetric]     = useState<CwvMetricKey>('lcp');
    const data = strategy === 'mobile' ? mobileHistory : desktopHistory;
    const cfg  = CWV_METRICS.find((m) => m.key === metric)!;

    // Detect whether this dataset has any CrUX field data at all.
    const hasCrux = data.some((p) => p[cfg.cruxKey] != null);
    const [showField, setShowField] = useState(true);
    const [showLab,   setShowLab]   = useState(false);

    // When metric changes, re-default: prefer field if available.
    const effectiveShowField = hasCrux && showField;
    const effectiveShowLab   = !hasCrux || showLab;   // always show lab when no CrUX

    if (mobileHistory.length === 0 && desktopHistory.length === 0) {
        return (
            <div className="flex h-48 items-center justify-center text-sm text-zinc-400">
                No history data for the selected date range.
            </div>
        );
    }

    const tooltipFormatter = (value: unknown, name: unknown) => {
        const label = name === cfg.cruxKey ? `${cfg.label} (field p75)` : `${cfg.label} (lab)`;
        return [cfg.fmt(Number(value)), label];
    };

    return (
        <div className="space-y-3">
            {/* Controls row */}
            <div className="flex flex-wrap items-center gap-3">
                {/* Strategy toggle */}
                <div className="flex rounded-lg border border-zinc-200 bg-white text-xs font-medium overflow-hidden">
                    {(['mobile', 'desktop'] as const).map((s) => (
                        <button
                            key={s}
                            onClick={() => setStrategy(s)}
                            className={cn(
                                'px-3 py-1.5 capitalize transition-colors',
                                strategy === s ? 'bg-zinc-800 text-white' : 'text-zinc-500 hover:bg-zinc-50',
                            )}
                        >
                            {s}
                        </button>
                    ))}
                </div>

                {/* Metric tabs */}
                <div className="flex gap-1.5">
                    {CWV_METRICS.map((m) => (
                        <button
                            key={m.key}
                            onClick={() => setMetric(m.key)}
                            className={cn(
                                'rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                                metric === m.key
                                    ? 'bg-zinc-800 text-white border-zinc-800'
                                    : 'border-zinc-200 bg-white text-zinc-400 hover:text-zinc-600',
                            )}
                        >
                            {m.label}
                        </button>
                    ))}
                </div>

                {/* Series toggles — Field and Lab */}
                <div className="flex gap-1.5 ml-auto">
                    {hasCrux && (
                        <button
                            onClick={() => setShowField((v) => !v)}
                            className={cn(
                                'flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                                effectiveShowField
                                    ? 'border-emerald-400 bg-emerald-500 text-white'
                                    : 'border-zinc-200 bg-white text-zinc-400 hover:text-zinc-600',
                            )}
                        >
                            <span className="h-1.5 w-1.5 rounded-full flex-shrink-0"
                                style={{ backgroundColor: effectiveShowField ? 'rgba(255,255,255,0.7)' : '#10b981' }} />
                            Field p75
                        </button>
                    )}
                    <button
                        onClick={() => setShowLab((v) => !v)}
                        className={cn(
                            'flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                            effectiveShowLab
                                ? 'border-zinc-600 bg-zinc-700 text-white'
                                : 'border-zinc-200 bg-white text-zinc-400 hover:text-zinc-600',
                        )}
                    >
                        <span className="h-1.5 w-1.5 rounded-full flex-shrink-0"
                            style={{ backgroundColor: effectiveShowLab ? 'rgba(255,255,255,0.7)' : '#71717a' }} />
                        Lab
                    </button>
                </div>
            </div>

            {data.length === 0 ? (
                <div className="flex h-36 items-center justify-center text-sm text-zinc-400">
                    No {strategy} history in this date range.
                </div>
            ) : (
                <ResponsiveContainer width="100%" height={220}>
                    <LineChart data={data} margin={{ top: 4, right: 16, bottom: 0, left: 8 }}>
                        {cfg.bands.map((b) => (
                            <ReferenceArea
                                key={`${b.y1}-${b.y2}`}
                                y1={b.y1}
                                y2={b.y2}
                                fill={b.fill}
                                fillOpacity={0.05}
                                ifOverflow="hidden"
                            />
                        ))}
                        <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" />
                        <XAxis
                            dataKey="date"
                            tick={{ fontSize: 11, fill: '#a1a1aa' }}
                            tickFormatter={fmtDate}
                            minTickGap={40}
                        />
                        <YAxis
                            domain={cfg.domain}
                            tickFormatter={cfg.yFmt}
                            tick={{ fontSize: 11, fill: '#a1a1aa' }}
                            width={48}
                        />
                        <RechartTooltip
                            contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e4e4e7' }}
                            labelFormatter={(v) => fmtDate(String(v))}
                            formatter={tooltipFormatter}
                        />
                        {effectiveShowField && hasCrux && (
                            <Line
                                type="monotone"
                                dataKey={cfg.cruxKey}
                                stroke="#10b981"
                                strokeWidth={2}
                                dot={false}
                                connectNulls={false}
                                name={cfg.cruxKey}
                            />
                        )}
                        {effectiveShowLab && (
                            <Line
                                type="monotone"
                                dataKey={cfg.labKey}
                                stroke="#71717a"
                                strokeWidth={1.5}
                                strokeDasharray="4 2"
                                dot={false}
                                connectNulls={false}
                                name={cfg.labKey}
                            />
                        )}
                    </LineChart>
                </ResponsiveContainer>
            )}
        </div>
    );
}

// ─── Score badge for table ────────────────────────────────────────────────────

function ScoreBadge({ score }: { score: number | null }) {
    if (score === null) return <span className="text-zinc-300">—</span>;
    return <span className={cn('font-semibold tabular-nums', scoreColor(scoreGrade(score)))}>{score}</span>;
}

// ─── URL selector dropdown ────────────────────────────────────────────────────

function UrlSelector({
    storeUrls,
    selectedId,
    from,
    to,
    navigate,
}: {
    storeUrls: StoreUrlItem[];
    selectedId: number | null;
    from: string;
    to: string;
    navigate: (params: Record<string, string | number | undefined>) => void;
}) {
    const [open, setOpen] = useState(false);
    const selected = storeUrls.find((u) => u.id === selectedId);

    if (storeUrls.length <= 1) return null;

    return (
        <div className="relative">
            <button
                onClick={() => setOpen((v) => !v)}
                className="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
            >
                <span className="max-w-[240px] truncate">
                    {selected?.label ?? selected?.url ?? 'Select URL'}
                </span>
                <ChevronDown className="h-4 w-4 text-zinc-400" />
            </button>
            {open && (
                <>
                    <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
                    <div className="absolute left-0 z-20 mt-1 w-80 rounded-xl border border-zinc-200 bg-white py-1 shadow-lg">
                        {storeUrls.map((u) => (
                            <button
                                key={u.id}
                                onClick={() => { setOpen(false); navigate({ url_id: u.id, from, to }); }}
                                className={cn(
                                    'flex w-full flex-col items-start gap-0.5 px-4 py-2 text-left hover:bg-zinc-50',
                                    u.id === selectedId && 'bg-zinc-50'
                                )}
                            >
                                <span className="text-sm font-medium text-zinc-800 truncate w-full">
                                    {u.label ?? u.url}
                                    {u.is_homepage && <span className="ml-1.5 text-xs text-zinc-400">(homepage)</span>}
                                </span>
                                {u.label && (
                                    <span className="text-xs text-zinc-400 truncate w-full">{u.url}</span>
                                )}
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

// ─── Alerts panel ─────────────────────────────────────────────────────────────

function AlertsPanel({ alerts }: { alerts: PerformanceAlert[] }) {
    const [open, setOpen] = useState(false);
    if (alerts.length === 0) return null;

    const criticalCount = alerts.filter((a) => a.severity === 'critical').length;

    return (
        <section className="space-y-2">
            <button
                onClick={() => setOpen((v) => !v)}
                className="flex items-center gap-2 text-sm font-semibold text-zinc-700"
            >
                <span className={cn(
                    'h-2 w-2 rounded-full',
                    criticalCount > 0 ? 'bg-red-500' : 'bg-amber-400',
                )} />
                Performance Alerts ({alerts.length})
                <ChevronDown className={cn('h-4 w-4 text-zinc-400 transition-transform', open && 'rotate-180')} />
            </button>
            {open && (
                <div className="space-y-2">
                    {alerts.map((alert, i) => (
                        <div
                            key={i}
                            className={cn(
                                'flex items-start gap-3 rounded-xl border p-4 text-sm',
                                alert.severity === 'critical'
                                    ? 'border-red-200 bg-red-50 text-red-800'
                                    : 'border-amber-200 bg-amber-50 text-amber-800',
                            )}
                        >
                            <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5" />
                            <div>
                                <span className="font-semibold">{alert.url_label}: </span>
                                {alert.message}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

// ─── Audit drill-down ─────────────────────────────────────────────────────────

function AuditDrillDown({ audits }: { audits: PerformanceAudit[] }) {
    const [open, setOpen] = useState(false);
    if (audits.length === 0) return null;

    return (
        <section className="space-y-3">
            <button
                onClick={() => setOpen((v) => !v)}
                className="section-label flex items-center gap-1.5"
            >
                Audit Opportunities
                <ChevronDown className={cn('h-4 w-4 text-zinc-400 transition-transform', open && 'rotate-180')} />
            </button>
            {open && (
                <div className="rounded-2xl border border-zinc-200 bg-white overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-zinc-100 bg-zinc-50 text-left text-xs font-medium text-zinc-500">
                                <th className="px-5 py-2.5">Audit</th>
                                <th className="px-4 py-2.5 text-center w-16">Score</th>
                                <th className="px-4 py-2.5 text-center w-16">Weight</th>
                                <th className="px-4 py-2.5 text-right">Current</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {audits.map((a) => (
                                <tr key={a.id} className="hover:bg-zinc-50">
                                    <td className="px-5 py-3">
                                        <div className="font-medium text-zinc-800">{a.title}</div>
                                        {a.description && (
                                            <div className="text-xs text-zinc-400 mt-0.5 line-clamp-2">{a.description}</div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-center">
                                        <span className={cn(
                                            'tabular-nums font-semibold',
                                            a.score !== null && a.score < 0.5  ? 'text-red-600'
                                            : a.score !== null && a.score < 0.9 ? 'text-amber-600'
                                            : 'text-zinc-400',
                                        )}>
                                            {a.score !== null ? Math.round(a.score * 100) : '—'}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-center text-xs text-zinc-400 tabular-nums">
                                        {a.weight}
                                    </td>
                                    <td className="px-4 py-3 text-right text-xs text-zinc-500 tabular-nums">
                                        {a.display_value ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function PerformancePage({
    store_urls,
    selected_url_id,
    mobile_latest,
    desktop_latest,
    mobile_history,
    desktop_history,
    mobile_score_delta,
    desktop_score_delta,
    url_summary,
    holiday_overlays,
    workspace_event_overlays,
    from,
    to,
    revenue_at_risk,
    performance_audits,
    performance_alerts,
    narrative,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency     = workspace?.reporting_currency ?? 'EUR';
    const selectedUrl  = store_urls.find((u) => u.id === selected_url_id);
    const hasAnyData   = mobile_latest !== null || desktop_latest !== null;

    function navigate(params: Record<string, string | number | undefined>) {
        router.get(wurl(workspace?.slug, '/performance'), params as Record<string, string>, { preserveState: true, replace: true });
    }

    // ── Empty state — no store connected ─────────────────────────────────────
    if (store_urls.length === 0) {
        return (
            <AppLayout>
                <Head title="Site Performance" />
                <div className="space-y-6">
                    <PageHeader title="Site Performance" />
                    <div className="rounded-2xl border border-zinc-200 bg-white p-10 text-center">
                        <div className="text-zinc-400 text-sm">
                            Connect a store to start monitoring page speed and Core Web Vitals.
                        </div>
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title="Site Performance" />
            <div className="space-y-8">
                <PageHeader
                    title="Site Performance"
                    subtitle={selectedUrl?.label ? selectedUrl.url : undefined}
                    action={
                        <UrlSelector
                            storeUrls={store_urls}
                            selectedId={selected_url_id}
                            from={from}
                            to={to}
                            navigate={navigate}
                        />
                    }
                />

                <PageNarrative text={narrative} />

                {/* Regression alerts (collapsed by default) */}
                <AlertsPanel alerts={performance_alerts} />

                {/* Revenue at risk hero card (§F19) */}
                <section className="space-y-3">
                    <h2 className="section-label">Revenue Impact</h2>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <MetricCard
                            label="Estimated Revenue at Risk"
                            value={revenue_at_risk > 0
                                ? formatCurrency(revenue_at_risk, currency)
                                : formatCurrency(0, currency)}
                            source="real"
                            tooltip="Estimate based on comparing current 7-day conversion rate vs 28-day baseline. Floor at 0."
                            subtext={revenue_at_risk === 0 ? 'No slowness-driven risk detected' : undefined}
                        />
                    </div>
                </section>

                {/* No data yet */}
                {!hasAnyData ? (
                    <div className="rounded-2xl border border-zinc-200 bg-white p-10 text-center space-y-3">
                        <div className="text-zinc-900 font-medium">Lighthouse check in progress</div>
                        <div className="text-sm text-zinc-400 max-w-sm mx-auto">
                            A PageSpeed Insights check was queued when this URL was added.
                            Results typically appear within 2–5 minutes — refresh to check.
                        </div>
                        <button
                            onClick={() => window.location.reload()}
                            className="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 transition-colors"
                        >
                            Refresh
                        </button>
                    </div>
                ) : (
                    <>
                        {/* Mobile + Desktop columns */}
                        <section className="space-y-3">
                            <h2 className="section-label">
                                Lighthouse Scores &amp; Core Web Vitals
                            </h2>
                            <div className="flex gap-6">
                                <StrategyColumn label="Mobile"  scores={mobile_latest}  delta={mobile_score_delta} />
                                <div className="w-px bg-zinc-100 self-stretch" />
                                <StrategyColumn label="Desktop" scores={desktop_latest} delta={desktop_score_delta} />
                            </div>
                        </section>

                        {/* Score trend chart */}
                        {(mobile_history.length > 0 || desktop_history.length > 0) && (
                            <section className="rounded-2xl border border-zinc-200 bg-white p-6 space-y-4">
                                <h2 className="section-label">
                                    Score Trend
                                </h2>
                                <ScoreTrendChart
                                    mobileHistory={mobile_history}
                                    desktopHistory={desktop_history}
                                    holidays={holiday_overlays}
                                    workspaceEvents={workspace_event_overlays}
                                />
                                {(holiday_overlays.length > 0 || workspace_event_overlays.length > 0) && (
                                    <div className="flex flex-wrap gap-4 pt-1 text-xs text-zinc-400">
                                        {holiday_overlays.length > 0 && (
                                            <span className="flex items-center gap-1.5">
                                                <span className="inline-block h-px w-4 border-t-2 border-dashed border-zinc-400" />
                                                Holidays
                                            </span>
                                        )}
                                        {workspace_event_overlays.length > 0 && (
                                            <span className="flex items-center gap-1.5">
                                                <span className="inline-block h-px w-4 border-t-2 border-dashed border-blue-400" />
                                                Promotions
                                            </span>
                                        )}
                                    </div>
                                )}
                            </section>
                        )}

                        {/* CWV trend chart */}
                        {(mobile_history.length > 0 || desktop_history.length > 0) && (() => {
                            const allHistory = [...mobile_history, ...desktop_history];
                            const hasCrux = allHistory.some(
                                (p) => p.crux_lcp_p75_ms != null || p.crux_inp_p75_ms != null || p.crux_cls_p75 != null
                            );
                            return (
                                <section className="rounded-2xl border border-zinc-200 bg-white p-6 space-y-4">
                                    <h2 className="section-label">Core Web Vitals Trend</h2>
                                    <CwvTrendChart
                                        mobileHistory={mobile_history}
                                        desktopHistory={desktop_history}
                                    />
                                    <p className="text-xs text-zinc-400">
                                        {hasCrux
                                            ? 'Field data (p75) from Chrome User Experience Report — real users. Lab data from PageSpeed Insights shown as reference.'
                                            : 'No real-user (CrUX) field data available for this URL yet — showing PageSpeed Insights lab estimates only.'}
                                    </p>
                                </section>
                            );
                        })()}

                        {/* Audit drill-down (collapsed by default) */}
                        <AuditDrillDown audits={performance_audits} />
                    </>
                )}

                {/* URL summary table */}
                {url_summary.length > 1 && (
                    <section className="space-y-3">
                        <h2 className="section-label">
                            All Monitored URLs
                        </h2>
                        <div className="rounded-2xl border border-zinc-200 bg-white overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                        <th className="px-5 py-3 font-medium text-zinc-500">URL</th>
                                        <th className="px-4 py-3 font-medium text-zinc-500 text-center" colSpan={3}>Mobile</th>
                                        <th className="px-4 py-3 font-medium text-zinc-500 text-center" colSpan={2}>Desktop</th>
                                        <th className="px-4 py-3 font-medium text-zinc-500 text-right">Monthly Orders</th>
                                        <th className="px-4 py-3 font-medium text-zinc-500 text-right">Revenue Risk</th>
                                        <th className="px-4 py-3 font-medium text-zinc-500 text-right">Checked</th>
                                    </tr>
                                    <tr className="border-b border-zinc-100 bg-zinc-50/50 text-left">
                                        <th />
                                        <th className="px-4 pb-2 text-xs font-normal text-zinc-400 text-center">Perf</th>
                                        <th className="px-4 pb-2 text-xs font-normal text-zinc-400 text-center">LCP</th>
                                        <th className="px-4 pb-2 text-xs font-normal text-zinc-400 text-center">INP</th>
                                        <th className="px-4 pb-2 text-xs font-normal text-zinc-400 text-center">Perf</th>
                                        <th className="px-4 pb-2 text-xs font-normal text-zinc-400 text-center">LCP</th>
                                        <th />
                                        <th />
                                        <th />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-zinc-100">
                                    {url_summary.map((row) => (
                                        <tr
                                            key={row.id}
                                            className={cn('hover:bg-zinc-50 cursor-pointer', row.id === selected_url_id && 'bg-zinc-50')}
                                            onClick={() => navigate({ url_id: row.id, from, to })}
                                        >
                                            <td className="px-5 py-3 max-w-[240px]">
                                                <div className="font-medium text-zinc-800 truncate">
                                                    {row.label ?? row.url}
                                                    {row.is_homepage && <span className="ml-1.5 text-xs text-zinc-400">homepage</span>}
                                                </div>
                                                {row.label && (
                                                    <div className="text-xs text-zinc-400 truncate">{row.url}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-center"><ScoreBadge score={row.mobile_performance_score} /></td>
                                            <td className="px-4 py-3 text-center text-zinc-600 tabular-nums text-xs">{fmtMs(row.mobile_lcp_ms)}</td>
                                            <td className="px-4 py-3 text-center">
                                                <CwvBand metric="inp" value={row.mobile_inp_ms} />
                                            </td>
                                            <td className="px-4 py-3 text-center"><ScoreBadge score={row.desktop_performance_score} /></td>
                                            <td className="px-4 py-3 text-center text-zinc-600 tabular-nums text-xs">{fmtMs(row.desktop_lcp_ms)}</td>
                                            <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                                {row.monthly_orders > 0 ? row.monthly_orders : '—'}
                                            </td>
                                            <td className={cn(
                                                'px-4 py-3 text-right tabular-nums text-sm font-medium',
                                                row.revenue_risk > 0 ? 'text-amber-600' : 'text-zinc-300',
                                            )}>
                                                {row.revenue_risk > 0
                                                    ? formatCurrency(row.revenue_risk, currency)
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-right text-zinc-400 text-xs">
                                                {row.last_checked_at ? fmtDate(row.last_checked_at) : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
