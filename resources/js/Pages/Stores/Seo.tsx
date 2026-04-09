import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ExternalLink } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { StoreLayout } from '@/Components/layouts/StoreLayout';
import type { StoreData } from '@/Components/layouts/StoreLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { LineChart } from '@/Components/charts/LineChart';
import { formatNumber } from '@/lib/formatters';
import type { PageProps } from '@/types';

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

interface Props extends PageProps {
    store: StoreData;
    property: GscProperty | null;
    daily_stats: DailyStat[];
    top_queries: QueryRow[];
    top_pages: PageRow[];
    from: string;
    to: string;
}

function formatCtr(ctr: number | null): string {
    if (ctr === null) return '—';
    return `${(ctr * 100).toFixed(1)}%`;
}

function formatPosition(pos: number | null): string {
    if (pos === null) return '—';
    return pos.toFixed(1);
}

export default function StoreSeo({
    store,
    property,
    daily_stats,
    top_queries,
    top_pages,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const timezone = workspace?.reporting_timezone;

    const hasPartialData = daily_stats.some((d) => d.is_partial);
    const clicksData      = daily_stats.map((d) => ({ date: d.date, value: d.clicks }));
    const impressionsData = daily_stats.map((d) => ({ date: d.date, value: d.impressions }));

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title={`${store.name} — SEO`} />
            <StoreLayout store={store} activeTab="seo">
                {!property ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                        <p className="text-sm text-zinc-500">
                            No Google Search Console property linked to this store.
                        </p>
                        <Link
                            href="/settings/integrations"
                            className="mt-4 text-sm font-medium text-indigo-600 hover:text-indigo-700"
                        >
                            Connect Search Console →
                        </Link>
                    </div>
                ) : (
                    <div className="space-y-6">
                        {/* GSC lag warning */}
                        {hasPartialData && (
                            <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    Data for the last 3 days may be incomplete — Google Search Console
                                    has a 2–3 day reporting lag.
                                </span>
                            </div>
                        )}

                        {/* Property badge */}
                        <div className="flex items-center gap-2 text-sm text-zinc-500">
                            <span>Property:</span>
                            <a
                                href={property.property_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="flex items-center gap-1 text-indigo-600 hover:text-indigo-700 break-all"
                            >
                                {property.property_url}
                                <ExternalLink className="h-3 w-3 shrink-0" />
                            </a>
                        </div>

                        {/* Clicks chart */}
                        <div className="rounded-xl border border-zinc-200 bg-white p-5">
                            <div className="mb-4 text-sm font-medium text-zinc-500">Clicks over time</div>
                            {clicksData.length === 0 ? (
                                <div className="flex h-48 items-center justify-center">
                                    <p className="text-sm text-zinc-400">No data for this period.</p>
                                </div>
                            ) : (
                                <LineChart
                                    data={clicksData}
                                    granularity="daily"
                                    timezone={timezone}
                                    valueType="number"
                                    seriesLabel="Clicks"
                                    className="h-48 w-full"
                                />
                            )}
                        </div>

                        {/* Impressions chart */}
                        <div className="rounded-xl border border-zinc-200 bg-white p-5">
                            <div className="mb-4 text-sm font-medium text-zinc-500">Impressions over time</div>
                            {impressionsData.length === 0 ? (
                                <div className="flex h-48 items-center justify-center">
                                    <p className="text-sm text-zinc-400">No data for this period.</p>
                                </div>
                            ) : (
                                <LineChart
                                    data={impressionsData}
                                    granularity="daily"
                                    timezone={timezone}
                                    valueType="number"
                                    seriesLabel="Impressions"
                                    className="h-48 w-full"
                                />
                            )}
                        </div>

                        {/* Top queries */}
                        <div className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
                            <div className="border-b border-zinc-100 px-4 py-3">
                                <h3 className="text-sm font-medium text-zinc-700">Top Queries</h3>
                            </div>
                            {top_queries.length === 0 ? (
                                <div className="px-4 py-10 text-center text-sm text-zinc-400">
                                    No query data for this period.
                                </div>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                            <th className="px-4 py-3 font-medium text-zinc-400 w-10">#</th>
                                            <th className="px-4 py-3 font-medium text-zinc-400">Query</th>
                                            <th className="px-4 py-3 font-medium text-zinc-400 text-right">Clicks</th>
                                            <th className="px-4 py-3 font-medium text-zinc-400 text-right hidden sm:table-cell">
                                                Impressions
                                            </th>
                                            <th className="px-4 py-3 font-medium text-zinc-400 text-right hidden md:table-cell">
                                                CTR
                                            </th>
                                            <th className="px-4 py-3 font-medium text-zinc-400 text-right hidden md:table-cell">
                                                Avg. Position
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-zinc-100">
                                        {top_queries.map((row, index) => (
                                            <tr key={row.query} className="hover:bg-zinc-50 transition-colors">
                                                <td className="px-4 py-3 text-zinc-400 tabular-nums">
                                                    {index + 1}
                                                </td>
                                                <td className="px-4 py-3 text-zinc-900 max-w-xs truncate">
                                                    {row.query}
                                                </td>
                                                <td className="px-4 py-3 text-right text-zinc-600 tabular-nums">
                                                    {formatNumber(row.clicks)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-zinc-600 tabular-nums hidden sm:table-cell">
                                                    {formatNumber(row.impressions)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-zinc-600 tabular-nums hidden md:table-cell">
                                                    {formatCtr(row.ctr)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-zinc-600 tabular-nums hidden md:table-cell">
                                                    {formatPosition(row.position)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>

                        {/* Top pages */}
                        <div className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
                            <div className="border-b border-zinc-100 px-4 py-3">
                                <h3 className="text-sm font-medium text-zinc-700">Top Pages</h3>
                            </div>
                            {top_pages.length === 0 ? (
                                <div className="px-4 py-10 text-center text-sm text-zinc-400">
                                    No page data for this period.
                                </div>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                            <th className="px-4 py-3 font-medium text-zinc-400 w-10">#</th>
                                            <th className="px-4 py-3 font-medium text-zinc-400">Page</th>
                                            <th className="px-4 py-3 font-medium text-zinc-400 text-right">Clicks</th>
                                            <th className="px-4 py-3 font-medium text-zinc-400 text-right hidden sm:table-cell">
                                                Impressions
                                            </th>
                                            <th className="px-4 py-3 font-medium text-zinc-400 text-right hidden md:table-cell">
                                                CTR
                                            </th>
                                            <th className="px-4 py-3 font-medium text-zinc-400 text-right hidden md:table-cell">
                                                Avg. Position
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-zinc-100">
                                        {top_pages.map((row, index) => (
                                            <tr key={row.page} className="hover:bg-zinc-50 transition-colors">
                                                <td className="px-4 py-3 text-zinc-400 tabular-nums">
                                                    {index + 1}
                                                </td>
                                                <td className="px-4 py-3 max-w-xs">
                                                    <a
                                                        href={row.page}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="flex items-center gap-1 text-indigo-600 hover:text-indigo-700"
                                                    >
                                                        <span className="truncate">
                                                            {row.page.replace(/^https?:\/\//, '')}
                                                        </span>
                                                        <ExternalLink className="h-3 w-3 shrink-0" />
                                                    </a>
                                                </td>
                                                <td className="px-4 py-3 text-right text-zinc-600 tabular-nums">
                                                    {formatNumber(row.clicks)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-zinc-600 tabular-nums hidden sm:table-cell">
                                                    {formatNumber(row.impressions)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-zinc-600 tabular-nums hidden md:table-cell">
                                                    {formatCtr(row.ctr)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-zinc-600 tabular-nums hidden md:table-cell">
                                                    {formatPosition(row.position)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    </div>
                )}
            </StoreLayout>
        </AppLayout>
    );
}
