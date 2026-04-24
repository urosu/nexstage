import axios from 'axios';
import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level lets us start with
// navigating=true so the skeleton stays visible until the real data is ready.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import { AlertTriangle, X } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { MetricCard } from '@/Components/shared/MetricCard';
import { StoreFilter } from '@/Components/shared/StoreFilter';
import { UtmCoverageNudgeModal } from '@/Components/shared/UtmCoverageNudgeModal';
import { DataFreshness } from '@/Components/shared/DataFreshness';
import { TodaysAttention, type AttentionItem } from '@/Components/shared/TodaysAttention';
import { SiteHealthStrip } from '@/Components/shared/SiteHealthStrip';
import { RecentOrdersFeed } from '@/Components/shared/RecentOrdersFeed';
import { MultiSeriesLineChart } from '@/Components/charts/MultiSeriesLineChart';
import type { MultiSeriesPoint, HolidayOverlay, WorkspaceEventOverlay } from '@/Components/charts/MultiSeriesLineChart';
import { formatCurrency, formatNumber, type Granularity } from '@/lib/formatters';
import type { PageProps } from '@/types';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';

// ─── Types ────────────────────────────────────────────────────────────────────

interface DashboardMetrics {
    revenue: number;
    orders: number;
    aov: number | null;
    new_customers: number | null;
    ad_spend: number | null;
    roas: number | null;
    attributed_revenue: number | null;
    cpo: number | null;
    not_tracked_revenue: number;
    not_tracked_pct: number | null;
    items_per_order: number | null;
    marketing_spend_pct: number | null;
}

interface PsiMetrics {
    performance_score: number | null;
    lcp_ms: number | null;
    cls_score: number | null;
    checked_at: string | null;
}

interface GscMetrics {
    gsc_clicks: number;
    gsc_impressions: number;
    avg_position: number | null;
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

interface NotePoint { date: string; note: string; }

interface ChannelRollupRow {
    channel: string;
    revenue: number;
    spend: number | null;
    roas: number | null;
}

interface Props {
    psi_metrics: PsiMetrics | null;
    metrics: DashboardMetrics;
    compare_metrics: DashboardMetrics | null;
    same_weekday_metrics: DashboardMetrics | null;
    gsc_metrics: GscMetrics | null;
    targets: WorkspaceTargets;
    utm_coverage: UtmCoverage | null;
    not_tracked_banner_dismissed: boolean;
    chart_data: MultiSeriesPoint[];
    compare_chart_data: MultiSeriesPoint[] | null;
    days_of_data: number;
    has_null_fx: boolean;
    granularity: Granularity;
    store_ids: number[];
    notes: NotePoint[];
    holidays: HolidayOverlay[];
    workspace_events: WorkspaceEventOverlay[];
    recent_orders: RecentOrders | null;
    narrative: string | null;
    raw_orders_count: number;
    // Phase 3.6
    attention_items: AttentionItem[];
    contribution_margin: { cm: number | null; cogs_configured: boolean };
    channel_rollup: ChannelRollupRow[];
    uptime_30d_pct: number | null;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function pctChange(current: number | null, previous: number | null): number | null {
    if (current === null || previous === null || previous === 0) return null;
    return ((current - previous) / previous) * 100;
}

// ─── iOS14 Not Tracked inflation banner ───────────────────────────────────────

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
            <button onClick={onDismiss} aria-label="Dismiss" className="shrink-0 text-amber-400 hover:text-amber-600">
                <X className="h-4 w-4" />
            </button>
        </div>
    );
}

// ─── Day-1 snapshot pending banner ────────────────────────────────────────────

function SnapshotPendingBanner({ orderCount, workspaceSlug }: { orderCount: number; workspaceSlug: string | undefined }) {
    return (
        <div className="mb-4 flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3">
            <div className="mt-0.5 h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-blue-400 border-t-transparent" />
            <div className="flex-1 text-sm text-blue-800">
                <span className="font-semibold">
                    {orderCount.toLocaleString()} order{orderCount !== 1 ? 's' : ''} imported — analytics are being processed.
                </span>{' '}
                Revenue and order totals appear after the first snapshot aggregation (usually within a few minutes).{' '}
                <Link href={wurl(workspaceSlug, '/acquisition')} className="font-medium underline hover:no-underline">
                    See live order breakdown on Acquisition →
                </Link>
            </div>
        </div>
    );
}

// ─── Channel roll-up table ────────────────────────────────────────────────────

function ChannelRollupTable({ rows, currency }: { rows: ChannelRollupRow[]; currency: string }) {
    return (
        <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
            <div className="border-b border-zinc-100 px-4 py-2.5">
                <span className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Channel breakdown</span>
            </div>
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-zinc-100 text-xs font-medium text-zinc-400">
                        <th className="px-4 py-2 text-left">Channel</th>
                        <th className="px-4 py-2 text-right">Revenue</th>
                        <th className="px-4 py-2 text-right">Ad Spend</th>
                        <th className="px-4 py-2 text-right">Real ROAS</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-zinc-50">
                    {rows.map((row) => (
                        <tr key={row.channel} className="hover:bg-zinc-50 transition-colors">
                            <td className="px-4 py-2.5 font-medium text-zinc-700">{row.channel}</td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-zinc-900">
                                {row.revenue > 0 ? formatCurrency(row.revenue, currency) : <span className="text-zinc-300">—</span>}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums text-zinc-600">
                                {row.spend !== null ? formatCurrency(row.spend, currency) : <span className="text-zinc-300">—</span>}
                            </td>
                            <td className="px-4 py-2.5 text-right tabular-nums">
                                {row.roas !== null
                                    ? <span className={cn('font-semibold', row.roas >= 1 ? 'text-green-700' : 'text-red-600')}>{row.roas.toFixed(2)}x</span>
                                    : <span className="text-zinc-300">—</span>}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function Dashboard({
    psi_metrics,
    metrics,
    compare_metrics,
    same_weekday_metrics,
    gsc_metrics,
    targets,
    utm_coverage,
    not_tracked_banner_dismissed,
    chart_data,
    compare_chart_data,
    days_of_data,
    has_null_fx,
    granularity,
    store_ids,
    notes,
    holidays,
    workspace_events,
    recent_orders,
    narrative,
    raw_orders_count,
    attention_items,
    contribution_margin,
    channel_rollup,
    uptime_30d_pct,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';

    const hasStore = workspace?.has_store ?? false;
    const hasAds   = workspace?.has_ads   ?? false;
    const hasGsc   = workspace?.has_gsc   ?? false;

    const [navigating, setNavigating] = useState(() => _inertiaNavigating);

    // iOS14 Not Tracked inflation banner
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
        axios.post(wurl(workspace?.slug, '/dashboard/dismiss-not-tracked-banner')).catch(() => {});
    }

    // Hero row: compare vs same weekday last week (§F1), fall back to compare period.
    const comparisonMetrics = same_weekday_metrics ?? compare_metrics;

    const heroDelta = useMemo(() => ({
        revenue:  pctChange(metrics.revenue,    comparisonMetrics?.revenue    ?? null),
        orders:   pctChange(metrics.orders,     comparisonMetrics?.orders     ?? null),
        ad_spend: pctChange(metrics.ad_spend,   comparisonMetrics?.ad_spend   ?? null),
        roas:     pctChange(metrics.roas,       comparisonMetrics?.roas       ?? null),
    }), [metrics, comparisonMetrics]);

    // CM comparison: compare cm from same_weekday_metrics is not available
    // (it would need a separate server-side computation). No comparison shown for CM.

    const snapshotPending = days_of_data === 0 && raw_orders_count > 0;

    return (
        <AppLayout>
            <Head title="Home" />

            <div className="mx-auto max-w-screen-xl px-4 py-6 sm:px-6 lg:px-8">

                {/* Status strip */}
                <div className="mb-4">
                    <DataFreshness variant="strip" />
                </div>

                {/* Page header */}
                <PageHeader
                    title="Home"
                    subtitle="Cross-channel command center"
                    narrative={narrative}
                    action={
                        <div className="flex flex-wrap items-center gap-2">
                            <StoreFilter selectedStoreIds={store_ids} />
                            <DateRangePicker />
                            {utm_coverage && (
                                <UtmCoverageNudgeModal
                                    coveragePct={utm_coverage.pct}
                                    coverageStatus={utm_coverage.status}
                                />
                            )}
                        </div>
                    }
                />

                {snapshotPending && (
                    <SnapshotPendingBanner orderCount={raw_orders_count} workspaceSlug={workspace?.slug} />
                )}

                {showInflationBanner && (
                    <NotTrackedInflationBanner onDismiss={handleDismissBanner} />
                )}

                {/* ── Hero row — 5 MetricCards equal width ────────────────── */}
                <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
                    <MetricCard
                        label="Revenue"
                        value={formatCurrency(metrics.revenue, currency)}
                        source="store"
                        change={heroDelta.revenue}
                        loading={navigating}
                        tooltip="Completed and processing orders converted to your reporting currency."
                        actionLine="View orders"
                        actionHref={wurl(workspace?.slug, '/store?tab=orders')}
                    />
                    <MetricCard
                        label="Orders"
                        value={formatNumber(metrics.orders)}
                        source="store"
                        change={heroDelta.orders}
                        loading={navigating}
                        actionLine="View orders"
                        actionHref={wurl(workspace?.slug, '/store?tab=orders')}
                    />
                    <MetricCard
                        label="Ad Spend"
                        value={metrics.ad_spend != null ? formatCurrency(metrics.ad_spend, currency) : null}
                        source="real"
                        change={heroDelta.ad_spend}
                        loading={navigating}
                        tooltip={hasAds
                            ? 'Total ad spend across Facebook and Google Ads for the selected period.'
                            : undefined}
                        actionLine={hasAds ? 'View campaigns' : 'Connect Facebook / Google for Real ROAS'}
                        actionHref={wurl(workspace?.slug, hasAds ? '/campaigns' : '/manage/integrations')}
                        subtext={!hasAds ? 'No ads connected' : undefined}
                    />
                    <MetricCard
                        label="Real ROAS"
                        value={metrics.roas != null ? `${metrics.roas.toFixed(2)}x` : null}
                        source="real"
                        change={heroDelta.roas}
                        loading={navigating}
                        target={targets.roas}
                        targetDirection="above"
                        tooltip="Store revenue attributed to paid channels ÷ total ad spend. Based on your store orders, not platform pixels."
                        actionLine={metrics.roas != null ? 'View acquisition' : 'N/A — no ad spend'}
                        actionHref={wurl(workspace?.slug, '/acquisition')}
                        subtext={!hasAds ? 'No ads connected' : undefined}
                    />
                    <MetricCard
                        label="Contribution Margin"
                        value={contribution_margin.cm != null
                            ? formatCurrency(contribution_margin.cm, currency)
                            : null}
                        source="real"
                        loading={navigating}
                        tooltip="Revenue minus COGS, payment fees, shipping, and ad spend (§F3)."
                        actionLine={contribution_margin.cogs_configured
                            ? 'View products'
                            : 'Configure product costs'}
                        actionHref={wurl(workspace?.slug, contribution_margin.cogs_configured
                            ? '/store?tab=products'
                            : '/manage/product-costs')}
                        subtext={!contribution_margin.cogs_configured ? 'COGS not configured' : undefined}
                    />
                </div>

                {/* ── Two-column: Trend chart (60%) + Today's Attention (40%) ── */}
                <div className="mb-6 flex flex-col gap-4 lg:flex-row">
                    <div className="lg:w-[60%]">
                        <div className="h-full rounded-lg border border-zinc-200 bg-white p-4">
                            <h3 className="mb-3 text-sm font-semibold text-zinc-800">28-day trend</h3>
                            <MultiSeriesLineChart
                                data={chart_data}
                                comparisonData={compare_chart_data ?? undefined}
                                granularity={granularity}
                                notes={notes}
                                holidays={holidays}
                                workspaceEvents={workspace_events}
                                currency={currency}
                            />
                        </div>
                    </div>
                    <div className="lg:w-[40%]">
                        <TodaysAttention items={attention_items} />
                    </div>
                </div>

                {/* ── Channel roll-up — 5 rows (§M1) ────────────────────────── */}
                <div className="mb-6">
                    <ChannelRollupTable rows={channel_rollup} currency={currency} />
                </div>

                {/* ── Site health strip ──────────────────────────────────────── */}
                {(psi_metrics || uptime_30d_pct !== null) && (
                    <div className="mb-6">
                        <SiteHealthStrip
                            score={psi_metrics?.performance_score ?? null}
                            lcp_ms={psi_metrics?.lcp_ms ?? null}
                            uptime_pct={uptime_30d_pct}
                            performanceHref={wurl(workspace?.slug, '/performance') ?? '/performance'}
                        />
                    </div>
                )}

                {/* ── Recent orders feed ─────────────────────────────────────── */}
                {hasStore && recent_orders && (
                    <RecentOrdersFeed feed={recent_orders} currency={currency} />
                )}

            </div>
        </AppLayout>
    );
}
