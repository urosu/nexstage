import React, { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level lets us start with
// navigating=true so the skeleton stays visible until the real data is ready.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });
import { Search, ArrowUpDown } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { MetricCard } from '@/Components/shared/MetricCard';
import { GscMultiSeriesChart } from '@/Components/charts/GscMultiSeriesChart';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { syncDotClass, syncDotTitle } from '@/lib/syncStatus';
import { formatGscProperty, getGscPropertyType } from '@/lib/gsc';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface GscProperty {
    id: number;
    property_url: string;
    status: string;
    last_synced_at: string | null;
}

interface DailyStat {
    date: string;
    clicks: number;
    impressions: number;
    ctr: number | null;
    position: number | null;
    is_partial: boolean;
}

interface QueryRow {
    query: string;
    clicks: number;
    impressions: number;
    ctr: number | null;
    position: number | null;
}

interface PageRow {
    page: string;
    clicks: number;
    impressions: number;
    ctr: number | null;
    position: number | null;
}

interface Summary {
    clicks: number;
    impressions: number;
    ctr: number | null;
    position: number | null;
}

interface Props {
    properties: GscProperty[];
    selected_property_ids: number[];
    daily_stats: DailyStat[];
    top_queries: QueryRow[];
    top_pages: PageRow[];
    summary: Summary | null;
    organic_revenue: number | null;
    organic_orders: number;
    organic_cvr: number | null;
    organic_aov: number | null;
    total_revenue: number | null;
    unattributed_revenue: number | null;
    from: string;
    to: string;
    sort: 'clicks' | 'impressions' | 'ctr' | 'position';
    sort_dir: 'asc' | 'desc';
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fmtCtr(v: number | null) {
    return v != null ? `${(v * 100).toFixed(2)}%` : '—';
}

function fmtPos(v: number | null) {
    return v != null ? v.toFixed(1) : '—';
}

// ─── Sort header ──────────────────────────────────────────────────────────────

function SortTh({
    col,
    label,
    currentSort,
    currentDir,
    onSort,
    className = '',
}: {
    col: string;
    label: string;
    currentSort: string;
    currentDir: 'asc' | 'desc';
    onSort: (col: string) => void;
    className?: string;
}) {
    const active = currentSort === col;
    return (
        <th className={cn('px-4 py-3', className)}>
            <button
                onClick={() => onSort(col)}
                className={cn(
                    'flex items-center gap-1 text-xs font-medium uppercase tracking-wide transition-colors',
                    active ? 'text-primary' : 'text-zinc-400 hover:text-zinc-600',
                    className.includes('text-right') ? 'ml-auto' : '',
                )}
            >
                {label}
                {active
                    ? <span className="text-[10px]">{currentDir === 'desc' ? '↓' : '↑'}</span>
                    : <ArrowUpDown className="h-3 w-3 opacity-40" />}
            </button>
        </th>
    );
}

// ─── GSC data table ───────────────────────────────────────────────────────────

/**
 * Estimate organic revenue for a query/page row: clicks × CVR × AOV.
 * Returns null when organic CVR/AOV unavailable (no organic orders in period).
 */
function estimateOrgRevenue(clicks: number, cvr: number | null, aov: number | null): number | null {
    if (cvr === null || aov === null || clicks === 0) return null;
    return clicks * cvr * aov;
}

function GscTable<T extends { clicks: number; impressions: number; ctr: number | null; position: number | null }>({
    rows,
    labelKey,
    labelHeader,
    sort,
    sortDir,
    onSort,
    renderLabel,
    organicCvr,
    organicAov,
    currency,
}: {
    rows: T[];
    labelKey: keyof T;
    labelHeader: string;
    sort: string;
    sortDir: 'asc' | 'desc';
    onSort: (col: string) => void;
    renderLabel: (row: T) => React.ReactNode;
    organicCvr?: number | null;
    organicAov?: number | null;
    currency?: string;
}) {
    const showEstRevenue = organicCvr != null && organicAov != null;
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr>
                        <th className="px-4 py-3 text-left th-label">
                            {labelHeader}
                        </th>
                        <SortTh col="clicks"      label="Clicks"      currentSort={sort} currentDir={sortDir} onSort={onSort} className="text-right" />
                        <SortTh col="impressions" label="Impressions"  currentSort={sort} currentDir={sortDir} onSort={onSort} className="text-right" />
                        <SortTh col="ctr"         label="CTR"         currentSort={sort} currentDir={sortDir} onSort={onSort} className="text-right" />
                        <SortTh col="position"    label="Position"    currentSort={sort} currentDir={sortDir} onSort={onSort} className="text-right" />
                        {showEstRevenue && (
                            <th className="px-4 py-3 text-right th-label" title="Estimated organic revenue: clicks × organic CVR × organic AOV. Displayed as a range estimate.">
                                Est. Revenue
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody className="divide-y divide-zinc-100">
                    {rows.map((row) => {
                        const estRev = showEstRevenue ? estimateOrgRevenue(row.clicks, organicCvr!, organicAov!) : null;
                        return (
                            <tr key={(row as unknown as QueryRow).query ?? (row as unknown as PageRow).page} className="hover:bg-zinc-50">
                                <td className="max-w-[280px] px-4 py-3 text-zinc-800">
                                    {renderLabel(row)}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                    {formatNumber(row.clicks)}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                    {formatNumber(row.impressions)}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                    {fmtCtr(row.ctr)}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                    {fmtPos(row.position)}
                                </td>
                                {showEstRevenue && (
                                    <td className="px-4 py-3 text-right tabular-nums text-zinc-500">
                                        {estRev != null ? `~${formatCurrency(estRev, currency ?? 'EUR')}` : '—'}
                                    </td>
                                )}
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function SeoIndex(props: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';

    function navigate(params: Record<string, string | undefined | number>) {
        router.get(wurl(workspace?.slug, '/seo'), params as Record<string, string>, { preserveState: true, replace: true });
    }

    const {
        properties,
        selected_property_ids,
        daily_stats,
        top_queries,
        top_pages,
        summary,
        organic_revenue,
        organic_cvr,
        organic_aov,
        total_revenue,
        unattributed_revenue,
        from,
        to,
        sort,
        sort_dir,
    } = props;

    const [navigating, setNavigating] = useState(() => _inertiaNavigating);

    useEffect(() => {
        const off1 = router.on('start',  () => setNavigating(true));
        const off2 = router.on('finish', () => setNavigating(false));
        return () => { off1(); off2(); };
    }, []);

    const currentParams = useMemo(() => ({
        from,
        to,
        sort,
        sort_dir,
        ...(selected_property_ids.length > 0 ? { property_ids: selected_property_ids.join(',') } : {}),
    }), [from, to, sort, sort_dir, selected_property_ids]);

    function toggleProperty(id: number) {
        const next = selected_property_ids.includes(id)
            ? selected_property_ids.filter((x) => x !== id)
            : [...selected_property_ids, id];
        // Selecting all individually == same as "All" (no filter)
        const params = { ...currentParams };
        if (next.length === 0 || next.length === properties.length) {
            delete params.property_ids;
        } else {
            params.property_ids = next.join(',');
        }
        navigate(params);
    }

    function setSort(col: string) {
        const newDir = sort === col && sort_dir === 'desc' ? 'asc' : 'desc';
        navigate({ ...currentParams, sort: col, sort_dir: newDir });
    }

    const chartData = useMemo(() =>
        daily_stats.map((d) => ({
            date: d.date,
            clicks: d.clicks,
            impressions: d.impressions,
            ctr: d.ctr,
            position: d.position,
        })),
    [daily_stats]);

    const hasPartial = daily_stats.some((d) => d.is_partial);

    // ── Empty state ──────────────────────────────────────────────────────────
    if (properties.length === 0) {
        return (
            <AppLayout dateRangePicker={<DateRangePicker />}>
                <Head title="SEO" />
                <PageHeader title="SEO" subtitle="Google Search Console performance" />
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100">
                        <Search className="h-6 w-6 text-zinc-400" />
                    </div>
                    <h3 className="mb-1 text-base font-semibold text-zinc-900">No Search Console properties connected</h3>
                    <p className="mb-5 max-w-xs text-sm text-zinc-500">
                        Connect Google Search Console to view clicks, impressions, CTR, and ranking data.
                    </p>
                    <Link
                        href="/settings/integrations"
                        className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        Connect Google Search Console →
                    </Link>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title="SEO" />
            <PageHeader title="SEO" subtitle="Google Search Console performance" />

            {/* ── Property filter ── */}
            <div className="mb-6 flex flex-wrap items-center gap-2">
                {properties.length > 1 && (
                    <button
                        onClick={() => navigate({ from, to, sort, sort_dir })}
                        className={cn(
                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            selected_property_ids.length === 0
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                        )}
                    >
                        All
                    </button>
                )}
                {properties.map((p) => {
                    const active = properties.length === 1 || selected_property_ids.length === 0
                        ? true  // all selected = every pill is "on"
                        : selected_property_ids.includes(p.id);
                    return (
                        <button
                            key={p.id}
                            onClick={() => properties.length > 1 ? toggleProperty(p.id) : undefined}
                            className={cn(
                                'flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                active
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                                properties.length === 1 && 'cursor-default',
                            )}
                            title={`${p.property_url} — ${syncDotTitle(p.status, p.last_synced_at)}`}
                        >
                            <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', syncDotClass(p.status, p.last_synced_at, 'gsc'))} />
                            {formatGscProperty(p.property_url)}
                            {(() => {
                                const isDomain = getGscPropertyType(p.property_url) === 'domain';
                                return (
                                    <span className={`inline-flex items-center rounded-full px-1.5 py-px text-[10px] font-medium ${isDomain ? 'bg-violet-100 text-violet-700' : 'bg-sky-100 text-sky-700'}`}>
                                        {isDomain ? 'Domain' : 'URL prefix'}
                                    </span>
                                );
                            })()}
                        </button>
                    );
                })}
            </div>

            {/* ── GSC lag warning ── */}
            {hasPartial && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-700">
                    Data for the last 3 days may be incomplete — Google Search Console has a 2–3 day reporting lag.{' '}
                    <a href="/help/data-accuracy#gsc-lag" className="font-medium underline hover:no-underline">
                        Learn more
                    </a>
                </div>
            )}

            {/* ── Hero cards (5 cards per PLANNING 12.5) ── */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-5">
                <MetricCard
                    label="Total Clicks"
                    source="gsc"
                    value={summary ? formatNumber(summary.clicks) : null}
                    loading={navigating}
                />
                <MetricCard
                    label="Total Impressions"
                    source="gsc"
                    value={summary ? formatNumber(summary.impressions) : null}
                    loading={navigating}
                />
                <MetricCard
                    label="Avg CTR"
                    source="gsc"
                    value={summary?.ctr != null ? `${(summary.ctr * 100).toFixed(2)}%` : null}
                    loading={navigating}
                    tooltip="Click-Through Rate. Percentage of Google Search impressions that resulted in a click to your site."
                />
                <MetricCard
                    label="Avg Position"
                    source="gsc"
                    value={summary?.position != null ? summary.position.toFixed(1) : null}
                    loading={navigating}
                    invertTrend
                    tooltip="Average ranking position in Google Search results. Lower is better — position 1 = top result."
                />
                <MetricCard
                    label="Organic Revenue"
                    source="real"
                    value={organic_revenue != null ? formatCurrency(organic_revenue, currency) : null}
                    loading={navigating}
                    tooltip="Revenue from orders attributed to organic search via UTM tracking (channel_type = organic_search)."
                />
            </div>

            {/* ── GSC trend chart — clicks, impressions, CTR, avg position ── */}
            <div className="mb-6 rounded-xl border border-zinc-200 bg-white p-5">
                <div className="mb-1 text-sm font-medium text-zinc-500">Performance over time</div>
                {navigating ? (
                    <div className="h-56 w-full animate-pulse rounded-lg bg-zinc-100" />
                ) : chartData.length === 0 ? (
                    <div className="flex h-56 flex-col items-center justify-center gap-2">
                        <p className="text-sm text-zinc-400">No data for this period.</p>
                    </div>
                ) : (
                    <GscMultiSeriesChart
                        data={chartData}
                        granularity="daily"
                        className="w-full"
                    />
                )}
            </div>

            {/* ── Tables ── */}
            <div className="grid gap-6 lg:grid-cols-2">
                {/* Top queries */}
                <div className="rounded-xl border border-zinc-200 bg-white">
                    <div className="border-b border-zinc-100 px-4 py-4">
                        <div className="text-sm font-medium text-zinc-700">Top queries</div>
                        <div className="text-xs text-zinc-400">Top 50 by selected sort</div>
                    </div>
                    {navigating ? (
                        <div className="h-64 animate-pulse bg-zinc-50" />
                    ) : top_queries.length === 0 ? (
                        <div className="flex h-40 items-center justify-center text-sm text-zinc-400">
                            No query data for this period.
                        </div>
                    ) : (
                        <GscTable
                            rows={top_queries}
                            labelKey="query"
                            labelHeader="Query"
                            sort={sort}
                            sortDir={sort_dir}
                            onSort={setSort}
                            organicCvr={organic_cvr}
                            organicAov={organic_aov}
                            currency={currency}
                            renderLabel={(row) => (
                                <span className="block truncate text-sm text-zinc-800" title={row.query}>
                                    {row.query}
                                </span>
                            )}
                        />
                    )}
                </div>

                {/* Top pages */}
                <div className="rounded-xl border border-zinc-200 bg-white">
                    <div className="border-b border-zinc-100 px-4 py-4">
                        <div className="text-sm font-medium text-zinc-700">Top pages</div>
                        <div className="text-xs text-zinc-400">Top 50 by selected sort</div>
                    </div>
                    {navigating ? (
                        <div className="h-64 animate-pulse bg-zinc-50" />
                    ) : top_pages.length === 0 ? (
                        <div className="flex h-40 items-center justify-center text-sm text-zinc-400">
                            No page data for this period.
                        </div>
                    ) : (
                        <GscTable
                            rows={top_pages}
                            labelKey="page"
                            labelHeader="Page"
                            sort={sort}
                            sortDir={sort_dir}
                            onSort={setSort}
                            organicCvr={organic_cvr}
                            organicAov={organic_aov}
                            currency={currency}
                            renderLabel={(row) => {
                                let display = row.page;
                                try {
                                    const url = new URL(row.page);
                                    display = url.pathname + (url.search || '');
                                } catch {}
                                return (
                                    <a
                                        href={row.page}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="block truncate text-sm text-primary hover:underline"
                                        title={row.page}
                                    >
                                        {display}
                                    </a>
                                );
                            }}
                        />
                    )}
                </div>
            </div>

        </AppLayout>
    );
}
