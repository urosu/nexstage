import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level lets us start with
// navigating=true so the skeleton stays visible until the real data is ready.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import { BarChart2, Table2, Grid2X2 } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { MetricCard } from '@/Components/shared/MetricCard';
import { CampaignsTabBar } from '@/Components/shared/CampaignsTabBar';
import { StatusBadge } from '@/Components/shared/StatusBadge';
import { SortButton } from '@/Components/shared/SortButton';
import { PlatformBadge } from '@/Components/shared/PlatformBadge';
import { ToggleGroup } from '@/Components/shared/ToggleGroup';
import { WlFilterBar, classifierTooltip } from '@/Components/shared/WlFilterBar';
import type { WlClassifier, WlFilter } from '@/Components/shared/WlFilterBar';
import {
    LineChart as RechartsLineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip as RechartsTooltip,
    ResponsiveContainer,
} from 'recharts';
import { QuadrantChart, type QuadrantCampaign } from '@/Components/charts/QuadrantChart';
import { formatCurrency, formatDate, formatNumber, type Granularity } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { syncDotClass, syncDotTitle } from '@/lib/syncStatus';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface CampaignMetrics {
    roas: number | null;
    cpo: number | null;
    spend: number | null;
    revenue: number | null;
    attributed_revenue: number | null;
    attributed_orders: number;
    real_roas: number | null;
    real_cpo: number | null;
    impressions: number;
    clicks: number;
    ctr: number | null;
    cpc: number | null;
}

interface CampaignRow {
    id: number;
    name: string;
    platform: string;
    status: string | null;
    spend: number;
    impressions: number;
    clicks: number;
    ctr: number | null;
    cpc: number | null;
    platform_roas: number | null;
    real_roas: number | null;
    real_cpo: number | null;
    attributed_revenue: number | null;
    attributed_orders: number;
    spend_velocity: number | null;
    target_roas: number | null;
    wl_tag: 'winner' | 'loser' | null;
}

interface PlatformBreakdownEntry {
    spend: number | null;
    impressions: number;
    clicks: number;
    ctr: number | null;
}

interface SpendChartPoint {
    date: string;
    facebook: number;
    google: number;
}

interface AdAccountOption {
    id: number;
    platform: string;
    name: string;
    status: string;
    last_synced_at: string | null;
}

interface Props {
    has_ad_accounts: boolean;
    ad_accounts: AdAccountOption[];
    ad_account_ids: number[];
    metrics: CampaignMetrics | null;
    compare_metrics: CampaignMetrics | null;
    campaigns: CampaignRow[];
    campaigns_total_count: number;
    platform_breakdown: Record<string, PlatformBreakdownEntry>;
    chart_data: SpendChartPoint[];
    compare_chart_data: SpendChartPoint[] | null;
    total_revenue: number | null;
    unattributed_revenue: number | null;
    not_tracked_pct: number | null;
    workspace_target_roas: number | null;
    // W/L classifier props from server
    wl_has_target: boolean;
    active_classifier: 'target' | 'peer' | 'period';
    wl_peer_avg_roas: number | null;
    filter: WlFilter;
    classifier: WlClassifier | null;
    from: string;
    to: string;
    compare_from: string | null;
    compare_to: string | null;
    granularity: Granularity;
    platform: 'all' | 'facebook' | 'google';
    status: 'all' | 'active' | 'paused';
    view: 'table' | 'quadrant';
    sort: string;
    direction: 'asc' | 'desc';
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function pctChange(current: number | null, previous: number | null): number | null {
    if (current === null || previous === null || previous === 0) return null;
    return ((current - previous) / previous) * 100;
}


// ─── Spend velocity badge ─────────────────────────────────────────────────────
// velocity = (spend / budget_for_period) / (days_elapsed / days_in_period)
// 1.0 = on pace, >1.0 = pacing fast, <1.0 = pacing slow

function SpendVelocityBadge({ velocity }: { velocity: number | null }) {
    if (velocity === null) return <span className="text-zinc-400">—</span>;

    const pct     = Math.round(velocity * 100);
    const isHigh  = velocity > 1.15;
    const isLow   = velocity < 0.85;
    const color   = isHigh ? 'text-amber-700' : isLow ? 'text-blue-600' : 'text-green-700';

    return (
        <span className={cn('tabular-nums font-medium', color)} title={`${pct}% of expected pacing`}>
            {pct}%
        </span>
    );
}


// ─── Spend chart (multi-platform lines) ──────────────────────────────────────

const SPEND_PLATFORMS = [
    { dataKey: 'facebook' as const, name: 'Facebook', color: 'var(--chart-1)' },
    { dataKey: 'google'   as const, name: 'Google',   color: 'var(--chart-4)' },
];

function SpendChart({
    chartData,
    granularity,
    currency,
    navigating,
}: {
    chartData: SpendChartPoint[];
    granularity: Granularity;
    currency: string;
    navigating: boolean;
}) {
    const activePlatforms = useMemo(
        () => SPEND_PLATFORMS.filter((p) => chartData.some((d) => (d[p.dataKey] ?? 0) > 0)),
        [chartData],
    );

    const [visible, setVisible] = useState<Set<string>>(() => new Set(activePlatforms.map((p) => p.dataKey)));

    useEffect(() => {
        setVisible(new Set(activePlatforms.map((p) => p.dataKey)));
    }, [activePlatforms.map((p) => p.dataKey).join(',')]);

    function toggle(key: string) {
        setVisible((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                if (next.size === 1) return prev;
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    }

    return (
        <div className="mb-6 rounded-xl border border-zinc-200 bg-white p-5">
            <div className="mb-1 text-sm font-medium text-zinc-500">Daily ad spend</div>
            {navigating ? (
                <div className="h-64 w-full animate-pulse rounded-lg bg-zinc-100" />
            ) : chartData.length === 0 ? (
                <div className="flex h-64 flex-col items-center justify-center gap-2">
                    <p className="text-sm text-zinc-400">No spend data for this period.</p>
                </div>
            ) : (
                <>
                    {activePlatforms.length > 1 && (
                        <div className="mb-3 flex flex-wrap gap-1.5">
                            {activePlatforms.map((p) => {
                                const on = visible.has(p.dataKey);
                                return (
                                    <button
                                        key={p.dataKey}
                                        onClick={() => toggle(p.dataKey)}
                                        className={cn(
                                            'flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                                            !on && 'border-zinc-200 bg-white text-zinc-400 hover:text-zinc-600',
                                        )}
                                        style={on ? { backgroundColor: p.color, borderColor: p.color, color: 'white' } : undefined}
                                    >
                                        <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: on ? 'white' : p.color }} />
                                        {p.name}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    <ResponsiveContainer width="100%" height={240}>
                        <RechartsLineChart data={chartData} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" vertical={false} />
                            <XAxis
                                dataKey="date"
                                tick={{ fontSize: 11, fill: '#a1a1aa' }}
                                tickFormatter={(d) => formatDate(d, granularity)}
                                tickLine={false}
                                axisLine={false}
                                minTickGap={40}
                            />
                            <YAxis
                                tick={{ fontSize: 11, fill: '#a1a1aa' }}
                                tickFormatter={(v) => formatCurrency(v, currency, true)}
                                tickLine={false}
                                axisLine={false}
                                width={60}
                                domain={[0, 'auto']}
                            />
                            <RechartsTooltip
                                contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e4e4e7' }}
                                formatter={(value: unknown, key: unknown) => {
                                    const p = SPEND_PLATFORMS.find((s) => s.dataKey === String(key));
                                    return [formatCurrency(Number(value), currency), p?.name ?? String(key)] as [string, string];
                                }}
                                labelFormatter={(label) => formatDate(String(label), granularity)}
                            />
                            {activePlatforms.map((p) =>
                                visible.has(p.dataKey) ? (
                                    <Line
                                        key={p.dataKey}
                                        type="monotone"
                                        dataKey={p.dataKey}
                                        stroke={p.color}
                                        strokeWidth={2}
                                        dot={false}
                                        connectNulls
                                    />
                                ) : null,
                            )}
                        </RechartsLineChart>
                    </ResponsiveContainer>
                </>
            )}
        </div>
    );
}

// ─── Campaign table ───────────────────────────────────────────────────────────

function CampaignTable({
    campaigns,
    currency,
    sort,
    direction,
    onSort,
    from,
    to,
    workspaceSlug,
    targetRoas,
}: {
    campaigns: CampaignRow[];
    currency: string;
    sort: string;
    direction: 'asc' | 'desc';
    onSort: (col: string) => void;
    from: string;
    to: string;
    workspaceSlug: string | undefined;
    targetRoas: number | null;
}) {
    const sortBtn = (col: string, label: string) => (
        <SortButton col={col} label={label} currentSort={sort} currentDir={direction} onSort={onSort} />
    );

    return (
        <div className="rounded-xl border border-zinc-200 bg-white">
            <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4">
                <div className="text-sm font-medium text-zinc-500">
                    Campaigns
                    {campaigns.length > 0 && (
                        <span className="ml-2 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500">
                            {campaigns.length}
                        </span>
                    )}
                </div>
            </div>

            {campaigns.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-16 text-center">
                    <p className="text-sm text-zinc-400">No campaign data for this period.</p>
                    <p className="mt-1 text-xs text-zinc-400">Data appears after the next sync completes.</p>
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            {/* Platform ROAS is adjacent to Real ROAS so the contrast is immediately visible.
                                This side-by-side comparison is the primary trust-building moment:
                                "Facebook says 4.2× / Store says 2.8×" on the same row.
                                See: PLANNING.md "Platform ROAS vs Real ROAS" */}
                            <tr className="text-left th-label">
                                <th className="px-5 py-3">Campaign</th>
                                <th className="px-5 py-3">Platform</th>
                                <th className="px-5 py-3">Status</th>
                                <th className="px-5 py-3 text-right">
                                    {sortBtn('spend', 'Spend')}
                                </th>
                                {/* Platform ROAS next to Real ROAS — the visual contrast is the point */}
                                <th className="px-5 py-3 text-right">
                                    <span className="inline-flex items-center gap-1">
                                        {sortBtn('platform_roas', 'Platform ROAS')}
                                        <a
                                            href="/help/data-accuracy#roas"
                                            className="text-zinc-300 hover:text-zinc-500 transition-colors"
                                            title="What Meta/Google report using pixel-based attribution. Typically higher than Real ROAS due to iOS14+ modeled conversions and cross-platform double-counting."
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            ⓘ
                                        </a>
                                    </span>
                                </th>
                                <th className="px-5 py-3 text-right">
                                    <span title="UTM-attributed revenue ÷ ad spend. Calculated from your actual orders, not platform pixel attribution. Requires UTM parameters on your ad links.">
                                        {sortBtn('real_roas', 'Real ROAS')}
                                    </span>
                                </th>
                                <th className="px-5 py-3 text-right">
                                    <span title="Ad spend ÷ UTM-attributed orders">
                                        {sortBtn('real_cpo', 'Real CPO')}
                                    </span>
                                </th>
                                <th className="px-5 py-3 text-right">
                                    {sortBtn('attributed_revenue', 'Attr. Revenue')}
                                </th>
                                <th className="px-5 py-3 text-right">{sortBtn('attributed_orders', 'Attr. Orders')}</th>
                                <th className="px-5 py-3 text-right">
                                    <span title="Spend velocity: how fast this campaign is burning through its budget vs expected daily pace. 100% = on pace. Requires a daily or lifetime budget set on the campaign.">
                                        {sortBtn('spend_velocity', 'Velocity')}
                                    </span>
                                </th>
                                <th className="px-5 py-3 text-right">{sortBtn('impressions', 'Impressions')}</th>
                                <th className="px-5 py-3 text-right">{sortBtn('clicks', 'Clicks')}</th>
                                <th className="px-5 py-3 text-right">{sortBtn('ctr', 'CTR')}</th>
                                <th className="px-5 py-3 text-right">{sortBtn('cpc', 'CPC')}</th>
                                <th className="px-5 py-3 text-right">Drill →</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {campaigns.map((c) => (
                                <tr key={c.id} className="hover:bg-zinc-50">
                                    <td className="max-w-[200px] px-5 py-3">
                                        <Link
                                            href={wurl(workspaceSlug, `/campaigns/adsets?campaign_id=${c.id}&from=${from}&to=${to}`)}
                                            className="block truncate font-medium text-zinc-800 hover:text-primary transition-colors"
                                            title={c.name}
                                        >
                                            {c.name || '—'}
                                        </Link>
                                    </td>
                                    <td className="px-5 py-3">
                                        <PlatformBadge platform={c.platform} />
                                    </td>
                                    <td className="px-5 py-3">
                                        {c.status ? <StatusBadge status={c.status} /> : <span className="text-zinc-400">—</span>}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-zinc-700">
                                        {c.spend > 0 ? formatCurrency(c.spend, currency) : 'N/A'}
                                    </td>
                                    {/* Platform ROAS — shown in muted blue to distinguish from Real ROAS.
                                        Adjacent columns make the gap between platform-reported and store-verified
                                        ROAS immediately visible without needing a tooltip. */}
                                    <td className="px-5 py-3 text-right tabular-nums">
                                        {c.platform_roas != null ? (
                                            <span className="text-blue-600 font-medium">
                                                {c.platform_roas.toFixed(2)}×
                                            </span>
                                        ) : (
                                            <span className="text-zinc-400">—</span>
                                        )}
                                    </td>
                                    {/* Real ROAS — green/red vs workspace target (same threshold as the MetricCard above). */}
                                    <td className="px-5 py-3 text-right tabular-nums font-medium">
                                        {c.real_roas != null ? (
                                            <span className={c.real_roas >= (targetRoas ?? 1) ? 'text-green-700' : 'text-red-600'}>
                                                {c.real_roas.toFixed(2)}×
                                            </span>
                                        ) : (
                                            <span className="text-zinc-400" title="No UTM-matched orders — add UTM parameters to your ad links to enable Real ROAS tracking.">—</span>
                                        )}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-zinc-700">
                                        {c.real_cpo != null ? formatCurrency(c.real_cpo, currency) : <span className="text-zinc-400">—</span>}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-zinc-700">
                                        {c.attributed_revenue != null ? formatCurrency(c.attributed_revenue, currency) : <span className="text-zinc-400">—</span>}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-zinc-700">
                                        {c.attributed_orders > 0 ? formatNumber(c.attributed_orders) : <span className="text-zinc-400">—</span>}
                                    </td>
                                    <td className="px-5 py-3 text-right">
                                        <SpendVelocityBadge velocity={c.spend_velocity} />
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-zinc-700">
                                        {formatNumber(c.impressions)}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-zinc-700">
                                        {formatNumber(c.clicks)}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-zinc-700">
                                        {c.ctr != null ? `${c.ctr.toFixed(2)}%` : 'N/A'}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-zinc-700">
                                        {c.cpc != null ? formatCurrency(c.cpc, currency) : 'N/A'}
                                    </td>
                                    <td className="px-5 py-3 text-right">
                                        <Link
                                            href={wurl(workspaceSlug, `/campaigns/adsets?campaign_id=${c.id}&from=${from}&to=${to}`)}
                                            className="text-xs text-zinc-400 hover:text-primary transition-colors"
                                            title="View ad sets for this campaign"
                                        >
                                            Ad Sets →
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function CampaignsIndex(props: Props) {
    const { workspace, auth } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';

    function navigate(params: Record<string, string | undefined>) {
        router.get(wurl(workspace?.slug, '/campaigns'), params as Record<string, string>, { preserveState: true, replace: true });
    }

    const {
        has_ad_accounts,
        ad_accounts,
        ad_account_ids,
        metrics,
        compare_metrics,
        campaigns,
        campaigns_total_count,
        chart_data,
        total_revenue,
        unattributed_revenue,
        not_tracked_pct,
        workspace_target_roas,
        wl_has_target,
        active_classifier,
        wl_peer_avg_roas,
        filter,
        classifier,
        from,
        to,
        compare_from,
        compare_to,
        granularity,
        platform,
        status,
        view,
        sort,
        direction,
    } = props;

    const [navigating, setNavigating] = useState(() => _inertiaNavigating);

    // ── Quadrant ROAS type toggle — local state, no server round-trip needed ──
    // Both real_roas and platform_roas are already in the campaign rows.
    const [roasType, setRoasType] = useState<'real' | 'platform'>('real');

    useEffect(() => {
        const off1 = router.on('start',  () => setNavigating(true));
        const off2 = router.on('finish', () => setNavigating(false));
        return () => { off1(); off2(); };
    }, []);

    const changes = useMemo(() => ({
        roas:    pctChange(metrics?.roas    ?? null, compare_metrics?.roas    ?? null),
        cpo:     pctChange(metrics?.cpo     ?? null, compare_metrics?.cpo     ?? null),
        spend:   pctChange(metrics?.spend   ?? null, compare_metrics?.spend   ?? null),
        revenue: pctChange(metrics?.revenue ?? null, compare_metrics?.revenue ?? null),
    }), [metrics, compare_metrics]);

    // ── Param helpers ────────────────────────────────────────────────────────
    const currentParams = useMemo(() => ({
        from, to,
        ...(compare_from              ? { compare_from }                              : {}),
        ...(compare_to                ? { compare_to }                                : {}),
        ...(ad_account_ids.length > 0 ? { ad_account_ids: ad_account_ids.join(',') } : {}),
        granularity,
        platform,
        status,
        view,
        sort,
        direction,
        ...(filter !== 'all'     ? { filter }     : {}),
        ...(classifier !== null  ? { classifier } : {}),
    }), [from, to, compare_from, compare_to, ad_account_ids, granularity, platform, status, view, sort, direction, filter, classifier]);

    function setPlatform(v: 'all' | 'facebook' | 'google') {
        // Clear ad account filter when switching platforms
        const { ad_account_ids: _removed, ...rest } = currentParams;
        navigate({ ...rest, platform: v });
    }
    function setStatus(v: 'all' | 'active' | 'paused') {
        navigate({ ...currentParams, status: v });
    }
    function toggleAdAccount(id: number) {
        const next = ad_account_ids.includes(id)
            ? ad_account_ids.filter((x) => x !== id)
            : [...ad_account_ids, id];
        const { ad_account_ids: _removed, ...rest } = currentParams;
        navigate(next.length > 0 ? { ...rest, ad_account_ids: next.join(',') } : rest);
    }
    function setView(v: 'table' | 'quadrant') {
        navigate({ ...currentParams, view: v });
    }
    function setSort(col: string) {
        const newDir = sort === col && direction === 'desc' ? 'asc' : 'desc';
        navigate({ ...currentParams, sort: col, direction: newDir });
    }

    // ── Empty state ──────────────────────────────────────────────────────────
    if (!has_ad_accounts) {
        return (
            <AppLayout dateRangePicker={<DateRangePicker />}>
                <Head title="Campaigns" />
                <PageHeader title="Campaigns" subtitle="Cross-platform ad performance" />
                <CampaignsTabBar />
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100">
                        <BarChart2 className="h-6 w-6 text-zinc-400" />
                    </div>
                    <h3 className="mb-1 text-base font-semibold text-zinc-900">No ad accounts connected</h3>
                    <p className="mb-5 max-w-xs text-sm text-zinc-500">
                        Connect a Facebook or Google Ads account to view campaign performance and ROAS.
                    </p>
                    <Link
                        href="/settings/integrations"
                        className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        Connect ad accounts →
                    </Link>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title="Campaigns" />
            <PageHeader title="Campaigns" subtitle="Cross-platform ad performance" />
            <CampaignsTabBar />

            {/* ── Ad account pills — one row per platform ── */}
            {(['facebook', 'google'] as const).map((plat) => {
                const accounts = ad_accounts.filter((a) => a.platform === plat);
                if (accounts.length === 0) return null;
                return (
                    <div key={plat} className="mb-3 flex flex-wrap items-center gap-2">
                        <span className="text-xs font-medium text-zinc-400 shrink-0">
                            {plat === 'facebook' ? 'Facebook' : 'Google'}
                        </span>
                        {accounts.length > 1 && (
                            <button
                                onClick={() => {
                                    // Deselect all accounts for this platform
                                    const otherIds = ad_account_ids.filter((id) => !accounts.some((a) => a.id === id));
                                    const { ad_account_ids: _r, ...rest } = currentParams;
                                    navigate(otherIds.length > 0 ? { ...rest, ad_account_ids: otherIds.join(',') } : rest);
                                }}
                                className={cn(
                                    'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                    accounts.every((a) => !ad_account_ids.includes(a.id))
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                                )}
                            >
                                All
                            </button>
                        )}
                        {accounts.map((a) => (
                            <button
                                key={a.id}
                                onClick={() => toggleAdAccount(a.id)}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                    ad_account_ids.includes(a.id)
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                                )}
                                title={`${a.name} — ${syncDotTitle(a.status, a.last_synced_at)}`}
                            >
                                <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', syncDotClass(a.status, a.last_synced_at, 'ad_account'))} />
                                {a.name}
                            </button>
                        ))}
                    </div>
                );
            })}

            {/* ── Filter bar ── */}
            <div className="mb-6 flex flex-wrap items-center gap-3">
                <ToggleGroup
                    options={[
                        { label: 'All', value: 'all' },
                        { label: 'Facebook', value: 'facebook' },
                        { label: 'Google', value: 'google' },
                    ]}
                    value={platform}
                    onChange={setPlatform}
                />
                <ToggleGroup
                    options={[
                        { label: 'All status', value: 'all' },
                        { label: 'Active', value: 'active' },
                        { label: 'Paused', value: 'paused' },
                    ]}
                    value={status}
                    onChange={setStatus}
                />

                <div className="ml-auto flex items-center gap-1 rounded-lg border border-zinc-200 bg-zinc-50 p-0.5">
                    <button
                        onClick={() => setView('table')}
                        title="Table view"
                        className={cn(
                            'rounded-md p-1.5 transition-colors',
                            view === 'table' ? 'bg-white shadow-sm text-zinc-800' : 'text-zinc-400 hover:text-zinc-600',
                        )}
                    >
                        <Table2 className="h-4 w-4" />
                    </button>
                    <button
                        onClick={() => setView('quadrant')}
                        title="Quadrant view"
                        className={cn(
                            'rounded-md p-1.5 transition-colors',
                            view === 'quadrant' ? 'bg-white shadow-sm text-zinc-800' : 'text-zinc-400 hover:text-zinc-600',
                        )}
                    >
                        <Grid2X2 className="h-4 w-4" />
                    </button>
                </div>

                {/* Winners / Losers chips — server-side filtered.
                    Classifier (target/peer/period) determines the threshold.
                    See: PLANNING.md section 15 (Winners/Losers classifier) */}
                <WlFilterBar
                    filter={filter}
                    totalCount={campaigns_total_count}
                    filteredCount={campaigns.length}
                    activeClassifier={active_classifier}
                    hasTarget={wl_has_target}
                    targetRoas={workspace_target_roas}
                    peerAvgRoas={wl_peer_avg_roas}
                    allLabel="Show all campaigns"
                    onFilterChange={f => navigate({ ...currentParams, ...(f !== 'all' ? { filter: f } : { filter: undefined }) })}
                    onClassifierChange={c => navigate({ ...currentParams, classifier: c })}
                />
            </div>

            {/* ── Hero cards — per PLANNING 12.5 /campaigns spec ── */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <MetricCard
                    label="Total Ad Spend"
                    source="real"
                    value={metrics?.spend != null ? formatCurrency(metrics.spend, currency) : null}
                    change={changes.spend}
                    invertTrend
                    loading={navigating}
                    tooltip="Total ad spend reported by the platform for the selected period, converted to your reporting currency."
                />
                <MetricCard
                    label="Real ROAS"
                    source="real"
                    value={metrics?.real_roas != null ? `${metrics.real_roas.toFixed(2)}×` : null}
                    target={workspace_target_roas}
                    targetDirection="above"
                    change={pctChange(
                        metrics?.real_roas ?? null,
                        compare_metrics?.real_roas ?? null,
                    )}
                    loading={navigating}
                    tooltip="UTM-attributed revenue divided by ad spend. Calculated from your actual store orders, not platform pixel attribution."
                />
                <MetricCard
                    label="Real CPO"
                    source="real"
                    value={metrics?.real_cpo != null ? formatCurrency(metrics.real_cpo, currency) : null}
                    invertTrend
                    change={pctChange(
                        metrics?.real_cpo ?? null,
                        compare_metrics?.real_cpo ?? null,
                    )}
                    loading={navigating}
                    tooltip="Ad spend divided by the number of UTM-attributed orders. Lower is better."
                />
                <MetricCard
                    label="Not Tracked"
                    source="real"
                    value={not_tracked_pct != null ? `${not_tracked_pct.toFixed(1)}%` : null}
                    loading={navigating}
                    subtext={unattributed_revenue != null ? formatCurrency(unattributed_revenue, currency) : undefined}
                    tooltip="Revenue not tracked by any ad platform. Includes organic search, direct, email, affiliates, and untagged traffic. When negative, platforms over-reported — usually due to iOS14+ modeled conversions."
                />
            </div>

            {/* ── Table view ── */}
            {view === 'table' && (
                <>
                    <SpendChart
                        chartData={chart_data}
                        granularity={granularity}
                        currency={currency}
                        navigating={navigating}
                    />
                    <CampaignTable
                        campaigns={campaigns}
                        currency={currency}
                        sort={sort}
                        direction={direction}
                        onSort={setSort}
                        from={from}
                        to={to}
                        workspaceSlug={workspace?.slug}
                        targetRoas={workspace_target_roas ?? null}
                    />
                </>
            )}

            {/* ── Quadrant view ── */}
            {view === 'quadrant' && (() => {
                // Filter out campaigns with no ROAS signal — quadrant needs a Y value to be useful.
                // They're still visible in the table view.
                const allMapped: QuadrantCampaign[] = campaigns.map(c => ({
                    id:                 c.id,
                    name:               c.name,
                    platform:           c.platform,
                    spend:              c.spend,
                    real_roas:          roasType === 'real' ? c.real_roas : c.platform_roas,
                    attributed_revenue: roasType === 'real' ? c.attributed_revenue : null,
                    attributed_orders:  roasType === 'real' ? c.attributed_orders  : 0,
                }));
                const quadrantData = allMapped.filter(c => c.real_roas !== null);
                const hiddenCount  = allMapped.length - quadrantData.length;
                return (
                    <div className="rounded-xl border border-zinc-200 bg-white p-5">
                        <div className="mb-3 flex items-center justify-between">
                            <div>
                                <div className="text-sm font-medium text-zinc-500">Performance quadrant</div>
                                <p className="mt-0.5 text-xs text-zinc-400">
                                    Each bubble is one campaign. X = ad spend (log), Y = ROAS, bubble size = attributed revenue.
                                </p>
                            </div>
                            {/* ROAS source toggle — Real uses UTM attribution; Platform uses ad platform's own reporting */}
                            <div className="inline-flex rounded-lg border border-zinc-200 bg-zinc-50 p-0.5 shrink-0">
                                <button
                                    onClick={() => setRoasType('real')}
                                    className={cn(
                                        'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                                        roasType === 'real' ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-400 hover:text-zinc-600',
                                    )}
                                >
                                    Real ROAS
                                </button>
                                <button
                                    onClick={() => setRoasType('platform')}
                                    className={cn(
                                        'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                                        roasType === 'platform' ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-400 hover:text-zinc-600',
                                    )}
                                >
                                    Platform ROAS
                                </button>
                            </div>
                        </div>
                        {navigating ? (
                            <div className="h-[460px] w-full animate-pulse rounded-lg bg-zinc-100" />
                        ) : (
                            <QuadrantChart
                                campaigns={quadrantData}
                                currency={currency}
                                targetRoas={workspace_target_roas ?? 1.5}
                                yLabel={roasType === 'real' ? 'Real ROAS' : 'Platform ROAS'}
                                hiddenCount={hiddenCount}
                                hiddenLabel="campaigns"
                            />
                        )}
                    </div>
                );
            })()}
        </AppLayout>
    );
}
