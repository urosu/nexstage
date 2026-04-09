import { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, Globe } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { AnalyticsTabBar } from '@/Components/shared/AnalyticsTabBar';
import { StoreFilter } from '@/Components/shared/StoreFilter';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

// Inline country name lookup
const COUNTRY_NAMES: Record<string, string> = {
    AD: 'Andorra',         AE: 'UAE',              AT: 'Austria',
    AU: 'Australia',       BE: 'Belgium',           BG: 'Bulgaria',
    BR: 'Brazil',          CA: 'Canada',            CH: 'Switzerland',
    CN: 'China',           CY: 'Cyprus',            CZ: 'Czech Republic',
    DE: 'Germany',         DK: 'Denmark',           EE: 'Estonia',
    ES: 'Spain',           FI: 'Finland',           FR: 'France',
    GB: 'United Kingdom',  GR: 'Greece',            HR: 'Croatia',
    HU: 'Hungary',         IE: 'Ireland',           IT: 'Italy',
    JP: 'Japan',           KR: 'South Korea',       LT: 'Lithuania',
    LU: 'Luxembourg',      LV: 'Latvia',            MT: 'Malta',
    MX: 'Mexico',          NL: 'Netherlands',       NO: 'Norway',
    NZ: 'New Zealand',     PL: 'Poland',            PT: 'Portugal',
    RO: 'Romania',         RU: 'Russia',            SE: 'Sweden',
    SI: 'Slovenia',        SK: 'Slovakia',          TR: 'Turkey',
    UA: 'Ukraine',         US: 'United States',
};

interface CountryRow {
    country_code: string;
    revenue: number;
    share: number;
}

interface TopProduct {
    product_external_id: string;
    product_name: string;
    units: number;
    revenue: number | null;
}

type SortBy = 'revenue' | 'country_name';
type SortDir = 'asc' | 'desc';

interface Props {
    countries: CountryRow[];
    top_products: TopProduct[];
    selected_country: string | null;
    from: string;
    to: string;
    store_ids: number[];
    sort_by: SortBy;
    sort_dir: SortDir;
}

function SortIcon({ column, sortBy, sortDir }: { column: SortBy; sortBy: SortBy; sortDir: SortDir }) {
    if (column !== sortBy) return <ArrowUpDown className="ml-1 h-3.5 w-3.5 opacity-40" />;
    return sortDir === 'asc'
        ? <ArrowUp className="ml-1 h-3.5 w-3.5 text-indigo-600" />
        : <ArrowDown className="ml-1 h-3.5 w-3.5 text-indigo-600" />;
}

export default function Countries({
    countries, top_products, selected_country, from, to, store_ids, sort_by, sort_dir,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const [navigating, setNavigating] = useState(false);

    useEffect(() => {
        const removeStart  = router.on('start',  () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));
        return () => { removeStart(); removeFinish(); };
    }, []);

    function buildParams(overrides: Record<string, string | undefined>): Record<string, string | undefined> {
        const base: Record<string, string | undefined> = { from, to };
        if (store_ids.length > 0) base.store_ids = store_ids.join(',');
        if (selected_country) base.country = selected_country;
        base.sort_by  = sort_by;
        base.sort_dir = sort_dir;
        return { ...base, ...overrides };
    }

    function sortBy(column: SortBy): void {
        const nextDir: SortDir = sort_by === column && sort_dir === 'desc' ? 'asc' : 'desc';
        router.get('/countries', buildParams({ sort_by: column, sort_dir: nextDir }), {
            preserveState: true, replace: true,
        });
    }

    function selectCountry(code: string): void {
        const next = selected_country === code ? undefined : code;
        router.get('/countries', buildParams({ country: next }), { preserveState: true, replace: true });
    }

    const selectedName = selected_country ? (COUNTRY_NAMES[selected_country] ?? selected_country) : null;

    return (
        <AppLayout dateRangePicker={<><DateRangePicker /><StoreFilter selectedStoreIds={store_ids} /></>}>
            <Head title="Analytics — By Country" />
            <PageHeader title="Analytics" subtitle="Revenue by country" />
            <AnalyticsTabBar />

            {navigating ? (
                <div className="space-y-2">
                    {[...Array(8)].map((_, i) => (
                        <div key={i} className="h-12 animate-pulse rounded-xl bg-zinc-100" />
                    ))}
                </div>
            ) : countries.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100">
                        <Globe className="h-6 w-6 text-zinc-400" />
                    </div>
                    <h3 className="mb-1 text-base font-semibold text-zinc-900">No country data</h3>
                    <p className="max-w-xs text-sm text-zinc-500">
                        Country data is derived from customer billing addresses on orders. It appears
                        after the nightly snapshot job has run.
                    </p>
                </div>
            ) : (
                <div className={cn('gap-6', selected_country ? 'grid grid-cols-1 lg:grid-cols-2' : 'block')}>
                    {/* Country table */}
                    <div className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                    <th className="px-4 py-3 font-medium text-zinc-400 w-10">#</th>
                                    <th className="px-4 py-3">
                                        <button
                                            onClick={() => sortBy('country_name')}
                                            className="flex items-center font-medium text-zinc-400 hover:text-zinc-700 transition-colors"
                                        >
                                            Country
                                            <SortIcon column="country_name" sortBy={sort_by} sortDir={sort_dir} />
                                        </button>
                                    </th>
                                    <th className="px-4 py-3 font-medium text-zinc-400 hidden sm:table-cell">Share</th>
                                    <th className="px-4 py-3 text-right">
                                        <button
                                            onClick={() => sortBy('revenue')}
                                            className="flex items-center justify-end w-full font-medium text-zinc-400 hover:text-zinc-700 transition-colors"
                                        >
                                            Revenue
                                            <SortIcon column="revenue" sortBy={sort_by} sortDir={sort_dir} />
                                        </button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100">
                                {countries.map((row, index) => {
                                    const code     = row.country_code.toUpperCase();
                                    const isActive = selected_country === code;
                                    return (
                                        <tr
                                            key={code}
                                            onClick={() => selectCountry(code)}
                                            className={cn(
                                                'cursor-pointer transition-colors',
                                                isActive ? 'bg-indigo-50' : 'hover:bg-zinc-50',
                                            )}
                                        >
                                            <td className="px-4 py-3 text-zinc-400 tabular-nums">{index + 1}</td>
                                            <td className="px-4 py-3">
                                                <div className={cn('font-medium', isActive ? 'text-indigo-700' : 'text-zinc-900')}>
                                                    {COUNTRY_NAMES[code] ?? code}
                                                </div>
                                                <div className="text-xs text-zinc-400">{code}</div>
                                            </td>
                                            <td className="px-4 py-3 hidden sm:table-cell">
                                                <div className="flex items-center gap-2">
                                                    <div className="w-24 h-1.5 rounded-full bg-zinc-100 overflow-hidden">
                                                        <div
                                                            className={cn('h-full rounded-full', isActive ? 'bg-indigo-500' : 'bg-indigo-400')}
                                                            style={{ width: `${Math.min(row.share, 100)}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-zinc-500 tabular-nums w-12 text-right text-xs">
                                                        {row.share.toFixed(1)}%
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium text-zinc-900 tabular-nums">
                                                {formatCurrency(row.revenue, currency)}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {/* Top products panel */}
                    {selected_country && (
                        <div className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
                            <div className="border-b border-zinc-100 px-4 py-3">
                                <h3 className="text-sm font-semibold text-zinc-900">
                                    Top products in {selectedName}
                                </h3>
                                <p className="text-xs text-zinc-400 mt-0.5">
                                    Based on revenue share of orders with {selected_country} billing address
                                </p>
                            </div>
                            {top_products.length === 0 ? (
                                <div className="flex flex-col items-center justify-center px-6 py-12 text-center">
                                    <p className="text-sm text-zinc-400">No product data for this country.</p>
                                </div>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                            <th className="px-4 py-3 font-medium text-zinc-400 w-10">#</th>
                                            <th className="px-4 py-3 font-medium text-zinc-400">Product</th>
                                            <th className="px-4 py-3 font-medium text-zinc-400 text-right hidden sm:table-cell">Units</th>
                                            <th className="px-4 py-3 font-medium text-zinc-400 text-right">Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-zinc-100">
                                        {top_products.map((p, index) => (
                                            <tr key={p.product_external_id} className="hover:bg-zinc-50 transition-colors">
                                                <td className="px-4 py-3 text-zinc-400 tabular-nums">{index + 1}</td>
                                                <td className="px-4 py-3">
                                                    <div className="font-medium text-zinc-900 truncate max-w-[200px]">{p.product_name}</div>
                                                </td>
                                                <td className="px-4 py-3 text-right text-zinc-500 tabular-nums hidden sm:table-cell">
                                                    {formatNumber(p.units)}
                                                </td>
                                                <td className="px-4 py-3 text-right font-medium text-zinc-900 tabular-nums">
                                                    {p.revenue != null ? formatCurrency(p.revenue, currency) : '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    )}
                </div>
            )}
        </AppLayout>
    );
}
