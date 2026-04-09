import React, { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { BarChart2, Table2, Grid2X2 } from 'lucide-react';
import {
    BarChart as RechartsBarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
} from 'recharts';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { MetricCard } from '@/Components/shared/MetricCard';
import { QuadrantChart } from '@/Components/charts/QuadrantChart';
import { formatCurrency, formatDate, formatNumber, type Granularity } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface CampaignMetrics {
    roas: number | null;
    cpo: number | null;
    spend: number | null;
    revenue: number | null;
    attributed_revenue: number | null;
    attributed_orders: number;
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
}

interface Props {
    has_ad_accounts: boolean;
    ad_accounts: AdAccountOption[];
    ad_account_id: number | null;
    metrics: CampaignMetrics | null;
    compare_metrics: CampaignMetrics | null;
    campaigns: CampaignRow[];
    platform_breakdown: Record<string, PlatformBreakdownEntry>;
    chart_data: SpendChartPoint[];
    compare_chart_data: SpendChartPoint[] | null;
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

function navigate(params: Record<string, string | undefined>) {
    router.get('/campaigns', params as Record<string, string>, { preserveState: true, replace: true });
}

// ─── Toggle button group ──────────────────────────────────────────────────────

function ToggleGroup<T extends string>({
    options,
    value,
    onChange,
}: {
    options: { label: string; value: T }[];
    value: T;
    onChange: (v: T) => void;
}) {
    return (
        <div className="inline-flex rounded-lg border border-zinc-200 bg-zinc-50 p-0.5">
            {options.map((opt) => (
                <button
                    key={opt.value}
                    onClick={() => onChange(opt.value)}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                        value === opt.value
                            ? 'bg-white text-zinc-900 shadow-sm'
                            : 'text-zinc-500 hover:text-zinc-700',
                    )}
                >
                    {opt.label}
                </button>
            ))}
        </div>
    );
}

// ─── Platform badge ───────────────────────────────────────────────────────────

const PLATFORM_COLORS: Record<string, string> = {
    facebook: 'bg-blue-50 text-blue-700',
    google:   'bg-red-50 text-red-700',
};

function PlatformBadge({ platform }: { platform: string }) {
    return (
        <span className={cn(
            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize',
            PLATFORM_COLORS[platform] ?? 'bg-zinc-100 text-zinc-500',
        )}>
            {platform}
        </span>
    );
}

// ─── Status badge ─────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: string | null }) {
    if (!status) return <span className="text-zinc-400">—</span>;
    const normalized = status.toLowerCase();
    const isActive   = ['active', 'enabled', 'delivering'].some((s) => normalized.includes(s));
    const isPaused   = normalized.includes('paused') || normalized.includes('inactive');
    return (
        <span className={cn(
            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
            isActive ? 'bg-green-50 text-green-700' : isPaused ? 'bg-zinc-100 text-zinc-500' : 'bg-zinc-100 text-zinc-500',
        )}>
            {status}
        </span>
    );
}

// ─── Sort button ──────────────────────────────────────────────────────────────

function SortButton({
    col,
    label,
    currentSort,
    currentDir,
    onSort,
}: {
    col: string;
    label: string;
    currentSort: string;
    currentDir: 'asc' | 'desc';
    onSort: (col: string) => void;
}) {
    const active = currentSort === col;
    return (
        <button
            onClick={() => onSort(col)}
            className={cn('flex items-center gap-1 hover:text-zinc-700 transition-colors', active ? 'text-indigo-600' : 'text-zinc-400')}
        >
            {label}
            {active && <span className="text-[10px]">{currentDir === 'desc' ? '↓' : '↑'}</span>}
        </button>
    );
}

// ─── Spend chart (multi-platform stacked) ────────────────────────────────────

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
    const hasFacebook = useMemo(() => chartData.some((d) => d.facebook > 0), [chartData]);
    const hasGoogle = useMemo(() => chartData.some((d) => d.google > 0), [chartData]);

    return (
        <div className="mb-6 rounded-xl border border-zinc-200 bg-white p-5">
            <div className="mb-4 flex items-center justify-between">
                <div className="text-sm font-medium text-zinc-500">Daily ad spend</div>
                <div className="flex items-center gap-4 text-xs text-zinc-400">
                    {hasFacebook && (
                        <span className="flex items-center gap-1.5">
                            <span className="h-2 w-2 rounded-full bg-blue-500" />Facebook
                        </span>
                    )}
                    {hasGoogle && (
                        <span className="flex items-center gap-1.5">
                            <span className="h-2 w-2 rounded-full bg-red-500" />Google
                        </span>
                    )}
                </div>
            </div>
            {navigating ? (
                <div className="h-64 w-full animate-pulse rounded-lg bg-zinc-100" />
            ) : chartData.length === 0 ? (
                <div className="flex h-64 flex-col items-center justify-center gap-2">
                    <p className="text-sm text-zinc-400">No spend data for this period.</p>
                </div>
            ) : (
                <div className="h-64 w-full">
                    <ResponsiveContainer width="100%" height="100%">
                        <RechartsBarChart
                            data={chartData}
                            margin={{ top: 4, right: 8, left: 0, bottom: 0 }}
                            barCategoryGap="30%"
                            barGap={2}
                        >
                            <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" vertical={false} />
                            <XAxis
                                dataKey="date"
                                tickLine={false}
                                axisLine={false}
                                tick={{ fontSize: 11, fill: '#a1a1aa' }}
                                tickFormatter={(d) => formatDate(d, granularity)}
                                minTickGap={40}
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                tick={{ fontSize: 11, fill: '#a1a1aa' }}
                                tickFormatter={(v) => formatCurrency(v, currency, true)}
                                width={60}
                            />
                            <Tooltip
                                contentStyle={{
                                    fontSize: 12,
                                    borderRadius: 8,
                                    border: '1px solid #e4e4e7',
                                    boxShadow: '0 1px 8px rgba(0,0,0,0.08)',
                                }}
                                cursor={{ fill: '#f4f4f5' }}
                                formatter={(value: unknown, name: unknown) => [
                                    formatCurrency(Number(value), currency),
                                    String(name),
                                ]}
                                labelFormatter={(label) => formatDate(String(label), granularity)}
                            />
                            {hasFacebook && (
                                <Bar dataKey="facebook" name="Facebook" fill="#3b82f6" radius={[3, 3, 0, 0]} stackId="spend" />
                            )}
                            {hasGoogle && (
                                <Bar dataKey="google" name="Google" fill="#ef4444" radius={[3, 3, 0, 0]} stackId="spend" />
                            )}
                        </RechartsBarChart>
                    </ResponsiveContainer>
                </div>
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
}: {
    campaigns: CampaignRow[];
    currency: string;
    sort: string;
    direction: 'asc' | 'desc';
    onSort: (col: string) => void;
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
                            <tr className="text-left text-xs font-medium uppercase tracking-wide text-zinc-400">
                                <th className="px-5 py-3">Campaign</th>
                                <th className="px-5 py-3">Platform</th>
                                <th className="px-5 py-3">Status</th>
                                <th className="px-5 py-3 text-right">
                                    {sortBtn('spend', 'Spend')}
                                </th>
                                <th className="px-5 py-3 text-right">
                                    <span title="UTM-attributed revenue ÷ ad spend">
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
                                <th className="px-5 py-3 text-right">Attr. Orders</th>
                                <th className="px-5 py-3 text-right">Impressions</th>
                                <th className="px-5 py-3 text-right">Clicks</th>
                                <th className="px-5 py-3 text-right">CTR</th>
                                <th className="px-5 py-3 text-right">CPC</th>
                                <th className="px-5 py-3 text-right">
                                    <span title="Platform-reported ROAS (pixel-based)">Platform ROAS</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {campaigns.map((c) => (
                                <tr key={c.id} className="hover:bg-zinc-50">
                                    <td className="max-w-[200px] px-5 py-3">
                                        <span className="block truncate font-medium text-zinc-800" title={c.name}>
                                            {c.name || '—'}
                                        </span>
                                    </td>
                                    <td className="px-5 py-3">
                                        <PlatformBadge platform={c.platform} />
                                    </td>
                                    <td className="px-5 py-3">
                                        <StatusBadge status={c.status} />
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-zinc-700">
                                        {c.spend > 0 ? formatCurrency(c.spend, currency) : 'N/A'}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums font-medium">
                                        {c.real_roas != null ? (
                                            <span className={c.real_roas >= 1 ? 'text-green-700' : 'text-red-600'}>
                                                {c.real_roas.toFixed(2)}×
                                            </span>
                                        ) : (
                                            <span className="text-zinc-400">—</span>
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
                                    <td className="px-5 py-3 text-right tabular-nums text-zinc-500">
                                        {c.platform_roas != null ? `${c.platform_roas.toFixed(2)}×` : 'N/A'}
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
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';

    const {
        has_ad_accounts,
        ad_accounts,
        ad_account_id,
        metrics,
        compare_metrics,
        campaigns,
        chart_data,
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

    const [navigating, setNavigating] = useState(false);

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
        ...(compare_from    ? { compare_from }                      : {}),
        ...(compare_to      ? { compare_to }                        : {}),
        ...(ad_account_id   ? { ad_account_id: String(ad_account_id) } : {}),
        granularity,
        platform,
        status,
        view,
        sort,
        direction,
    }), [from, to, compare_from, compare_to, ad_account_id, granularity, platform, status, view, sort, direction]);

    function setPlatform(v: 'all' | 'facebook' | 'google') {
        // Clear ad account filter when switching platforms — the account may not belong to the new platform
        navigate({ ...currentParams, platform: v, ad_account_id: undefined });
    }
    function setStatus(v: 'all' | 'active' | 'paused') {
        navigate({ ...currentParams, status: v });
    }
    function setAdAccount(id: number | null) {
        const { ad_account_id: _removed, ...rest } = currentParams;
        navigate(id !== null ? { ...rest, ad_account_id: String(id) } : rest);
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
                        className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
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
                {ad_accounts.length > 1 && (
                    <select
                        value={ad_account_id ?? ''}
                        onChange={(e) => setAdAccount(e.target.value ? Number(e.target.value) : null)}
                        className="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-medium text-zinc-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 cursor-pointer"
                    >
                        <option value="">All accounts</option>
                        {ad_accounts
                            .filter((a) => platform === 'all' || a.platform === platform)
                            .map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.platform === 'facebook' ? 'FB' : 'G'}: {a.name}
                                </option>
                            ))}
                    </select>
                )}
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
            </div>

            {/* ── Metric cards ── */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <MetricCard
                    label="Blended ROAS"
                    value={metrics?.roas != null ? `${metrics.roas.toFixed(2)}×` : null}
                    change={changes.roas}
                    loading={navigating}
                    tooltip="Blended Return On Ad Spend. Total store revenue divided by total ad spend across all platforms — not limited to UTM-attributed orders."
                />
                <MetricCard
                    label="CPO"
                    value={metrics?.cpo != null ? formatCurrency(metrics.cpo, currency) : null}
                    change={changes.cpo}
                    invertTrend
                    loading={navigating}
                    tooltip="Cost Per Order. Ad spend divided by the number of orders attributed to this platform via UTM tracking. N/A when no orders have matching UTM parameters."
                />
                <MetricCard
                    label="Total Spend"
                    value={metrics?.spend != null ? formatCurrency(metrics.spend, currency) : null}
                    change={changes.spend}
                    invertTrend
                    loading={navigating}
                    tooltip="Total ad spend reported by the platform for the selected period, converted to your reporting currency."
                />
                <MetricCard
                    label="Attributed Revenue"
                    value={metrics?.attributed_revenue != null ? formatCurrency(metrics.attributed_revenue, currency) : null}
                    change={pctChange(
                        metrics?.attributed_revenue ?? null,
                        compare_metrics?.attributed_revenue ?? null,
                    )}
                    loading={navigating}
                    subtext="UTM-matched orders"
                    tooltip="Revenue from orders where utm_source matches this platform and utm_campaign matches a campaign name. Best-effort attribution — requires UTM parameters on your store links."
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
                    />
                </>
            )}

            {/* ── Quadrant view ── */}
            {view === 'quadrant' && (
                <div className="rounded-xl border border-zinc-200 bg-white p-5">
                    <div className="mb-2 text-sm font-medium text-zinc-500">Performance quadrant</div>
                    <p className="mb-5 text-xs text-zinc-400">
                        Each bubble is one campaign. X = ad spend, Y = real ROAS, bubble size = attributed revenue.
                        Campaigns without UTM attribution appear at ROAS = 0.
                    </p>
                    {navigating ? (
                        <div className="h-[460px] w-full animate-pulse rounded-lg bg-zinc-100" />
                    ) : (
                        <QuadrantChart campaigns={campaigns} currency={currency} targetRoas={1.5} />
                    )}
                </div>
            )}
        </AppLayout>
    );
}
