import { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level lets us start with
// navigating=true so the skeleton stays visible until the real data is ready.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import { Layers } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StoreFilter } from '@/Components/shared/StoreFilter';
import { MetricCard } from '@/Components/shared/MetricCard';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────────

interface ChannelRow {
    channel_type: string;
    channel_name: string;
    clicks: number | null;
    orders: number;
    cvr: number | null;
    revenue: number;
    ad_spend: number | null;
    total_cogs: number | null;
    contribution_margin: number | null;
    real_profit: number | null;
    wl_tag: 'winner' | 'loser' | null;
}

interface HeroMetrics {
    total_orders: number;
    total_revenue: number;
    top_converting: string | null;
    top_cvr: number | null;
    total_profit: number;
    attributed_orders: number;
    coverage_pct: number | null;
}

interface Props {
    channels: ChannelRow[];
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
}

// ── Channel display helpers ─────────────────────────────────────────────────

const CHANNEL_TYPE_LABELS: Record<string, string> = {
    paid_social:    'Paid Social',
    paid_search:    'Paid Search',
    organic_search: 'Organic Search',
    organic_social: 'Social',
    email:          'Email',
    sms:            'SMS',
    affiliate:      'Affiliate',
    referral:       'Referral',
    direct:         'Direct',
    other:          'Other Tagged',
    not_tracked:    'Not Tracked',
};

const CHANNEL_TYPE_COLORS: Record<string, string> = {
    paid_social:    '#3b82f6',
    paid_search:    '#8b5cf6',
    organic_search: '#16a34a',
    organic_social: '#ec4899',
    email:          '#f59e0b',
    sms:            '#06b6d4',
    affiliate:      '#f97316',
    referral:       '#64748b',
    direct:         '#a1a1aa',
    other:          '#78716c',
    not_tracked:    '#d4d4d8',
};

function channelLabel(row: ChannelRow): string {
    if (row.channel_name && row.channel_name !== 'Not Tracked') {
        return row.channel_name;
    }
    return CHANNEL_TYPE_LABELS[row.channel_type] ?? row.channel_type;
}

function StatCell({ label, value, colorClass }: { label: string; value: string; colorClass?: string }) {
    return (
        <div className="flex-1 px-3 py-3 text-right">
            <div className="text-[10px] uppercase tracking-wide text-zinc-400">{label}</div>
            <div className={cn('text-sm font-medium tabular-nums text-zinc-700', colorClass)}>{value}</div>
        </div>
    );
}

// ── Main page ───────────────────────────────────────────────────────────────

export default function Acquisition(props: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const [navigating, setNavigating] = useState(() => _inertiaNavigating);

    const { channels, hero, store_ids } = props;

    useEffect(() => {
        const off1 = router.on('start',  () => setNavigating(true));
        const off2 = router.on('finish', () => setNavigating(false));
        return () => { off1(); off2(); };
    }, []);

    // ── Empty state ─────────────────────────────────────────────────────────
    if (!navigating && channels.length === 0) {
        return (
            <AppLayout dateRangePicker={<DateRangePicker />}>
                <Head title="Acquisition" />
                <PageHeader title="Acquisition" subtitle="Traffic sources that bring orders" />
                <StoreFilter selectedStoreIds={store_ids} />
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

    const total = channels.reduce((s, c) => s + c.revenue, 0);

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title="Acquisition" />
            <PageHeader title="Acquisition" subtitle="Which traffic sources bring me orders, not just visitors?" />
            <StoreFilter selectedStoreIds={store_ids} />

            {/* ── Hero cards ─────────────────────────────────────────────────── */}
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
                    tooltip="Revenue minus COGS minus ad spend across all channels."
                />
                <MetricCard
                    label="Attribution Coverage"
                    source="real"
                    value={hero.coverage_pct != null ? `${hero.coverage_pct}%` : 'N/A'}
                    subtext={`${hero.attributed_orders} of ${hero.total_orders} orders`}
                    loading={navigating}
                    tooltip="Orders attributed to a known marketing channel. Direct counts as attributed — it means PYS detected no referrer source (bookmark, type-in). Only 'Not Tracked' orders (no PYS data) lower this number."
                />
            </div>

            {/* ── Channels: bar + full stats ──────────────────────────────────── */}
            {channels.length > 0 && (
                <div className="rounded-xl border border-zinc-200 bg-white divide-y divide-zinc-100">
                    {/* header row */}
                    <div className="flex items-center text-[10px] uppercase tracking-wide text-zinc-400">
                        <div className="flex-1 px-4 py-2">Channel</div>
                        <div className="flex flex-[1] shrink-0 border-l border-zinc-100 divide-x divide-zinc-100">
                            <div className="flex-1 px-3 py-2 text-right">Clicks</div>
                            <div className="flex-1 px-3 py-2 text-right">Orders</div>
                            <div className="flex-1 px-3 py-2 text-right">CVR</div>
                            <div className="flex-1 px-3 py-2 text-right">Revenue</div>
                            <div className="flex-1 px-3 py-2 text-right">Real Profit</div>
                        </div>
                    </div>
                    {channels.map((ch, i) => {
                        const pct   = total > 0 ? Math.round((ch.revenue / total) * 1000) / 10 : 0;
                        const color = CHANNEL_TYPE_COLORS[ch.channel_type] ?? '#a1a1aa';
                        const profitClass = ch.real_profit != null
                            ? ch.real_profit >= 0 ? 'text-green-700' : 'text-red-600'
                            : undefined;
                        return (
                            <div key={`${ch.channel_type}-${ch.channel_name}`} className="flex items-center hover:bg-zinc-50 transition-colors">
                                {/* 2/3 — bar */}
                                <div className="flex flex-1 items-center gap-3 px-4 py-3 min-w-0">
                                    <span className="w-5 shrink-0 text-xs text-zinc-300 font-medium text-right">{i + 1}</span>
                                    <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: color }} />
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-1.5">
                                            <span className="text-sm text-zinc-800 font-medium">{channelLabel(ch)}</span>
                                            {ch.wl_tag === 'winner' && (
                                                <span className="rounded-full bg-green-50 px-1.5 py-0.5 text-[10px] font-medium text-green-700 border border-green-200">W</span>
                                            )}
                                            {ch.wl_tag === 'loser' && (
                                                <span className="rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-medium text-red-600 border border-red-200">L</span>
                                            )}
                                        </div>
                                        <div className="mt-1 h-1.5 w-full rounded-full bg-zinc-100">
                                            <div className="h-full rounded-full" style={{ width: `${pct}%`, backgroundColor: color }} />
                                        </div>
                                    </div>
                                    <span className="shrink-0 text-xs text-zinc-400 tabular-nums">{pct}%</span>
                                </div>
                                {/* 1/3 — stats */}
                                <div className="flex flex-[1] shrink-0 border-l border-zinc-100 divide-x divide-zinc-100">
                                    <StatCell
                                        label="Clicks"
                                        value={ch.clicks != null ? formatNumber(ch.clicks) : '—'}
                                    />
                                    <StatCell
                                        label="Orders"
                                        value={formatNumber(ch.orders)}
                                    />
                                    <StatCell
                                        label="CVR"
                                        value={ch.cvr != null ? `${ch.cvr}%` : '—'}
                                    />
                                    <StatCell
                                        label="Revenue"
                                        value={formatCurrency(ch.revenue, currency, true)}
                                    />
                                    <StatCell
                                        label="Real Profit"
                                        value={ch.real_profit != null ? formatCurrency(ch.real_profit, currency, true) : '—'}
                                        colorClass={profitClass}
                                    />
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </AppLayout>
    );
}
