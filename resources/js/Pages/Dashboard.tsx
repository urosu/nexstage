import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Bot, Store } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { MetricCard } from '@/Components/shared/MetricCard';
import { AnalyticsTabBar } from '@/Components/shared/AnalyticsTabBar';
import { StoreFilter } from '@/Components/shared/StoreFilter';
import { MultiSeriesLineChart } from '@/Components/charts/MultiSeriesLineChart';
import type { MultiSeriesPoint } from '@/Components/charts/MultiSeriesLineChart';
import { formatCurrency, formatNumber, type Granularity } from '@/lib/formatters';
import type { PageProps } from '@/types';

interface DashboardMetrics {
    revenue: number;
    orders: number;
    roas: number | null;
    aov: number | null;
    items_per_order: number | null;
    marketing_spend_pct: number | null;
    ad_spend: number | null;
}

interface AiSummaryData {
    summary_text: string;
    generated_at: string;
}

interface NotePoint {
    date: string;
    note: string;
}

interface Props {
    has_stores: boolean;
    show_ad_accounts_banner: boolean;
    metrics: DashboardMetrics | null;
    compare_metrics: DashboardMetrics | null;
    chart_data: MultiSeriesPoint[];
    compare_chart_data: MultiSeriesPoint[] | null;
    ai_summary: AiSummaryData | null;
    has_null_fx: boolean;
    granularity: Granularity;
    store_ids: number[];
    notes: NotePoint[];
}

/** Returns percentage change, null when previous is zero/null or either value is null. */
function pctChange(current: number | null, previous: number | null): number | null {
    if (current === null || previous === null || previous === 0) return null;
    return ((current - previous) / previous) * 100;
}

export default function Dashboard({
    has_stores,
    show_ad_accounts_banner,
    metrics,
    compare_metrics,
    chart_data,
    compare_chart_data,
    ai_summary,
    has_null_fx,
    granularity,
    store_ids,
    notes,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const timezone = workspace?.reporting_timezone;

    const [navigating, setNavigating] = useState(false);
    const [showNotes, setShowNotes] = useState(true);

    useEffect(() => {
        const removeStart  = router.on('start',  () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));
        return () => {
            removeStart();
            removeFinish();
        };
    }, []);

    // Memoised comparison deltas — only recomputed when metrics change
    const changes = useMemo(() => {
        const cm = compare_metrics;
        const m  = metrics;
        return {
            revenue:          pctChange(m?.revenue          ?? null, cm?.revenue          ?? null),
            orders:           pctChange(m?.orders           ?? null, cm?.orders           ?? null),
            roas:             pctChange(m?.roas             ?? null, cm?.roas             ?? null),
            aov:              pctChange(m?.aov              ?? null, cm?.aov              ?? null),
            items_per_order:  pctChange(m?.items_per_order  ?? null, cm?.items_per_order  ?? null),
            marketing_spend_pct: pctChange(
                m?.marketing_spend_pct ?? null,
                cm?.marketing_spend_pct ?? null,
            ),
            ad_spend: pctChange(m?.ad_spend ?? null, cm?.ad_spend ?? null),
        };
    }, [metrics, compare_metrics]);

    const topBarRight = <><DateRangePicker /><StoreFilter selectedStoreIds={store_ids} /></>;

    // ── Empty state ──────────────────────────────────────────────────────────
    if (!has_stores) {
        return (
            <AppLayout dateRangePicker={topBarRight}>
                <Head title="Analytics" />
                <PageHeader title="Analytics" subtitle="Workspace overview" />
                <AnalyticsTabBar />
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100">
                        <Store className="h-6 w-6 text-zinc-400" />
                    </div>
                    <h3 className="mb-1 text-base font-semibold text-zinc-900">No store connected</h3>
                    <p className="mb-5 max-w-xs text-sm text-zinc-500">
                        Connect your first store to start tracking revenue, orders, and ad performance.
                    </p>
                    <Link
                        href="/onboarding"
                        className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        Connect a store →
                    </Link>
                </div>
            </AppLayout>
        );
    }

    // ── Main dashboard ───────────────────────────────────────────────────────
    return (
        <AppLayout dateRangePicker={topBarRight}>
            <Head title="Analytics" />
            <PageHeader title="Analytics" subtitle="Workspace overview" />
            <AnalyticsTabBar />

            {/* Ad accounts banner */}
            {show_ad_accounts_banner && (
                <div className="mb-4 flex items-center justify-between rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-700">
                    <span>Connect ad accounts to see ROAS and blended performance.</span>
                    <Link
                        href="/settings/integrations"
                        className="ml-4 shrink-0 font-medium underline hover:text-indigo-900"
                    >
                        Connect now →
                    </Link>
                </div>
            )}

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

            {/* AI daily summary */}
            {ai_summary && (
                <div className="mb-6 rounded-xl border border-zinc-200 bg-white p-5">
                    <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                        <Bot className="h-3.5 w-3.5" />
                        AI Daily Summary
                    </div>
                    <p className="text-sm leading-relaxed text-zinc-700">
                        {ai_summary.summary_text}
                    </p>
                </div>
            )}

            {/* Metric cards — 7 columns: Revenue, Orders, ROAS, AOV, Items/Order, Mktg%, Ad Spend */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4 xl:grid-cols-7">
                <MetricCard
                    label="Revenue"
                    value={metrics ? formatCurrency(metrics.revenue, currency) : null}
                    change={changes.revenue}
                    loading={navigating}
                />
                <MetricCard
                    label="Orders"
                    value={metrics ? formatNumber(metrics.orders) : null}
                    change={changes.orders}
                    loading={navigating}
                />
                <MetricCard
                    label="ROAS"
                    value={metrics?.roas != null ? `${metrics.roas.toFixed(2)}×` : null}
                    change={changes.roas}
                    loading={navigating}
                    tooltip="Return On Ad Spend. Total revenue divided by total ad spend across all connected platforms. N/A when no ad accounts are connected."
                />
                <MetricCard
                    label="AOV"
                    value={metrics?.aov != null ? formatCurrency(metrics.aov, currency) : null}
                    change={changes.aov}
                    loading={navigating}
                    tooltip="Average Order Value. Total revenue divided by number of completed and processing orders."
                />
                <MetricCard
                    label="Items / Order"
                    value={metrics?.items_per_order != null
                        ? metrics.items_per_order.toFixed(1)
                        : null}
                    change={changes.items_per_order}
                    loading={navigating}
                    tooltip="Average number of line items per order in the selected period."
                />
                <MetricCard
                    label="Mktg Spend %"
                    value={metrics?.marketing_spend_pct != null
                        ? `${metrics.marketing_spend_pct.toFixed(1)}%`
                        : null}
                    change={changes.marketing_spend_pct}
                    invertTrend
                    loading={navigating}
                    tooltip="Ad spend as a percentage of total revenue. Lower means more efficient use of marketing budget."
                />
                <MetricCard
                    label="Ad Spend"
                    value={metrics?.ad_spend != null
                        ? formatCurrency(metrics.ad_spend, currency)
                        : null}
                    change={changes.ad_spend}
                    invertTrend
                    loading={navigating}
                    tooltip="Total amount spent across all connected ad platforms (Facebook Ads + Google Ads) in the selected period."
                />
            </div>

            {/* Multi-series chart */}
            <div className="rounded-xl border border-zinc-200 bg-white p-5">
                <div className="mb-3 flex items-center justify-between">
                    <span className="text-sm font-medium text-zinc-500">Performance over time</span>
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
                        granularity={granularity}
                        currency={currency}
                        timezone={timezone}
                    />
                )}
            </div>
        </AppLayout>
    );
}
