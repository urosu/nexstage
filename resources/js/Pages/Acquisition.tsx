import { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level keeps the skeleton visible.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import { Layers } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ScopeFilter } from '@/Components/shared/ScopeFilter';
import { DestinationTabs } from '@/Components/shared/DestinationTabs';
import { MetricCard } from '@/Components/shared/MetricCard';
import { ChannelMatrix } from '@/Components/shared/ChannelMatrix';
import { PlatformVsRealTable } from '@/Components/shared/PlatformVsRealTable';
import { JourneyTimeline } from '@/Components/shared/JourneyTimeline';
import { OpportunitiesSidebar } from '@/Components/shared/OpportunitiesSidebar';
import { formatCurrency, formatNumber, formatDateOnly } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';
import type { ChannelMatrixRow } from '@/Components/shared/ChannelMatrix';
import type { CampaignDiscrepancyRow, DiscrepancyChartPoint, DiscrepancyHero } from '@/Components/shared/PlatformVsRealTable';
import type { JourneyOrder } from '@/Components/shared/JourneyTimeline';
import type { OpportunityItem } from '@/Components/shared/OpportunitiesSidebar';

// ── Types ─────────────────────────────────────────────────────────────────────

interface HeroMetrics {
    total_orders: number;
    total_revenue: number;
    top_converting: string | null;
    top_cvr: number | null;
    total_profit: number;
    attributed_orders: number;
    coverage_pct: number | null;
}

interface DiscrepancyData {
    campaigns: CampaignDiscrepancyRow[];
    chart_data: DiscrepancyChartPoint[];
    hero: DiscrepancyHero;
    platform: string;
}

interface JourneyData {
    orders: JourneyOrder[];
    filter: string;
}

interface Props {
    channels: ChannelMatrixRow[];
    channels_total_count: number;
    has_cogs: boolean;
    hero: HeroMetrics;
    other_tagged_detail: unknown[];
    chart_data: unknown[];
    from: string;
    to: string;
    store_ids: number[];
    view: string;
    filter: string;
    classifier: string;
    narrative: string | null;
    // Phase 3.5 additions
    tab: string;
    attribution_model: 'first_touch' | 'last_touch';
    discrepancy: DiscrepancyData;
    journeys: JourneyData;
    opportunities: OpportunityItem[];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function JourneyFilterPill({
    label,
    active,
    onClick,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            onClick={onClick}
            className={cn(
                'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                active
                    ? 'border-primary bg-primary/10 text-primary'
                    : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
            )}
        >
            {label}
        </button>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function Acquisition(props: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const [navigating, setNavigating] = useState(() => _inertiaNavigating);
    const [selectedOrder, setSelectedOrder] = useState<JourneyOrder | null>(null);

    const {
        channels, hero, store_ids, narrative,
        tab, attribution_model, discrepancy, journeys, opportunities,
        from, to, filter,
    } = props;

    useEffect(() => {
        const off1 = router.on('start',  () => setNavigating(true));
        const off2 = router.on('finish', () => setNavigating(false));
        return () => { off1(); off2(); };
    }, []);

    // ── Navigation helpers ───────────────────────────────────────────────────

    function navigate(patch: Record<string, string | null | undefined>): void {
        const params = new URLSearchParams(window.location.search);
        params.delete('page');
        for (const [key, value] of Object.entries(patch)) {
            if (value === null || value === undefined || value === '') {
                params.delete(key);
            } else {
                params.set(key, value);
            }
        }
        router.get(
            wurl(workspace?.slug, '/acquisition'),
            Object.fromEntries(params) as Record<string, string>,
            { preserveScroll: true, replace: true },
        );
    }

    function handleAttributionModelChange(model: 'first_touch' | 'last_touch') {
        navigate({ attribution_model: model });
    }

    function handlePlatformChange(p: string) {
        navigate({ platform: p !== 'all' ? p : null, tab: 'platform-vs-real' });
    }

    function handleJourneyFilter(f: string) {
        navigate({ journey_filter: f !== 'all' ? f : null, tab: 'journeys' });
    }

    // ── Tab definitions ──────────────────────────────────────────────────────

    const baseUrl = wurl(workspace?.slug, '/acquisition');
    const commonParams = new URLSearchParams({ from, to });
    if (attribution_model !== 'last_touch') commonParams.set('attribution_model', attribution_model);

    const tabs = [
        { key: 'channels',         label: 'Channels',         href: `${baseUrl}?${new URLSearchParams({ ...Object.fromEntries(commonParams), tab: 'channels' })}` },
        { key: 'platform-vs-real', label: 'Platform vs Real', href: `${baseUrl}?${new URLSearchParams({ ...Object.fromEntries(commonParams), tab: 'platform-vs-real' })}` },
        { key: 'journeys',         label: 'Customer Journeys',href: `${baseUrl}?${new URLSearchParams({ ...Object.fromEntries(commonParams), tab: 'journeys' })}` },
    ];

    // ── Empty state ──────────────────────────────────────────────────────────

    if (!navigating && channels.length === 0 && tab === 'channels') {
        return (
            <AppLayout>
                <Head title="Acquisition" />
                <PageHeader title="Acquisition" subtitle="Traffic sources that bring orders" narrative={narrative} />
                <ScopeFilter
                    selectedStoreIds={store_ids}
                    attributionModel={attribution_model}
                    onAttributionModelChange={handleAttributionModelChange}
                    showAttributionWindow
                />
                <DestinationTabs tabs={tabs} activeKey={tab} />
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100">
                        <Layers className="h-6 w-6 text-zinc-400" />
                    </div>
                    <h3 className="mb-1 text-base font-semibold text-zinc-900">No attribution data yet</h3>
                    <p className="max-w-xs text-sm text-zinc-500">
                        Attribution data appears after orders with UTM tags are synced and processed
                        by the attribution parser.
                    </p>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Acquisition" />
            <PageHeader
                title="Acquisition"
                subtitle="Which traffic sources bring me orders, not just visitors?"
                narrative={narrative}
            />

            <ScopeFilter
                selectedStoreIds={store_ids}
                attributionModel={attribution_model}
                onAttributionModelChange={handleAttributionModelChange}
                showAttributionWindow
            />

            <DestinationTabs tabs={tabs} activeKey={tab} />

            {/* ── Hero cards (shown on all tabs) ───────────────────────────── */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-5">
                <MetricCard
                    label="Total Orders"
                    source="store"
                    value={formatNumber(hero.total_orders)}
                    loading={navigating}
                    tooltip="Total attributed orders across all channels."
                />
                <MetricCard
                    label="Total Revenue"
                    source="store"
                    value={formatCurrency(hero.total_revenue, currency)}
                    loading={navigating}
                    tooltip="Total revenue from attributed orders."
                />
                <MetricCard
                    label="Top Converting Source"
                    source="real"
                    value={hero.top_converting}
                    subtext={hero.top_cvr != null ? `${hero.top_cvr}% CVR` : undefined}
                    loading={navigating}
                    tooltip="Channel with the highest conversion rate (min 5 orders). CVR = orders / clicks."
                />
                <MetricCard
                    label="Total Real Profit"
                    source="real"
                    value={formatCurrency(hero.total_profit, currency)}
                    loading={navigating}
                    tooltip="Revenue minus COGS, payment fees, shipping, and ad spend across all channels."
                />
                <MetricCard
                    label="Attribution Coverage"
                    source="real"
                    value={hero.coverage_pct != null ? `${hero.coverage_pct}%` : 'N/A'}
                    subtext={`${hero.attributed_orders} of ${hero.total_orders} orders`}
                    loading={navigating}
                    tooltip="Orders attributed to a known marketing channel. Direct counts as attributed."
                />
            </div>

            {/* ── Two-column layout: tab content + opportunities sidebar ──── */}
            <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                <div className="flex-1 min-w-0">

                    {/* Tab 1 — Channels */}
                    {tab === 'channels' && (
                        <ChannelMatrix
                            rows={channels}
                            currency={currency}
                            loading={navigating}
                            attributionModel={attribution_model}
                        />
                    )}

                    {/* Tab 2 — Platform vs Real */}
                    {tab === 'platform-vs-real' && (
                        <PlatformVsRealTable
                            campaigns={discrepancy.campaigns}
                            chartData={discrepancy.chart_data}
                            hero={discrepancy.hero}
                            currency={currency}
                            platform={discrepancy.platform}
                            onPlatformChange={handlePlatformChange}
                            loading={navigating}
                            attributionModel={attribution_model}
                        />
                    )}

                    {/* Tab 3 — Customer Journeys */}
                    {tab === 'journeys' && (
                        <div className="space-y-4">
                            {/* Filter chips */}
                            <div className="flex items-center gap-2">
                                <JourneyFilterPill
                                    label="All"
                                    active={journeys.filter === 'all'}
                                    onClick={() => handleJourneyFilter('all')}
                                />
                                <JourneyFilterPill
                                    label="New customers"
                                    active={journeys.filter === 'new_customers'}
                                    onClick={() => handleJourneyFilter('new_customers')}
                                />
                                <JourneyFilterPill
                                    label="High value"
                                    active={journeys.filter === 'high_ltv'}
                                    onClick={() => handleJourneyFilter('high_ltv')}
                                />
                            </div>

                            {/* Journey order list */}
                            {journeys.orders.length === 0 ? (
                                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-16 text-center">
                                    <p className="text-sm text-zinc-500">No orders found for this filter and period.</p>
                                </div>
                            ) : (
                                <div className="rounded-xl border border-zinc-200 bg-white overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-zinc-100">
                                                <th className="px-5 py-3 text-left text-[10px] font-medium uppercase tracking-wide text-zinc-400">Customer</th>
                                                <th className="px-3 py-3 text-right text-[10px] font-medium uppercase tracking-wide text-zinc-400">Revenue</th>
                                                <th className="px-3 py-3 text-left text-[10px] font-medium uppercase tracking-wide text-zinc-400">First Touch</th>
                                                <th className="px-3 py-3 text-left text-[10px] font-medium uppercase tracking-wide text-zinc-400">Last Touch</th>
                                                <th className="px-3 py-3 text-right text-[10px] font-medium uppercase tracking-wide text-zinc-400">Date</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-zinc-100">
                                            {journeys.orders.map((order) => (
                                                <tr
                                                    key={order.id}
                                                    className="cursor-pointer hover:bg-zinc-50 transition-colors"
                                                    onClick={() => setSelectedOrder(order)}
                                                >
                                                    <td className="px-5 py-3">
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-xs text-zinc-600 font-mono">
                                                                {order.customer_email_hash
                                                                    ? `cust-${order.customer_email_hash.slice(0, 8)}…`
                                                                    : 'anonymous'}
                                                            </span>
                                                            {order.is_first_for_customer && (
                                                                <span className="rounded-full bg-green-50 px-1.5 py-0.5 text-[10px] font-medium text-green-700 border border-green-200">
                                                                    New
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-3 text-right tabular-nums font-medium text-zinc-800">
                                                        {formatCurrency(order.revenue, currency)}
                                                    </td>
                                                    <td className="px-3 py-3 max-w-[160px]">
                                                        <span className="truncate text-xs text-zinc-500 block">
                                                            {order.attribution_first_touch?.channel
                                                                || order.attribution_first_touch?.source
                                                                || '—'}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-3 max-w-[160px]">
                                                        <span className="truncate text-xs text-zinc-500 block">
                                                            {order.attribution_last_touch?.channel
                                                                || order.attribution_last_touch?.source
                                                                || '—'}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-3 text-right text-xs text-zinc-400 tabular-nums">
                                                        {formatDateOnly(order.occurred_at)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Opportunities sidebar — full width on mobile, fixed-width on lg+ */}
                <OpportunitiesSidebar
                    items={opportunities}
                    currency={currency}
                    className="lg:block"
                />
            </div>

            {/* Journey timeline modal */}
            {selectedOrder && (
                <JourneyTimeline
                    order={selectedOrder}
                    currency={currency}
                    open={selectedOrder !== null}
                    onClose={() => setSelectedOrder(null)}
                />
            )}
        </AppLayout>
    );
}
