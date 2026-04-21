import axios from 'axios';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level lets us start with
// navigating=true so the skeleton stays visible until the real data is ready.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import {
    AlertTriangle,
    Bot,
    ChevronDown,
    Gauge,
    Lightbulb,
    NotebookPen,
    ShoppingBag,
    TrendingUp,
    TriangleAlert,
    X,
    Zap,
    CheckCircle2,
} from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { MetricCard } from '@/Components/shared/MetricCard';
import { StoreFilter } from '@/Components/shared/StoreFilter';
import { UtmCoverageNudgeModal } from '@/Components/shared/UtmCoverageNudgeModal';
import { MultiSeriesLineChart } from '@/Components/charts/MultiSeriesLineChart';
import type { MultiSeriesPoint, HolidayOverlay, WorkspaceEventOverlay } from '@/Components/charts/MultiSeriesLineChart';
import { formatCurrency, formatNumber, type Granularity } from '@/lib/formatters';
import type { PageProps } from '@/types';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';

// ─── Types ────────────────────────────────────────────────────────────────────

interface DashboardMetrics {
    // Store row
    revenue: number;
    orders: number;
    aov: number | null;
    new_customers: number | null;
    // Paid row
    ad_spend: number | null;
    roas: number | null;              // Real ROAS: revenue / ad_spend
    attributed_revenue: number | null;
    cpo: number | null;               // Real CPO: ad_spend / orders
    // Not Tracked (signed — negative = iOS14 inflation)
    not_tracked_revenue: number;
    not_tracked_pct: number | null;
    // Derived
    items_per_order: number | null;
    marketing_spend_pct: number | null;
}

interface AdvancedPaidMetrics {
    impressions: number;
    clicks: number;
    platform_conversions: number;
    cpm: number | null;
    cpc: number | null;
    platform_conversion_rate: number | null;
}

interface GscMetrics {
    gsc_clicks: number;
    gsc_impressions: number;
    avg_position: number | null;
}

interface TopAlert {
    type: string;
    severity: 'info' | 'warning' | 'critical';
    created_at: string;
}

interface AiSummaryData {
    summary_text: string;
    generated_at: string;
}

interface NotePoint {
    date: string;
    note: string;
}

interface PsiMetrics {
    performance_score: number | null;
    lcp_ms: number | null;
    cls_score: number | null;
    checked_at: string | null;
}

interface WorkspaceTargets {
    roas: number | null;
    cpo: number | null;
    marketing_pct: number | null;
}

interface UnrecognizedSource {
    source: string;
    order_count: number;
    revenue_pct: number;
}

interface UtmCoverage {
    pct: number | null;
    status: 'green' | 'amber' | 'red' | null;
    checked_at: string | null;
    unrecognized_sources: UnrecognizedSource[];
}

// Phase 1.4: 14-day trend dots per Real row metric
interface TrendDots {
    roas: Array<boolean | null>;
    cpo: Array<boolean | null>;
    marketing_pct: Array<boolean | null>;
}

// Phase 1.4: "Last 7 days vs prior 7 days" delta widget
interface DailyAvgDelta {
    last7_avg_revenue: number | null;
    prev7_avg_revenue: number | null;
    revenue_delta_pct: number | null;
    last7_avg_orders: number | null;
    prev7_avg_orders: number | null;
    orders_delta_pct: number | null;
}

// Phase 1.4: Latest orders feed
interface RecentOrder {
    id: number;
    order_number: string;
    status: string;
    total: number;
    currency: string;
    occurred_at: string;
}

interface RecentOrders {
    orders: RecentOrder[];
    feed_source: 'webhook' | 'polling';
    last_synced_at: string | null;
}

interface Props {
    psi_metrics: PsiMetrics | null;
    metrics: DashboardMetrics;
    compare_metrics: DashboardMetrics | null;
    gsc_metrics: GscMetrics | null;
    compare_gsc_metrics: GscMetrics | null;
    advanced_paid_metrics: AdvancedPaidMetrics | null;
    compare_advanced_paid_metrics: AdvancedPaidMetrics | null;
    targets: WorkspaceTargets;
    utm_coverage: UtmCoverage | null;
    not_tracked_banner_dismissed: boolean;
    chart_data: MultiSeriesPoint[];
    compare_chart_data: MultiSeriesPoint[] | null;
    top_alert: TopAlert | null;
    days_of_data: number;
    ai_summary: AiSummaryData | null;
    has_null_fx: boolean;
    granularity: Granularity;
    store_ids: number[];
    notes: NotePoint[];
    holidays: HolidayOverlay[];
    workspace_events: WorkspaceEventOverlay[];
    // Phase 1.4 additions
    trend_dots: TrendDots;
    daily_avg_delta: DailyAvgDelta | null;
    recent_orders: RecentOrders | null;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Returns percentage change, null when previous is zero/null or either value is null. */
function pctChange(current: number | null, previous: number | null): number | null {
    if (current === null || previous === null || previous === 0) return null;
    return ((current - previous) / previous) * 100;
}

// ─── Prominent note input ─────────────────────────────────────────────────────

/** Autosaving textarea for a single day's note. Saves on blur or Enter. */
function NoteInput({ date, initialNote }: { date: string; initialNote: string | null }) {
    const [value, setValue] = useState(initialNote ?? '');
    const [saving, setSaving] = useState(false);
    const [savedFlash, setSavedFlash] = useState(false);
    const lastSavedRef  = useRef(initialNote ?? '');
    const focusedRef    = useRef(false);
    const flashTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (!focusedRef.current) {
            const v = initialNote ?? '';
            setValue(v);
            lastSavedRef.current = v;
        }
    }, [initialNote]);

    useEffect(() => () => { if (flashTimerRef.current) clearTimeout(flashTimerRef.current); }, []);

    function save(current: string): void {
        if (current === lastSavedRef.current) return;
        setSaving(true);
        axios
            .post(`/analytics/notes/${date}`, { note: current })
            .then(() => {
                lastSavedRef.current = current;
                setSavedFlash(true);
                if (flashTimerRef.current) clearTimeout(flashTimerRef.current);
                flashTimerRef.current = setTimeout(() => setSavedFlash(false), 2000);
            })
            .catch(() => setValue(lastSavedRef.current))
            .finally(() => setSaving(false));
    }

    function handleBlur(e: React.FocusEvent<HTMLTextAreaElement>): void {
        focusedRef.current = false;
        save(e.currentTarget.value);
    }

    function handleKeyDown(e: React.KeyboardEvent<HTMLTextAreaElement>): void {
        if (e.key === 'Escape') {
            setValue(lastSavedRef.current);
            e.currentTarget.blur();
        }
    }

    const today = new Date().toISOString().slice(0, 10);
    const label = date === today ? "Today's note" : (() => {
        const d = new Date(date);
        const weekday = d.toLocaleDateString('en-GB', { weekday: 'short' });
        const day     = d.getDate();
        const month   = d.getMonth() + 1;
        return `Note for ${weekday} ${day}.${month}`;
    })();

    return (
        <div className="relative">
            <div className="mb-1.5 flex items-center justify-between">
                <label className="flex items-center gap-1.5 text-xs font-medium text-zinc-500">
                    <NotebookPen className="h-3.5 w-3.5" />
                    {label}
                </label>
                {saving && <span className="text-[10px] text-zinc-400">saving…</span>}
                {!saving && savedFlash && <span className="text-[10px] text-green-500">saved</span>}
            </div>
            <textarea
                value={value}
                onChange={(e) => setValue(e.target.value)}
                onFocus={() => { focusedRef.current = true; }}
                onBlur={handleBlur}
                onKeyDown={handleKeyDown}
                placeholder="Add a note about today — promotions, outages, ad changes…"
                maxLength={1000}
                rows={2}
                className="w-full resize-none rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-700 outline-none transition-colors placeholder:text-zinc-300 focus:border-primary/40 focus:bg-white focus:shadow-sm"
            />
        </div>
    );
}

// ─── Channel section ──────────────────────────────────────────────────────────

/**
 * Collapsible channel row with optional "Show advanced" sub-toggle.
 */
// ─── UTM Coverage Badge ───────────────────────────────────────────────────────

/**
 * Persistent green/amber/red dot shown near attribution metrics.
 * Hover reveals exact coverage % and a fix link when amber/red.
 * See: PLANNING.md "UTM Coverage Health Check + Tag Generator"
 */
function UtmCoverageBadge({ coverage }: { coverage: UtmCoverage }) {
    const { workspace } = usePage<PageProps>().props;
    const [showTip, setShowTip] = useState(false);

    if (!coverage.status) return null;

    const colors: Record<string, string> = {
        green: 'bg-emerald-400',
        amber: 'bg-amber-400',
        red:   'bg-red-400',
    };
    const labels: Record<string, string> = {
        green: 'UTM tracking: good',
        amber: 'UTM tracking: partial',
        red:   'UTM tracking: low',
    };
    const messages: Record<string, string> = {
        green: 'Great — most paid traffic is tracked via UTM parameters.',
        amber: 'Some paid traffic isn\'t tracked. Use the Tag Generator to fix your ad URLs.',
        red:   'Most paid traffic isn\'t tracked. Attribution numbers may be unreliable.',
    };

    const dotColor = colors[coverage.status] ?? 'bg-zinc-400';
    const pctText  = coverage.pct != null ? ` (${coverage.pct.toFixed(0)}%)` : '';

    return (
        <div className="relative">
            <button
                type="button"
                onMouseEnter={() => setShowTip(true)}
                onMouseLeave={() => setShowTip(false)}
                onFocus={() => setShowTip(true)}
                onBlur={() => setShowTip(false)}
                className="flex items-center gap-1 rounded px-1.5 py-0.5 text-xs text-zinc-500 hover:bg-zinc-100 transition-colors"
                aria-label={labels[coverage.status]}
            >
                <span className={cn('h-2 w-2 rounded-full', dotColor)} />
                UTM{pctText}
            </button>

            {showTip && (
                <div className="absolute right-0 top-full z-30 mt-1 w-64 rounded-lg border border-zinc-200 bg-white p-3 shadow-lg">
                    <p className="text-xs text-zinc-600">{messages[coverage.status]}</p>
                    {coverage.status !== 'green' && (
                        <Link
                            href={wurl(workspace?.slug, '/manage/tag-generator')}
                            className="mt-1.5 block text-xs font-medium text-primary hover:underline"
                        >
                            Open Tag Generator →
                        </Link>
                    )}
                </div>
            )}
        </div>
    );
}

// ─── Unrecognized Sources Banner ──────────────────────────────────────────────

/**
 * Inline callout when orders contain utm_source values not matching Facebook or Google aliases.
 * Shown inside Paid Ads section. Points user to Tag Generator to fix.
 * See: PLANNING.md "UTM Coverage Health Check + Tag Generator" — unrecognized sources
 */
function UnrecognizedSourcesBanner({ sources, workspaceSlug }: { sources: UnrecognizedSource[]; workspaceSlug: string | undefined }) {
    const [dismissed, setDismissed] = useState(false);
    const [expanded, setExpanded]   = useState(false);
    if (dismissed || sources.length === 0) return null;

    const examples = sources.slice(0, 3).map((s) => `'${s.source}'`).join(', ');

    return (
        <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
            <div className="flex items-start gap-2.5">
                <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />
                <div className="flex-1">
                    We found unrecognized utm_source values ({examples}
                    {sources.length > 3 ? ` and ${sources.length - 3} more` : ''}) that don't
                    match any known channel and aren't counted in paid attribution.{' '}
                    {sources.length > 3 && (
                        <button
                            onClick={() => setExpanded(!expanded)}
                            className="font-medium underline hover:no-underline"
                        >
                            {expanded ? 'Hide full list' : 'Show all'}
                        </button>
                    )}
                    {sources.length > 3 && ' · '}
                    <Link href={wurl(workspaceSlug, '/manage/tag-generator')} className="font-medium underline hover:no-underline">
                        Fix with Tag Generator →
                    </Link>
                </div>
                <button
                    onClick={() => setDismissed(true)}
                    className="shrink-0 text-amber-400 hover:text-amber-600"
                    aria-label="Dismiss"
                >
                    <X className="h-3.5 w-3.5" />
                </button>
            </div>
            {expanded && (
                <div className="ml-6 mt-2 space-y-1">
                    {sources.map((s) => (
                        <div key={s.source} className="flex items-center gap-2 tabular-nums">
                            <code className="rounded bg-amber-100 px-1 py-0.5 text-[11px]">{s.source}</code>
                            <span className="text-amber-600">
                                {s.order_count} order{s.order_count !== 1 ? 's' : ''} · {s.revenue_pct}% of tagged revenue
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

// ─── Channel Section ──────────────────────────────────────────────────────────

function ChannelSection({
    title,
    icon: Icon,
    color,
    defaultOpen = true,
    children,
    advancedChildren,
    headerBadge,
    footer,
    connectHref,
    connectMessage,
}: {
    title: string;
    icon: React.ComponentType<{ className?: string }>;
    color: string;
    defaultOpen?: boolean;
    children?: React.ReactNode;
    /** When provided, renders a "Show advanced metrics" toggle below main metrics */
    advancedChildren?: React.ReactNode;
    /** Optional badge/indicator rendered inline in the section header */
    headerBadge?: React.ReactNode;
    /** Content rendered below the metrics grid (always visible when section is open) */
    footer?: React.ReactNode;
    connectHref?: string;
    connectMessage?: string;
}) {
    const [open, setOpen] = useState(defaultOpen);
    const [showAdvanced, setShowAdvanced] = useState(false);

    if (connectHref) {
        return (
            <div className="rounded-xl border border-dashed border-zinc-200 bg-zinc-50 px-4 py-3">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2 text-sm text-zinc-400">
                        <Icon className="h-4 w-4" />
                        <span>{title}</span>
                    </div>
                    <Link
                        href={connectHref}
                        className="text-xs font-medium text-primary hover:text-primary/80"
                    >
                        {connectMessage ?? 'Connect →'}
                    </Link>
                </div>
            </div>
        );
    }

    return (
        <div className="rounded-xl border border-zinc-200 bg-white">
            {/* Header */}
            <div className="flex items-center gap-2 px-4 py-3">
                <button
                    onClick={() => setOpen((v) => !v)}
                    className="flex flex-1 items-center gap-2 text-left"
                >
                    <div className={cn('flex h-6 w-6 shrink-0 items-center justify-center rounded-md', color)}>
                        <Icon className="h-3.5 w-3.5 text-white" />
                    </div>
                    <span className="flex-1 text-sm font-medium text-zinc-700">{title}</span>
                    <ChevronDown
                        className={cn(
                            'h-4 w-4 shrink-0 text-zinc-300 transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                </button>
                {headerBadge && <div onClick={(e) => e.stopPropagation()}>{headerBadge}</div>}
            </div>

            {/* Metric grid */}
            {open && (
                <div className="border-t border-zinc-100 p-4 pt-3">
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {children}
                    </div>

                    {/* Advanced metrics toggle */}
                    {advancedChildren && (
                        <>
                            <button
                                onClick={() => setShowAdvanced((v) => !v)}
                                className="mt-3 flex items-center gap-1 text-xs text-zinc-400 hover:text-zinc-600 transition-colors"
                            >
                                <ChevronDown
                                    className={cn(
                                        'h-3 w-3 transition-transform',
                                        showAdvanced && 'rotate-180',
                                    )}
                                />
                                {showAdvanced ? 'Hide advanced metrics' : 'Show advanced metrics'}
                            </button>

                            {showAdvanced && (
                                <div className="mt-3 grid grid-cols-2 gap-3 border-t border-zinc-100 pt-3 sm:grid-cols-4">
                                    {advancedChildren}
                                </div>
                            )}
                        </>
                    )}

                    {footer && <div>{footer}</div>}
                </div>
            )}
        </div>
    );
}

// ─── Attention card ───────────────────────────────────────────────────────────

function AttentionCard({ alert, daysOfData }: { alert: TopAlert | null; daysOfData: number }) {
    const { workspace } = usePage<PageProps>().props;
    const BASELINE_DAYS = 28;
    const learningComplete = daysOfData >= BASELINE_DAYS;

    if (alert) {
        const isCritical = alert.severity === 'critical';
        return (
            <div className={cn(
                'rounded-xl border p-5 space-y-1',
                isCritical
                    ? 'border-red-200 bg-red-50'
                    : 'border-amber-200 bg-amber-50',
            )}>
                <div className="flex items-center justify-between">
                    <span className={cn(
                        'flex items-center gap-1.5 text-sm font-medium',
                        isCritical ? 'text-red-500' : 'text-amber-500',
                    )}>
                        Attention needed
                    </span>
                    <TriangleAlert className={cn('h-4 w-4', isCritical ? 'text-red-400' : 'text-amber-400')} />
                </div>
                <div className={cn(
                    'text-2xl font-semibold tabular-nums capitalize',
                    isCritical ? 'text-red-700' : 'text-amber-700',
                )}>
                    {alert.severity}
                </div>
                <div className="min-h-[20px]">
                    <Link href={wurl(workspace?.slug, '/insights')} className={cn(
                        'text-xs hover:underline',
                        isCritical ? 'text-red-500' : 'text-amber-500',
                    )}>
                        {alert.type.replace(/_/g, ' ')} → View in Insights
                    </Link>
                </div>
            </div>
        );
    }

    if (learningComplete) {
        return (
            <div className="rounded-xl border border-zinc-200 bg-white p-5 space-y-1">
                <div className="flex items-center justify-between">
                    <span className="flex items-center gap-1.5 text-sm font-medium text-zinc-500">
                        All clear
                    </span>
                    <CheckCircle2 className="h-4 w-4 text-zinc-300" />
                </div>
                <div className="text-2xl font-semibold text-zinc-700">No alerts</div>
                <div className="min-h-[20px]">
                    <span className="text-xs text-zinc-400">Anomaly detection active</span>
                </div>
            </div>
        );
    }

    // Day-1 state: show learning progress (most users see this on initial setup)
    const pct = Math.min(100, Math.round((daysOfData / BASELINE_DAYS) * 100));
    return (
        <div className="rounded-xl border border-zinc-200 bg-white p-5 space-y-1">
            <div className="flex items-center justify-between">
                <span className="flex items-center gap-1.5 text-sm font-medium text-zinc-400">
                    Intelligence
                </span>
                <Zap className="h-4 w-4 text-zinc-300" />
            </div>
            <div className="text-lg font-semibold text-zinc-700">
                Learning&hellip;
            </div>
            <div className="min-h-[20px] space-y-1.5 pt-1">
                <div className="h-1.5 w-full overflow-hidden rounded-full bg-zinc-100">
                    <div
                        className="h-full rounded-full bg-primary/60 transition-all"
                        style={{ width: `${pct}%` }}
                    />
                </div>
                <span className="text-xs text-zinc-400">
                    {daysOfData}/{BASELINE_DAYS} days to anomaly detection
                </span>
            </div>
        </div>
    );
}

// ─── iOS14 / Negative Not Tracked banner ─────────────────────────────────────

/**
 * Dismissible banner shown when Not Tracked % < -5%.
 * Indicates iOS14 attribution inflation — platforms claimed more than store received.
 * Fires once per workspace; stored in user view_preferences.
 * See: PLANNING.md "Not Tracked" sign-aware section
 */
function NotTrackedInflationBanner({ onDismiss }: { onDismiss: () => void }) {
    const { workspace } = usePage<PageProps>().props;
    return (
        <div className="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
            <div className="flex-1 text-sm text-amber-800">
                <span className="font-semibold">Ad platforms are claiming more revenue than your store received.</span>{' '}
                This usually indicates iOS14+ modeled conversions — Facebook or Google are attributing orders
                that your store didn't record.{' '}
                <Link href={wurl(workspace?.slug, '/help/data-accuracy#roas')} className="underline hover:no-underline">
                    Learn more about attribution overlap →
                </Link>
            </div>
            <button
                onClick={onDismiss}
                aria-label="Dismiss"
                className="shrink-0 text-amber-400 hover:text-amber-600"
            >
                <X className="h-4 w-4" />
            </button>
        </div>
    );
}

// ─── Period comparison delta table ────────────────────────────────────────────

/**
 * Shows per-channel revenue/spend deltas between current and comparison periods.
 * Helps the user understand which channel drove a revenue change.
 * See: PLANNING.md Phase 1.2 "Period comparison: per-channel delta table"
 */
function DeltaCell({ value, pctValue, currency: cur, invert = false, isCount = false }: {
    value: number | null;
    pctValue: number | null;
    currency?: string;
    invert?: boolean;
    isCount?: boolean;
}) {
    if (value === null) return <td className="px-3 py-2.5 text-sm text-zinc-300">—</td>;
    const positive = value >= 0;
    const good = invert ? !positive : positive;
    return (
        <td className="px-3 py-2.5 text-sm tabular-nums">
            <span className={good ? 'text-green-600' : 'text-red-500'}>
                {positive ? '+' : ''}
                {isCount
                    ? formatNumber(Math.round(value))
                    : cur
                    ? formatCurrency(value, cur)
                    : `${value.toFixed(1)}%`}
            </span>
            {pctValue !== null && (
                <span className="ml-1 text-xs text-zinc-400">
                    ({positive ? '+' : ''}{pctValue.toFixed(1)}%)
                </span>
            )}
        </td>
    );
}

function PeriodComparisonTable({
    metrics,
    compareMetrics,
    gscMetrics,
    compareGscMetrics,
    currency,
}: {
    metrics: DashboardMetrics;
    compareMetrics: DashboardMetrics;
    gscMetrics: GscMetrics | null;
    compareGscMetrics: GscMetrics | null;
    currency: string;
}) {
    function delta(curr: number | null, prev: number | null): number | null {
        if (curr === null || prev === null) return null;
        return curr - prev;
    }
    function pct(curr: number | null, prev: number | null): number | null {
        if (curr === null || prev === null || prev === 0) return null;
        return ((curr - prev) / prev) * 100;
    }

    const revenueDelta  = delta(metrics.revenue, compareMetrics.revenue);
    const adSpendDelta  = delta(metrics.ad_spend, compareMetrics.ad_spend);
    const clicksDelta   = delta(gscMetrics?.gsc_clicks ?? null, compareGscMetrics?.gsc_clicks ?? null);
    const notTrackedDelta = delta(metrics.not_tracked_revenue, compareMetrics.not_tracked_revenue);

    return (
        <div className="mt-4 overflow-hidden rounded-xl border border-zinc-200 bg-white">
            <div className="border-b border-zinc-100 px-4 py-3">
                <span className="text-sm font-medium text-zinc-500">Period-over-period change by channel</span>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full">
                    <thead>
                        <tr className="border-b border-zinc-100 text-xs text-zinc-400">
                            <th className="px-3 py-2 text-left font-medium">Channel</th>
                            <th className="px-3 py-2 text-left font-medium">Metric</th>
                            <th className="px-3 py-2 text-left font-medium">Change</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-zinc-50">
                        <tr>
                            <td className="px-3 py-2.5 text-sm font-medium text-zinc-600">Store</td>
                            <td className="px-3 py-2.5 text-sm text-zinc-500">Revenue</td>
                            <DeltaCell value={revenueDelta} pctValue={pct(metrics.revenue, compareMetrics.revenue)} currency={currency} />
                        </tr>
                        <tr>
                            <td className="px-3 py-2.5 text-sm font-medium text-zinc-600"></td>
                            <td className="px-3 py-2.5 text-sm text-zinc-500">Orders</td>
                            <DeltaCell value={delta(metrics.orders, compareMetrics.orders)} pctValue={pct(metrics.orders, compareMetrics.orders)} isCount />
                        </tr>
                        <tr>
                            <td className="px-3 py-2.5 text-sm font-medium text-zinc-600">Paid Ads</td>
                            <td className="px-3 py-2.5 text-sm text-zinc-500">Ad Spend</td>
                            <DeltaCell value={adSpendDelta} pctValue={pct(metrics.ad_spend, compareMetrics.ad_spend)} currency={currency} invert />
                        </tr>
                        <tr>
                            <td className="px-3 py-2.5 text-sm font-medium text-zinc-600"></td>
                            <td className="px-3 py-2.5 text-sm text-zinc-500">Attributed Revenue</td>
                            <DeltaCell value={delta(metrics.attributed_revenue, compareMetrics.attributed_revenue)} pctValue={pct(metrics.attributed_revenue, compareMetrics.attributed_revenue)} currency={currency} />
                        </tr>
                        {gscMetrics !== null && compareGscMetrics !== null && (
                            <tr>
                                <td className="px-3 py-2.5 text-sm font-medium text-zinc-600">Organic</td>
                                <td className="px-3 py-2.5 text-sm text-zinc-500">GSC Clicks</td>
                                <DeltaCell value={clicksDelta} pctValue={pct(gscMetrics.gsc_clicks, compareGscMetrics.gsc_clicks)} isCount />
                            </tr>
                        )}
                        <tr>
                            <td className="px-3 py-2.5 text-sm font-medium text-zinc-600">Not Tracked</td>
                            <td className="px-3 py-2.5 text-sm text-zinc-500">Revenue</td>
                            <DeltaCell value={notTrackedDelta} pctValue={pct(metrics.not_tracked_revenue, compareMetrics.not_tracked_revenue)} currency={currency} />
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    );
}

// ─── Latest orders feed (Phase 1.4) ──────────────────────────────────────────

/**
 * Shows the 10 most recent orders with recency labels.
 * Webhook-gated: stores without active webhooks see a nudge to enable them.
 * Header shows "Live via webhook" (green dot) or "Synced X min ago" (amber dot).
 *
 * Why: only shown for webhook stores to avoid presenting stale data as live.
 * Honest labeling is the trust mechanism.
 * See: PLANNING.md "Latest orders feed" (Phase 1.4 widget)
 */
function LatestOrdersFeed({ feed, currency, workspaceSlug }: { feed: RecentOrders; currency: string; workspaceSlug: string | undefined }) {
    function relativeTime(iso: string): string {
        const diff  = Date.now() - new Date(iso).getTime();
        const mins  = Math.floor(diff / 60_000);
        const hours = Math.floor(mins / 60);
        const days  = Math.floor(hours / 24);
        if (mins < 1)   return 'just now';
        if (mins < 60)  return `${mins}m ago`;
        if (hours < 24) return `${hours}h ago`;
        return `${days}d ago`;
    }

    // Polling-only store: show nudge to enable webhooks
    if (feed.feed_source === 'polling') {
        const lastSync = feed.last_synced_at ? relativeTime(feed.last_synced_at) : null;
        return (
            <div className="mt-3 rounded-lg border border-zinc-100 bg-zinc-50 px-4 py-3">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-1.5 text-xs font-medium text-zinc-500">
                        <span className="h-1.5 w-1.5 rounded-full bg-amber-400" />
                        {lastSync ? `Synced ${lastSync}` : 'Polling mode'}
                    </div>
                    <Link
                        href="/settings/integrations"
                        className="text-xs font-medium text-primary hover:text-primary/80"
                    >
                        Enable webhooks for live orders →
                    </Link>
                </div>
            </div>
        );
    }

    if (feed.orders.length === 0) return null;

    return (
        <div className="mt-3 rounded-lg border border-zinc-100 bg-zinc-50 overflow-hidden">
            <div className="flex items-center justify-between border-b border-zinc-100 px-4 py-2">
                <div className="flex items-center gap-1.5 text-xs font-medium text-zinc-500">
                    <span className="h-1.5 w-1.5 rounded-full bg-green-400 animate-pulse" />
                    Live via webhook
                </div>
                <span className="text-[10px] text-zinc-400">{feed.orders.length} recent orders</span>
            </div>
            <div className="divide-y divide-zinc-100">
                {feed.orders.slice(0, 5).map(order => (
                    <Link
                        key={order.id}
                        href={wurl(workspaceSlug, `/orders/${order.id}`)}
                        className="flex items-center justify-between px-4 py-2 transition-colors hover:bg-white"
                    >
                        <div className="flex items-center gap-2 min-w-0">
                            <span className="text-xs font-medium text-zinc-700 shrink-0">
                                #{order.order_number}
                            </span>
                            <span className={cn(
                                'rounded-full px-1.5 py-0.5 text-[10px] font-medium capitalize',
                                order.status === 'completed' ? 'bg-green-50 text-green-700' : 'bg-zinc-100 text-zinc-500',
                            )}>
                                {order.status}
                            </span>
                        </div>
                        <div className="flex items-center gap-3">
                            <span className="text-xs font-semibold tabular-nums text-zinc-900">
                                {formatCurrency(order.total, currency)}
                            </span>
                            <span className="text-[10px] text-zinc-400 shrink-0">
                                {relativeTime(order.occurred_at)}
                            </span>
                        </div>
                    </Link>
                ))}
            </div>
        </div>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

function fmtMs(ms: number | null): string | null {
    if (ms === null) return null;
    if (ms >= 1000) return `${(ms / 1000).toFixed(2)} s`;
    return `${ms} ms`;
}

function fmtCls(cls: number | null): string | null {
    if (cls === null) return null;
    return cls.toFixed(3);
}

export default function Dashboard({
    psi_metrics,
    metrics,
    compare_metrics,
    gsc_metrics,
    compare_gsc_metrics,
    advanced_paid_metrics,
    compare_advanced_paid_metrics,
    targets,
    utm_coverage,
    not_tracked_banner_dismissed,
    chart_data,
    compare_chart_data,
    top_alert,
    days_of_data,
    ai_summary,
    has_null_fx,
    granularity,
    store_ids,
    notes,
    holidays,
    workspace_events,
    trend_dots,
    daily_avg_delta,
    recent_orders,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const timezone = workspace?.reporting_timezone;

    const hasStore = workspace?.has_store ?? false;
    const hasAds   = workspace?.has_ads   ?? false;
    const hasGsc   = workspace?.has_gsc   ?? false;
    const hasPsi   = workspace?.has_psi   ?? false;

    const [navigating, setNavigating] = useState(() => _inertiaNavigating);
    const [showNotes, setShowNotes]   = useState(true);

    // iOS14 Not Tracked inflation banner — dismiss stored server-side per-workspace.
    // Threshold: negative not_tracked_pct exceeding -5%.
    const notTrackedIsNegative = (metrics.not_tracked_pct ?? 0) < -5;
    const [bannerDismissed, setBannerDismissed] = useState(not_tracked_banner_dismissed);
    const showInflationBanner = hasStore && hasAds && notTrackedIsNegative && !bannerDismissed;

    useEffect(() => {
        const removeStart  = router.on('start',  () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));
        return () => { removeStart(); removeFinish(); };
    }, []);

    function handleDismissBanner(): void {
        setBannerDismissed(true);
        axios.post(wurl(workspace?.slug, '/dashboard/dismiss-not-tracked-banner')).catch(() => {
            // Non-critical — banner stays dismissed locally even if request fails
        });
    }

    // Comparison deltas
    const storeDelta = useMemo(() => ({
        revenue:      pctChange(metrics.revenue,      compare_metrics?.revenue      ?? null),
        orders:       pctChange(metrics.orders,       compare_metrics?.orders       ?? null),
        aov:          pctChange(metrics.aov,          compare_metrics?.aov          ?? null),
        new_customers: pctChange(metrics.new_customers ?? null, compare_metrics?.new_customers ?? null),
    }), [metrics, compare_metrics]);

    const paidDelta = useMemo(() => ({
        ad_spend:           pctChange(metrics.ad_spend,           compare_metrics?.ad_spend           ?? null),
        roas:               pctChange(metrics.roas,               compare_metrics?.roas               ?? null),
        attributed_revenue: pctChange(metrics.attributed_revenue, compare_metrics?.attributed_revenue ?? null),
        cpo:                pctChange(metrics.cpo,                compare_metrics?.cpo                ?? null),
        cpm:                pctChange(advanced_paid_metrics?.cpm       ?? null, compare_advanced_paid_metrics?.cpm       ?? null),
        cpc:                pctChange(advanced_paid_metrics?.cpc       ?? null, compare_advanced_paid_metrics?.cpc       ?? null),
        platform_conversion_rate: pctChange(
            advanced_paid_metrics?.platform_conversion_rate ?? null,
            compare_advanced_paid_metrics?.platform_conversion_rate ?? null,
        ),
    }), [metrics, compare_metrics, advanced_paid_metrics, compare_advanced_paid_metrics]);

    const organicDelta = useMemo(() => ({
        gsc_clicks:       pctChange(gsc_metrics?.gsc_clicks      ?? null, compare_gsc_metrics?.gsc_clicks      ?? null),
        gsc_impressions:  pctChange(gsc_metrics?.gsc_impressions  ?? null, compare_gsc_metrics?.gsc_impressions  ?? null),
        avg_position:     pctChange(gsc_metrics?.avg_position     ?? null, compare_gsc_metrics?.avg_position     ?? null),
        not_tracked_revenue: pctChange(metrics.not_tracked_revenue, compare_metrics?.not_tracked_revenue ?? null),
    }), [metrics, compare_metrics, gsc_metrics, compare_gsc_metrics]);

    // Not Tracked display — signed value
    const notTrackedAbs = metrics.not_tracked_revenue;
    const notTrackedPct = metrics.not_tracked_pct;
    const notTrackedIsNeg = notTrackedAbs < 0;

    const notTrackedTooltip = notTrackedIsNeg
        ? 'Ad platforms reported more conversion value than your store received. This usually indicates attribution inflation from iOS14+ modeled conversions.'
        : 'Revenue not tracked by any ad platform. Includes organic search, direct, email campaigns, affiliates, and any untagged traffic. To see email revenue separately, add utm_medium=email to your email campaigns.';

    // Real row is only meaningful when both store and ads are connected.
    const showRealRow = hasStore && hasAds;

    // ── Action language (PLANNING §12 principle 7) ─────────────────────────
    // Each string gives a one-line interpretation of the metric's current state.
    // Copy is dynamic: target hit/miss, delta sign, and anomaly state all feed in.

    const heroRevenueAction = useMemo<string | undefined>(() => {
        const d = storeDelta.revenue;
        if (d === null) return undefined;
        if (d > 5)  return `Up ${d.toFixed(1)}% vs prior period`;
        if (d < -5) return `Down ${Math.abs(d).toFixed(1)}% vs prior period`;
        return 'Flat vs prior period';
    }, [storeDelta.revenue]);

    const heroOrdersAction = useMemo<string | undefined>(() => {
        const d = storeDelta.orders;
        if (d === null) return undefined;
        if (d > 5)  return `Up ${d.toFixed(1)}% vs prior period`;
        if (d < -5) return `Down ${Math.abs(d).toFixed(1)}% vs prior period`;
        return 'Flat vs prior period';
    }, [storeDelta.orders]);

    const realRoasAction = useMemo<{ line: string; href?: string } | undefined>(() => {
        if (metrics.roas === null) return undefined;
        const roasStr = `${metrics.roas.toFixed(2)}×`;
        if (targets.roas !== null) {
            const isAbove = metrics.roas >= targets.roas;
            return isAbove
                ? { line: `Holding at ${roasStr} — above target` }
                : { line: `At ${roasStr} — below target`, href: wurl(workspace?.slug, '/campaigns') };
        }
        return { line: `${roasStr} blended ROAS`, href: wurl(workspace?.slug, '/campaigns') };
    }, [metrics.roas, targets.roas, workspace?.slug]);

    const marketingPctAction = useMemo<{ line: string; href?: string } | undefined>(() => {
        if (metrics.marketing_spend_pct === null) return undefined;
        const pctStr = `${metrics.marketing_spend_pct}%`;
        if (targets.marketing_pct !== null) {
            const isGood = metrics.marketing_spend_pct <= targets.marketing_pct;
            return isGood
                ? { line: `${pctStr} of revenue — within budget` }
                : { line: `${pctStr} of revenue — over budget`, href: wurl(workspace?.slug, '/campaigns') };
        }
        return { line: `${pctStr} of revenue on ads` };
    }, [metrics.marketing_spend_pct, targets.marketing_pct, workspace?.slug]);

    const realCpoAction = useMemo<{ line: string; href?: string } | undefined>(() => {
        if (metrics.cpo === null) return undefined;
        const cpoStr = formatCurrency(metrics.cpo, currency);
        if (targets.cpo !== null) {
            const isGood = metrics.cpo <= targets.cpo;
            return isGood
                ? { line: `${cpoStr} per order — on target` }
                : { line: `${cpoStr} per order — above target`, href: wurl(workspace?.slug, '/campaigns') };
        }
        return { line: `${cpoStr} per order` };
    }, [metrics.cpo, targets.cpo, currency, workspace?.slug]);

    const notTrackedAction = useMemo<{ line: string; href?: string } | undefined>(() => {
        if (notTrackedPct === null) return undefined;
        if (notTrackedIsNeg) {
            return { line: 'Platforms over-reporting — see discrepancy', href: wurl(workspace?.slug, '/analytics/discrepancy') };
        }
        const pctStr = `${Math.abs(notTrackedPct).toFixed(1)}%`;
        return {
            line: `${pctStr} unattributed — improve tagging`,
            href: wurl(workspace?.slug, '/acquisition'),
        };
    }, [notTrackedPct, notTrackedIsNeg, workspace?.slug]);

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title="Overview" />

            {/* UTM coverage nudge modal — only when ads connected and coverage <50% */}
            {hasAds && utm_coverage && (
                <UtmCoverageNudgeModal
                    coveragePct={utm_coverage.pct}
                    coverageStatus={utm_coverage.status}
                />
            )}

            <PageHeader title="Overview" subtitle="Cross-channel command center" />
            <StoreFilter selectedStoreIds={store_ids} />

            {/* NULL FX warning */}
            {has_null_fx && (
                <div className="mb-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        Some revenue figures may be incomplete — exchange rates were unavailable
                        for certain orders in this period. Affected orders are excluded from totals.
                    </span>
                </div>
            )}

            {/* iOS14 attribution inflation banner */}
            {showInflationBanner && (
                <NotTrackedInflationBanner onDismiss={handleDismissBanner} />
            )}

            {/* AI daily summary */}
            {ai_summary && (
                <div className="mb-4 rounded-xl border border-zinc-200 bg-white p-5">
                    <div className="mb-2 flex items-center gap-1.5 section-label">
                        <Bot className="h-3.5 w-3.5" />
                        AI Daily Summary
                    </div>
                    <p className="text-sm leading-relaxed text-zinc-700">{ai_summary.summary_text}</p>
                </div>
            )}

            {/* ── Hero row ──────────────────────────────────────────────────── */}
            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <MetricCard
                    label="Revenue"
                    value={formatCurrency(metrics.revenue, currency)}
                    source="store"
                    change={storeDelta.revenue}
                    loading={navigating}
                    tooltip="Completed and processing orders converted to your reporting currency."
                    actionLine={heroRevenueAction}
                />
                <MetricCard
                    label="Orders"
                    value={formatNumber(metrics.orders)}
                    source="store"
                    change={storeDelta.orders}
                    loading={navigating}
                    actionLine={heroOrdersAction}
                />
                <AttentionCard alert={top_alert} daysOfData={days_of_data} />
            </div>

            {/* ── Daily average delta widget (Phase 1.4) ────────────────────── */}
            {/* "Last 7 days avg vs prior 7 days" — shows momentum at a glance.
                See: PLANNING.md "Daily average delta block" */}
            {daily_avg_delta && hasStore && (
                <div className="mb-4 rounded-xl border border-zinc-200 bg-white px-4 py-3">
                    <div className="mb-2 section-label">
                        Last 7 days vs prior 7 days
                    </div>
                    <div className="flex flex-wrap gap-6">
                        {/* Avg daily revenue */}
                        <div>
                            <p className="text-[10px] uppercase tracking-wide text-zinc-400">Avg Daily Revenue</p>
                            <div className="flex items-baseline gap-2">
                                <span className="text-xl font-semibold tabular-nums text-zinc-900">
                                    {daily_avg_delta.last7_avg_revenue != null
                                        ? formatCurrency(daily_avg_delta.last7_avg_revenue, currency)
                                        : '—'}
                                </span>
                                {daily_avg_delta.revenue_delta_pct != null && (
                                    <span className={cn(
                                        'text-xs font-semibold',
                                        daily_avg_delta.revenue_delta_pct >= 0 ? 'text-green-600' : 'text-red-600',
                                    )}>
                                        {daily_avg_delta.revenue_delta_pct >= 0 ? '+' : ''}{daily_avg_delta.revenue_delta_pct.toFixed(1)}%
                                    </span>
                                )}
                            </div>
                            {daily_avg_delta.prev7_avg_revenue != null && (
                                <p className="mt-0.5 text-[10px] text-zinc-400">
                                    prev: {formatCurrency(daily_avg_delta.prev7_avg_revenue, currency)}/day
                                </p>
                            )}
                        </div>
                        {/* Avg daily orders */}
                        <div>
                            <p className="text-[10px] uppercase tracking-wide text-zinc-400">Avg Daily Orders</p>
                            <div className="flex items-baseline gap-2">
                                <span className="text-xl font-semibold tabular-nums text-zinc-900">
                                    {daily_avg_delta.last7_avg_orders != null
                                        ? daily_avg_delta.last7_avg_orders.toFixed(1)
                                        : '—'}
                                </span>
                                {daily_avg_delta.orders_delta_pct != null && (
                                    <span className={cn(
                                        'text-xs font-semibold',
                                        daily_avg_delta.orders_delta_pct >= 0 ? 'text-green-600' : 'text-red-600',
                                    )}>
                                        {daily_avg_delta.orders_delta_pct >= 0 ? '+' : ''}{daily_avg_delta.orders_delta_pct.toFixed(1)}%
                                    </span>
                                )}
                            </div>
                            {daily_avg_delta.prev7_avg_orders != null && (
                                <p className="mt-0.5 text-[10px] text-zinc-400">
                                    prev: {daily_avg_delta.prev7_avg_orders.toFixed(1)}/day
                                </p>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* ── Real row (Phase 1.2) — truth lens across all channels ─────── */}
            {/* "Real" = Nexstage-computed metrics from multiple sources (store + ads).
                The section header carries the provenance context — no per-card source badge needed.
                See: PLANNING.md "Real row — Phase 1.2" */}
            {showRealRow && (
                <div className="mb-4">
                    {/* Section label — one lightbulb for the whole group, not one per card */}
                    <div className="mb-2 flex items-center gap-1.5">
                        <Lightbulb className="h-3.5 w-3.5 text-amber-400" />
                        <span className="section-label">
                            Blended — store revenue vs ad spend, cross-source estimate
                        </span>
                    </div>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {/* Real ROAS — trendDots: last 14 days hit/miss vs target */}
                        <MetricCard
                            label="ROAS"
                            value={metrics.roas != null ? `${metrics.roas.toFixed(2)}×` : null}
                            target={targets.roas ?? undefined}
                            targetDirection="above"
                            targetLabel={targets.roas != null ? 'target' : undefined}
                            change={paidDelta.roas}
                            trendDots={targets.roas != null ? trend_dots.roas : undefined}
                            loading={navigating}
                            tooltip="Store revenue ÷ total ad spend. Uses your actual orders, not platform pixel attribution. Blended across all connected ad platforms."
                            actionLine={realRoasAction?.line}
                            actionHref={realRoasAction?.href}
                        />
                        {/* Marketing % — trendDots: last 14 days hit/miss vs target */}
                        <MetricCard
                            label="Marketing %"
                            value={metrics.marketing_spend_pct != null ? `${metrics.marketing_spend_pct}%` : null}
                            target={targets.marketing_pct ?? undefined}
                            targetDirection="below"
                            targetLabel={targets.marketing_pct != null ? 'target' : undefined}
                            trendDots={targets.marketing_pct != null ? trend_dots.marketing_pct : undefined}
                            loading={navigating}
                            tooltip="Ad spend as a percentage of revenue. Lower is better — a high marketing % means you're spending a large share of revenue on ads."
                            actionLine={marketingPctAction?.line}
                            actionHref={marketingPctAction?.href}
                        />
                        {/* Real CPO — trendDots: last 14 days hit/miss vs target */}
                        <MetricCard
                            label="Cost per order"
                            value={metrics.cpo != null ? formatCurrency(metrics.cpo, currency) : null}
                            target={targets.cpo ?? undefined}
                            targetDirection="below"
                            targetLabel={targets.cpo != null ? 'target' : undefined}
                            change={paidDelta.cpo}
                            invertTrend
                            trendDots={targets.cpo != null ? trend_dots.cpo : undefined}
                            loading={navigating}
                            tooltip="Total ad spend ÷ total store orders. Uses your actual order count, not platform-attributed conversions."
                            actionLine={realCpoAction?.line}
                            actionHref={realCpoAction?.href}
                        />
                        {/* Not Tracked % */}
                        <MetricCard
                            label="Not tracked %"
                            value={notTrackedPct != null ? `${notTrackedPct.toFixed(1)}%` : null}
                            loading={navigating}
                            tooltip={notTrackedTooltip}
                            actionLine={notTrackedAction?.line}
                            actionHref={notTrackedAction?.href}
                        />
                    </div>
                </div>
            )}

            {/* ── Channel rows ──────────────────────────────────────────────── */}
            <div className="mb-4 space-y-3">

                {/* Store section */}
                <ChannelSection
                    title="Store"
                    icon={ShoppingBag}
                    color="bg-primary"
                    defaultOpen={hasStore}
                    connectHref={!hasStore ? wurl(workspace?.slug, '/onboarding') : undefined}
                    connectMessage="Connect your store to track revenue, orders, and customers →"
                    footer={recent_orders ? <LatestOrdersFeed feed={recent_orders} currency={currency} workspaceSlug={workspace?.slug} /> : undefined}
                >
                    <MetricCard
                        label="Revenue"
                        value={formatCurrency(metrics.revenue, currency)}
                        source="store"
                        change={storeDelta.revenue}
                        loading={navigating}
                        tooltip="Completed and processing orders converted to your reporting currency."
                    />
                    <MetricCard
                        label="Orders"
                        value={formatNumber(metrics.orders)}
                        source="store"
                        change={storeDelta.orders}
                        loading={navigating}
                    />
                    <MetricCard
                        label="AOV"
                        value={metrics.aov != null ? formatCurrency(metrics.aov, currency) : null}
                        source="store"
                        change={storeDelta.aov}
                        loading={navigating}
                        tooltip="Average Order Value. Total revenue divided by number of completed and processing orders."
                    />
                    <MetricCard
                        label="New Customers"
                        value={metrics.new_customers != null ? formatNumber(metrics.new_customers) : null}
                        source="store"
                        change={storeDelta.new_customers}
                        loading={navigating}
                        tooltip="First-time buyers in this period — orders from customer IDs with no prior purchase history."
                    />
                </ChannelSection>

                {/* Paid Ads section */}
                <ChannelSection
                    title="Paid Ads"
                    icon={Zap}
                    color="bg-amber-500"
                    defaultOpen={hasAds}
                    headerBadge={utm_coverage ? <UtmCoverageBadge coverage={utm_coverage} /> : undefined}
                    footer={utm_coverage?.unrecognized_sources?.length ? (
                        <UnrecognizedSourcesBanner sources={utm_coverage.unrecognized_sources} workspaceSlug={workspace?.slug} />
                    ) : undefined}
                    connectHref={!hasAds ? wurl(workspace?.slug, '/settings/integrations') : undefined}
                    connectMessage="Connect Meta Ads or Google Ads to see cross-channel ROAS →"
                    advancedChildren={advanced_paid_metrics ? (
                        <>
                            <MetricCard
                                label="CPM"
                                value={advanced_paid_metrics.cpm != null ? formatCurrency(advanced_paid_metrics.cpm, currency) : null}
                                change={paidDelta.cpm}
                                invertTrend
                                loading={navigating}
                                tooltip="Cost Per 1,000 Impressions. Total spend ÷ impressions × 1,000. Lower = more efficient reach."
                            />
                            <MetricCard
                                label="CPC"
                                value={advanced_paid_metrics.cpc != null ? formatCurrency(advanced_paid_metrics.cpc, currency) : null}
                                change={paidDelta.cpc}
                                invertTrend
                                loading={navigating}
                                tooltip="Cost Per Click. Total spend ÷ total clicks. Lower = more efficient traffic acquisition."
                            />
                            <MetricCard
                                label="Platform Conv. Rate"
                                value={advanced_paid_metrics.platform_conversion_rate != null
                                    ? `${advanced_paid_metrics.platform_conversion_rate.toFixed(2)}%`
                                    : null}
                                change={paidDelta.platform_conversion_rate}
                                loading={navigating}
                                tooltip="Platform-reported conversions ÷ clicks. This is what Meta/Google report — differs from your store's actual conversion rate due to attribution models."
                            />
                            <MetricCard
                                label="Platform Conversions"
                                value={advanced_paid_metrics.platform_conversions > 0
                                    ? formatNumber(advanced_paid_metrics.platform_conversions)
                                    : null}
                                loading={navigating}
                                tooltip="Purchase conversions reported by the ad platforms (Meta + Google). These may over-count due to iOS14+ modeled attribution."
                            />
                        </>
                    ) : undefined}
                >
                    <MetricCard
                        label="Ad Spend"
                        value={metrics.ad_spend != null ? formatCurrency(metrics.ad_spend, currency) : null}
                        source="facebook"
                        change={paidDelta.ad_spend}
                        invertTrend
                        loading={navigating}
                        tooltip="Total spend across all connected ad platforms (Meta + Google) in the selected period."
                    />
                    <MetricCard
                        label="Real ROAS"
                        value={metrics.roas != null ? `${metrics.roas.toFixed(2)}×` : null}
                        source="real"
                        target={targets.roas ?? undefined}
                        targetDirection="above"
                        change={paidDelta.roas}
                        loading={navigating}
                        tooltip="Store revenue ÷ ad spend. Calculated from your actual orders, not platform pixel attribution."
                        actionLine={realRoasAction?.line}
                        actionHref={realRoasAction?.href}
                    />
                    <MetricCard
                        label="Attributed Revenue"
                        value={metrics.attributed_revenue != null ? formatCurrency(metrics.attributed_revenue, currency) : null}
                        source="store"
                        change={paidDelta.attributed_revenue}
                        loading={navigating}
                        tooltip="Revenue from orders where utm_source matches a paid ad platform (Meta or Google). Based on UTM parameters, not pixel attribution."
                    />
                    <MetricCard
                        label="Real CPO"
                        value={metrics.cpo != null ? formatCurrency(metrics.cpo, currency) : null}
                        source="real"
                        target={targets.cpo ?? undefined}
                        targetDirection="below"
                        change={paidDelta.cpo}
                        invertTrend
                        loading={navigating}
                        tooltip="Cost Per Order. Total ad spend ÷ total store orders. Uses actual orders, not platform-attributed conversions."
                        actionLine={realCpoAction?.line}
                        actionHref={realCpoAction?.href}
                    />
                </ChannelSection>

                {/* Organic Search section */}
                <ChannelSection
                    title="Organic Search"
                    icon={TrendingUp}
                    color="bg-emerald-500"
                    defaultOpen={hasGsc}
                    connectHref={!hasGsc ? wurl(workspace?.slug, '/settings/integrations') : undefined}
                    connectMessage="Connect Google Search Console to track organic traffic →"
                >
                    <MetricCard
                        label="GSC Clicks"
                        value={gsc_metrics != null ? formatNumber(gsc_metrics.gsc_clicks) : null}
                        source="gsc"
                        change={organicDelta.gsc_clicks}
                        loading={navigating}
                        actionLine="View organic details"
                        actionHref={wurl(workspace?.slug, '/seo')}
                    />
                    <MetricCard
                        label="Impressions"
                        value={gsc_metrics != null ? formatNumber(gsc_metrics.gsc_impressions) : null}
                        source="gsc"
                        change={organicDelta.gsc_impressions}
                        loading={navigating}
                    />
                    <MetricCard
                        label="Avg Position"
                        value={gsc_metrics?.avg_position != null
                            ? gsc_metrics.avg_position.toFixed(1)
                            : null}
                        source="gsc"
                        change={organicDelta.avg_position}
                        invertTrend
                        loading={navigating}
                        tooltip="Weighted average search position across all queries and properties. Lower is better. Weighted by impressions."
                    />
                    <MetricCard
                        label={notTrackedIsNeg ? 'Not Tracked (negative)' : 'Not Tracked Revenue'}
                        value={notTrackedAbs !== 0 ? formatCurrency(notTrackedAbs, currency) : null}
                        source="real"
                        change={organicDelta.not_tracked_revenue}
                        loading={navigating}
                        tooltip={notTrackedTooltip}
                        actionLine={notTrackedAction?.line}
                        actionHref={notTrackedAction?.href}
                    />
                </ChannelSection>

                {/* Site Performance section */}
                <ChannelSection
                    title="Site Performance"
                    icon={Gauge}
                    color="bg-violet-500"
                    defaultOpen={hasPsi}
                    connectHref={!hasPsi ? wurl(workspace?.slug, '/settings/integrations') : undefined}
                    connectMessage="Add a monitored URL to track Lighthouse scores →"
                >
                    <MetricCard
                        label="Performance Score"
                        value={psi_metrics?.performance_score != null ? `${psi_metrics.performance_score} / 100` : null}
                        source="site"
                        loading={navigating}
                        tooltip="Lighthouse Performance score (0–100) for your homepage. Mobile strategy, latest check."
                        actionLine="View site performance"
                        actionHref={wurl(workspace?.slug, '/performance')}
                    />
                    <MetricCard
                        label="LCP"
                        value={fmtMs(psi_metrics?.lcp_ms ?? null)}
                        source="site"
                        loading={navigating}
                        tooltip="Largest Contentful Paint. Target: ≤2.5s (good), ≤4.0s (needs improvement)."
                    />
                    <MetricCard
                        label="CLS"
                        value={fmtCls(psi_metrics?.cls_score ?? null)}
                        source="site"
                        loading={navigating}
                        tooltip="Cumulative Layout Shift. Target: ≤0.1 (good), ≤0.25 (needs improvement)."
                    />
                    <MetricCard
                        label="Uptime"
                        value={null}
                        source="site"
                        loading={navigating}
                        tooltip="Store uptime over the selected period. Phase 2+."
                    />
                </ChannelSection>

            </div>

            {/* ── Multi-series chart ────────────────────────────────────────── */}
            <div className="rounded-xl border border-zinc-200 bg-white p-5">
                <div className="mb-3 flex items-center justify-between">
                    <span className="text-sm font-medium text-zinc-500">Performance over time</span>
                    <div className="flex items-center gap-2">
                        {granularity === 'hourly' && hasGsc && (
                            <span className="text-xs text-zinc-400">GSC data is daily only — hourly view shows no organic data.</span>
                        )}
                        {notes.length > 0 && (
                            <button
                                onClick={() => setShowNotes((v) => !v)}
                                className={`flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors ${
                                    showNotes
                                        ? 'border-amber-300 bg-amber-50 text-amber-700'
                                        : 'border-zinc-200 bg-white text-zinc-400 hover:text-zinc-600'
                                }`}
                            >
                                <span className="h-2 w-2 rounded-full bg-amber-400" />
                                Notes
                            </button>
                        )}
                    </div>
                </div>
                {navigating ? (
                    <div className="h-64 w-full animate-pulse rounded-lg bg-zinc-100" />
                ) : chart_data.length === 0 ? (
                    <div className="flex h-64 flex-col items-center justify-center gap-2 text-center">
                        <p className="text-sm text-zinc-400">No data for this period.</p>
                        <p className="text-xs text-zinc-400">
                            Data appears once the nightly snapshot job has run.
                        </p>
                    </div>
                ) : (
                    <MultiSeriesLineChart
                        data={chart_data}
                        comparisonData={compare_chart_data ?? undefined}
                        notes={showNotes ? notes : undefined}
                        holidays={holidays}
                        workspaceEvents={workspace_events}
                        granularity={granularity}
                        currency={currency}
                        timezone={timezone}
                    />
                )}
            </div>

            {/* ── Period comparison delta table — shown when compare period is active ── */}
            {compare_metrics !== null && (
                <PeriodComparisonTable
                    metrics={metrics}
                    compareMetrics={compare_metrics}
                    gscMetrics={gsc_metrics}
                    compareGscMetrics={compare_gsc_metrics}
                    currency={currency}
                />
            )}

            {/* ── Daily notes ──────────────────────────────────────────────── */}
            <DailyNotesSection notes={notes} />

        </AppLayout>
    );
}

// ─── Daily notes section ───────────────────────────────────────────────────────

function DailyNotesSection({ notes }: { notes: NotePoint[] }) {
    const today = new Date().toISOString().slice(0, 10);
    const todayNote = notes.find((n) => n.date === today)?.note ?? null;

    const pastNotes = notes
        .filter((n) => n.date < today)
        .sort((a, b) => b.date.localeCompare(a.date))
        .slice(0, 5);

    return (
        <div className="mt-4 rounded-xl border border-zinc-200 bg-white p-5">
            <div className="mb-4 flex items-center gap-1.5 section-label">
                <NotebookPen className="h-3.5 w-3.5" />
                Daily Notes
            </div>

            <NoteInput date={today} initialNote={todayNote} />

            {pastNotes.length > 0 && (
                <div className="mt-4 space-y-2 border-t border-zinc-100 pt-4">
                    {pastNotes.map((n) => {
                        const d = new Date(n.date);
                        const label = d.toLocaleDateString('en-GB', {
                            weekday: 'short', day: 'numeric', month: 'numeric',
                        });
                        return (
                            <div key={n.date} className="flex gap-3 text-sm">
                                <span className="w-24 shrink-0 text-xs text-zinc-400">{label}</span>
                                <span className="text-zinc-600">{n.note}</span>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
