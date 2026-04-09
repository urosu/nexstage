import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/Components/layouts/AppLayout';
import { StoreLayout } from '@/Components/layouts/StoreLayout';
import type { StoreData } from '@/Components/layouts/StoreLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import type { PageProps } from '@/types';

interface ProductRow {
    external_id: string;
    name: string;
    units: number;
    revenue: number;
}

interface Props extends PageProps {
    store: StoreData;
    products: ProductRow[];
    from: string;
    to: string;
}

export default function StoreProducts({ store, products }: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';

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
                                <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                    <th className="px-4 py-3 font-medium text-zinc-400 w-10">#</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400">Product</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400 text-right hidden sm:table-cell">
                                        Units Sold
                                    </th>
                                    <th className="px-4 py-3 font-medium text-zinc-400 text-right">Revenue</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100">
                                {products.map((product, index) => (
                                    <tr key={product.external_id} className="hover:bg-zinc-50 transition-colors">
                                        <td className="px-4 py-3 text-zinc-400 tabular-nums">{index + 1}</td>
                                        <td className="px-4 py-3">
                                            <div className="font-medium text-zinc-900">{product.name}</div>
                                            <div className="text-xs text-zinc-400">ID: {product.external_id}</div>
                                        </td>
                                        <td className="px-4 py-3 text-right text-zinc-600 tabular-nums hidden sm:table-cell">
                                            {formatNumber(product.units)}
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium text-zinc-900 tabular-nums">
                                            {formatCurrency(product.revenue, currency)}
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
