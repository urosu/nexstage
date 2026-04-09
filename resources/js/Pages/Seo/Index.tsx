import React, { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Search, ArrowUpDown } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { MetricCard } from '@/Components/shared/MetricCard';
import { BarChart } from '@/Components/charts/BarChart';
import { formatNumber, formatDateOnly } from '@/lib/formatters';
import { cn } from '@/lib/utils';
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
    selected_property: GscProperty | null;
    daily_stats: DailyStat[];
    top_queries: QueryRow[];
    top_pages: PageRow[];
    summary: Summary | null;
    from: string;
    to: string;
    sort: 'clicks' | 'impressions' | 'ctr' | 'position';
    sort_dir: 'asc' | 'desc';
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function navigate(params: Record<string, string | undefined | number>) {
    router.get('/seo', params as Record<string, string>, { preserveState: true, replace: true });
}

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
                    active ? 'text-indigo-600' : 'text-zinc-400 hover:text-zinc-600',
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

function GscTable<T extends { clicks: number; impressions: number; ctr: number | null; position: number | null }>({
    rows,
    labelKey,
    labelHeader,
    sort,
    sortDir,
    onSort,
    renderLabel,
}: {
    rows: T[];
    labelKey: keyof T;
    labelHeader: string;
    sort: string;
    sortDir: 'asc' | 'desc';
    onSort: (col: string) => void;
    renderLabel: (row: T) => React.ReactNode;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr>
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-400">
                            {labelHeader}
                        </th>
                        <SortTh col="clicks"      label="Clicks"      currentSort={sort} currentDir={sortDir} onSort={onSort} className="text-right" />
                        <SortTh col="impressions" label="Impressions"  currentSort={sort} currentDir={sortDir} onSort={onSort} className="text-right" />
                        <SortTh col="ctr"         label="CTR"         currentSort={sort} currentDir={sortDir} onSort={onSort} className="text-right" />
                        <SortTh col="position"    label="Position"    currentSort={sort} currentDir={sortDir} onSort={onSort} className="text-right" />
                    </tr>
                </thead>
                <tbody className="divide-y divide-zinc-100">
                    {rows.map((row, idx) => (
                        <tr key={idx} className="hover:bg-zinc-50">
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
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function SeoIndex(props: Props) {
    usePage<PageProps>().props; // access page props for future use

    const {
        properties,
        selected_property,
        daily_stats,
        top_queries,
        top_pages,
        summary,
        from,
        to,
        sort,
        sort_dir,
    } = props;

    const [navigating, setNavigating] = useState(false);

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
        ...(selected_property ? { property_id: String(selected_property.id) } : {}),
    }), [from, to, sort, sort_dir, selected_property]);

    function selectProperty(id: number | null) {
        const params = { ...currentParams };
        if (id === null) {
            delete params.property_id;
        } else {
            params.property_id = String(id);
        }
        navigate(params);
    }

    function setSort(col: string) {
        const newDir = sort === col && sort_dir === 'desc' ? 'asc' : 'desc';
        navigate({ ...currentParams, sort: col, sort_dir: newDir });
    }

    // clicks chart data
    const clicksData = useMemo(() =>
        daily_stats.map((d) => ({ date: d.date, value: d.clicks })),
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
                        className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
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
            {properties.length > 1 && (
                <div className="mb-6 flex flex-wrap items-center gap-2">
                    <button
                        onClick={() => selectProperty(null)}
                        className={cn(
                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            selected_property === null
                                ? 'border-indigo-600 bg-indigo-50 text-indigo-700'
                                : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                        )}
                    >
                        All properties
                    </button>
                    {properties.map((p) => (
                        <button
                            key={p.id}
                            onClick={() => selectProperty(p.id)}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs font-medium transition-colors truncate max-w-[240px]',
                                selected_property?.id === p.id
                                    ? 'border-indigo-600 bg-indigo-50 text-indigo-700'
                                    : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                            )}
                            title={p.property_url}
                        >
                            {p.property_url}
                        </button>
                    ))}
                </div>
            )}

            {/* ── GSC lag warning ── */}
            {hasPartial && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-700">
                    Data for the last 3 days may be incomplete — Google Search Console has a 2–3 day reporting lag.
                </div>
            )}

            {/* ── Summary cards ── */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <MetricCard
                    label="Total Clicks"
                    value={summary ? formatNumber(summary.clicks) : null}
                    loading={navigating}
                />
                <MetricCard
                    label="Total Impressions"
                    value={summary ? formatNumber(summary.impressions) : null}
                    loading={navigating}
                />
                <MetricCard
                    label="Avg CTR"
                    value={summary?.ctr != null ? `${(summary.ctr * 100).toFixed(2)}%` : null}
                    loading={navigating}
                    tooltip="Click-Through Rate. Percentage of Google Search impressions that resulted in a click to your site."
                />
                <MetricCard
                    label="Avg Position"
                    value={summary?.position != null ? summary.position.toFixed(1) : null}
                    loading={navigating}
                    invertTrend
                    tooltip="Average ranking position in Google Search results for the selected period. Lower is better — position 1 means top result. Google Search Console data has a 2–3 day lag."
                />
            </div>

            {/* ── Daily clicks chart ── */}
            <div className="mb-6 rounded-xl border border-zinc-200 bg-white p-5">
                <div className="mb-4 text-sm font-medium text-zinc-500">Daily clicks</div>
                {navigating ? (
                    <div className="h-56 w-full animate-pulse rounded-lg bg-zinc-100" />
                ) : clicksData.length === 0 ? (
                    <div className="flex h-56 flex-col items-center justify-center gap-2">
                        <p className="text-sm text-zinc-400">No data for this period.</p>
                    </div>
                ) : (
                    <BarChart
                        data={clicksData}
                        granularity="daily"
                        seriesLabel="Clicks"
                        valueType="number"
                        className="h-56 w-full"
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
                                        className="block truncate text-sm text-indigo-600 hover:underline"
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

            {/* ── Property status footer ── */}
            <div className="mt-4 flex flex-wrap gap-3">
                {properties.map((p) => (
                    <div
                        key={p.id}
                        className="flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-3 py-1 text-xs text-zinc-500"
                    >
                        <span className={cn(
                            'h-1.5 w-1.5 rounded-full',
                            p.status === 'active' ? 'bg-green-500' : 'bg-red-400',
                        )} />
                        <span className="truncate max-w-[200px]" title={p.property_url}>
                            {p.property_url}
                        </span>
                        {p.last_synced_at && (
                            <span className="text-zinc-400">
                                · synced {formatDateOnly(p.last_synced_at)}
                            </span>
                        )}
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
