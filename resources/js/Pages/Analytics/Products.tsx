import { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, Package } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { AnalyticsTabBar } from '@/Components/shared/AnalyticsTabBar';
import { StoreFilter } from '@/Components/shared/StoreFilter';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import type { PageProps } from '@/types';

interface ProductRow {
    external_id: string;
    name: string;
    units: number;
    revenue: number | null;
}

type SortBy = 'revenue' | 'units';
type SortDir = 'asc' | 'desc';

interface Props {
    products: ProductRow[];
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

export default function AnalyticsProducts({ products, from, to, store_ids, sort_by, sort_dir }: Props) {
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
        base.sort_by  = sort_by;
        base.sort_dir = sort_dir;
        return { ...base, ...overrides };
    }

    function sortByColumn(column: SortBy): void {
        const nextDir: SortDir = sort_by === column && sort_dir === 'desc' ? 'asc' : 'desc';
        router.get('/analytics/products', buildParams({ sort_by: column, sort_dir: nextDir }), {
            preserveState: true, replace: true,
        });
    }

    return (
        <AppLayout dateRangePicker={<><DateRangePicker /><StoreFilter selectedStoreIds={store_ids} /></>}>
            <Head title="Analytics — By Product" />
            <PageHeader title="Analytics" subtitle="Top products by revenue" />
            <AnalyticsTabBar />

            {navigating ? (
                <div className="space-y-2">
                    {[...Array(10)].map((_, i) => (
                        <div key={i} className="h-12 animate-pulse rounded-xl bg-zinc-100" />
                    ))}
                </div>
            ) : products.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100">
                        <Package className="h-6 w-6 text-zinc-400" />
                    </div>
                    <h3 className="mb-1 text-base font-semibold text-zinc-900">No product data</h3>
                    <p className="max-w-xs text-sm text-zinc-500">
                        Product data is derived from order snapshots. It appears after the nightly
                        snapshot job has run.
                    </p>
                </div>
            ) : (
                <div className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                <th className="px-4 py-3 font-medium text-zinc-400 w-10">#</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Product</th>
                                <th className="px-4 py-3 hidden sm:table-cell">
                                    <button
                                        onClick={() => sortByColumn('units')}
                                        className="flex items-center justify-end w-full font-medium text-zinc-400 hover:text-zinc-700 transition-colors"
                                    >
                                        Units sold
                                        <SortIcon column="units" sortBy={sort_by} sortDir={sort_dir} />
                                    </button>
                                </th>
                                <th className="px-4 py-3">
                                    <button
                                        onClick={() => sortByColumn('revenue')}
                                        className="flex items-center justify-end w-full font-medium text-zinc-400 hover:text-zinc-700 transition-colors"
                                    >
                                        Revenue
                                        <SortIcon column="revenue" sortBy={sort_by} sortDir={sort_dir} />
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {products.map((p, index) => (
                                <tr key={p.external_id} className="hover:bg-zinc-50 transition-colors">
                                    <td className="px-4 py-3 text-zinc-400 tabular-nums">{index + 1}</td>
                                    <td className="px-4 py-3">
                                        <div className="font-medium text-zinc-900 truncate max-w-[340px]">
                                            {p.name}
                                        </div>
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
                </div>
            )}
        </AppLayout>
    );
}
