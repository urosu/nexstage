import { useMemo, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Package } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { StoreLayout } from '@/Components/layouts/StoreLayout';
import type { StoreData } from '@/Components/layouts/StoreLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { TrendBadge } from '@/Components/shared/TrendBadge';
import { StatusBadge } from '@/Components/shared/StatusBadge';
import { SortButton } from '@/Components/shared/SortButton';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import type { PageProps } from '@/types';

interface ProductRow {
    external_id: string;
    name: string;
    units: number;
    revenue: number;
    revenue_delta: number | null;
    units_delta: number | null;
    stock_status: string | null;
    stock_quantity: number | null;
    image_url: string | null;
}

interface Props extends PageProps {
    store: StoreData;
    products: ProductRow[];
    from: string;
    to: string;
}

type SortCol = 'name' | 'units' | 'revenue';

export default function StoreProducts({ store, products }: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';

    const [sortCol, setSortCol] = useState<SortCol>('revenue');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

    function handleSort(col: string) {
        const c = col as SortCol;
        if (sortCol === c) {
            setSortDir(d => d === 'desc' ? 'asc' : 'desc');
        } else {
            setSortCol(c);
            setSortDir('desc');
        }
    }

    const sorted = useMemo(() => {
        return [...products].sort((a, b) => {
            let aVal: string | number = sortCol === 'name' ? a.name : sortCol === 'units' ? a.units : a.revenue;
            let bVal: string | number = sortCol === 'name' ? b.name : sortCol === 'units' ? b.units : b.revenue;
            if (typeof aVal === 'string') {
                const cmp = aVal.localeCompare(bVal as string);
                return sortDir === 'asc' ? cmp : -cmp;
            }
            return sortDir === 'asc' ? (aVal as number) - (bVal as number) : (bVal as number) - (aVal as number);
        });
    }, [products, sortCol, sortDir]);

    const sortBtn = (col: string, label: string) => (
        <SortButton col={col} label={label} currentSort={sortCol} currentDir={sortDir} onSort={handleSort} />
    );

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title={`${store.name} — Products`} />
            <StoreLayout store={store} activeTab="products">
                {products.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                        <p className="text-sm text-zinc-400">No product data for this period.</p>
                        <p className="text-xs text-zinc-400 mt-1">
                            Data appears once the nightly snapshot job has run.
                        </p>
                    </div>
                ) : (
                    <div className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-zinc-100 text-left th-label">
                                    <th className="px-4 py-3 w-10">#</th>
                                    <th className="px-4 py-3">{sortBtn('name', 'Product')}</th>
                                    <th className="px-4 py-3 text-right hidden sm:table-cell">{sortBtn('units', 'Units Sold')}</th>
                                    <th className="px-4 py-3 text-right">{sortBtn('revenue', 'Revenue')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100">
                                {sorted.map((product, index) => (
                                    <tr key={product.external_id} className="hover:bg-zinc-50 transition-colors">
                                        <td className="px-4 py-3 text-zinc-400 tabular-nums">{index + 1}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-3">
                                                {product.image_url ? (
                                                    <img
                                                        src={product.image_url}
                                                        alt=""
                                                        className="h-8 w-8 rounded object-cover shrink-0"
                                                        loading="lazy"
                                                    />
                                                ) : (
                                                    <div className="flex h-8 w-8 items-center justify-center rounded bg-zinc-100 shrink-0">
                                                        <Package className="h-4 w-4 text-zinc-300" />
                                                    </div>
                                                )}
                                                <div className="min-w-0">
                                                    <div className="font-medium text-zinc-900 truncate">{product.name}</div>
                                                    <div className="flex items-center gap-2 mt-0.5">
                                                        <span className="text-xs text-zinc-400">ID: {product.external_id}</span>
                                                        {product.stock_status && product.stock_status !== 'in_stock' && (
                                                            <StatusBadge status={product.stock_status} preset="stock" />
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-right hidden sm:table-cell">
                                            <div className="text-zinc-600 tabular-nums">{formatNumber(product.units)}</div>
                                            {product.units_delta != null && (
                                                <div className="flex justify-end mt-0.5">
                                                    <TrendBadge value={product.units_delta} />
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="font-medium text-zinc-900 tabular-nums">
                                                {formatCurrency(product.revenue, currency)}
                                            </div>
                                            {product.revenue_delta != null && (
                                                <div className="flex justify-end mt-0.5">
                                                    <TrendBadge value={product.revenue_delta} />
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </StoreLayout>
        </AppLayout>
    );
}
