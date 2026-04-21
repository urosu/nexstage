import { Head, Link } from '@inertiajs/react';
import { Package, ExternalLink, TrendingUp } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

/**
 * Product detail — drill-down from /analytics/products.
 *
 * Shows 90-day hero metrics, variation breakdown, attributed source mix,
 * recent orders, and Frequently-Bought-Together pairs from product_affinities
 * (computed weekly by ComputeProductAffinitiesJob).
 *
 * @see PLANNING.md section 12.5, section 19
 */

interface ProductInfo {
    id: number;
    external_id: string;
    name: string;
    sku: string | null;
    image_url: string | null;
    product_url: string | null;
    price: number;
    stock_status: string | null;
    stock_quantity: number | null;
    store: { id: number; name: string; slug: string } | null;
}

interface Hero {
    units: number;
    orders: number;
    revenue: number;
    total_cogs: number | null;
    margin: number | null;
    margin_pct: number | null;
    has_cogs: boolean;
    window_days: number;
}

interface VariantRow {
    variant_name: string;
    sku: string | null;
    units: number;
    revenue: number | null;
}

interface SourceRow {
    channel_type: string;
    orders: number;
    revenue: number;
}

interface RecentOrderRow {
    id: number;
    external_number: string | null;
    external_id: string;
    occurred_at: string | null;
    total: number;
    currency: string;
    attribution_source: string | null;
    qty: number;
    line_total: number;
}

interface FbtRow {
    product_id: number;
    external_id: string;
    name: string;
    image_url: string | null;
    confidence: number;
    support: number;
    lift: number;
    margin_lift: number | null;
}

interface Props extends PageProps {
    product: ProductInfo;
    hero: Hero;
    variants: VariantRow[];
    sources: SourceRow[];
    recent_orders: RecentOrderRow[];
    fbt: FbtRow[];
}

const CHANNEL_LABEL: Record<string, string> = {
    paid_social: 'Paid social',
    paid_search: 'Paid search',
    organic_search: 'Organic search',
    organic_social: 'Organic social',
    email: 'Email',
    sms: 'SMS',
    direct: 'Direct',
    referral: 'Referral',
    affiliate: 'Affiliate',
    other: 'Other',
    not_tracked: 'Not tracked',
};

function StockBadge({ status }: { status: string | null }) {
    if (!status) return <span className="text-xs text-zinc-400">—</span>;
    const map: Record<string, string> = {
        instock:    'bg-green-50 text-green-700 border-green-200',
        outofstock: 'bg-red-50 text-red-700 border-red-200',
        onbackorder:'bg-amber-50 text-amber-700 border-amber-200',
    };
    return (
        <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${map[status] ?? 'bg-zinc-50 text-zinc-700 border-zinc-200'}`}>
            {status}
        </span>
    );
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
    });
}

export default function ProductShow({ product, hero, variants, sources, recent_orders, fbt, workspace }: Props) {
    // Currency: prefer the most recent order currency; fall back to EUR.
    const currency = recent_orders[0]?.currency ?? 'EUR';
    const totalSourceRevenue = sources.reduce((s, r) => s + r.revenue, 0);

    return (
        <AppLayout>
            <Head title={product.name} />

            <div className="space-y-6">
                <PageHeader
                    title={product.name}
                    subtitle={[
                        product.store?.name,
                        product.sku ? `SKU ${product.sku}` : null,
                    ].filter(Boolean).join(' · ') || undefined}
                />

                <div className="flex items-center justify-between">
                    <Link
                        href={wurl(workspace?.slug, '/analytics/products')}
                        className="text-sm text-zinc-500 hover:text-zinc-900"
                    >
                        ← Back to products
                    </Link>
                    {product.product_url && (
                        <a
                            href={product.product_url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-900"
                        >
                            View on store <ExternalLink className="h-3 w-3" />
                        </a>
                    )}
                </div>

                {/* ── Product identity + hero metrics ─────────────────────────── */}
                <section className="rounded-xl border border-zinc-200 bg-white p-5">
                    <div className="flex items-start gap-5">
                        {product.image_url ? (
                            <img
                                src={product.image_url}
                                alt=""
                                className="h-24 w-24 shrink-0 rounded-lg object-cover"
                            />
                        ) : (
                            <div className="flex h-24 w-24 shrink-0 items-center justify-center rounded-lg bg-zinc-100">
                                <Package className="h-10 w-10 text-zinc-300" />
                            </div>
                        )}

                        <div className="flex-1">
                            <div className="flex items-center gap-3">
                                <StockBadge status={product.stock_status} />
                                {product.stock_quantity !== null && (
                                    <span className="text-xs text-zinc-500">
                                        {product.stock_quantity} in stock
                                    </span>
                                )}
                                <span className="text-xs text-zinc-500">
                                    List price {formatCurrency(product.price, currency)}
                                </span>
                            </div>

                            <div className="mt-4 grid grid-cols-2 gap-6 md:grid-cols-4">
                                <Metric label="Units sold" value={formatNumber(hero.units)} hint={`last ${hero.window_days}d`} />
                                <Metric label="Orders" value={formatNumber(hero.orders)} hint={`last ${hero.window_days}d`} />
                                <Metric label="Revenue" value={formatCurrency(hero.revenue, currency)} hint={`last ${hero.window_days}d`} />
                                {hero.has_cogs ? (
                                    <Metric
                                        label="Contribution margin"
                                        value={hero.margin !== null ? formatCurrency(hero.margin, currency) : '—'}
                                        hint={hero.margin_pct !== null ? `${hero.margin_pct}% margin` : undefined}
                                        tone={hero.margin !== null && hero.margin >= 0 ? 'positive' : 'negative'}
                                    />
                                ) : (
                                    <Metric label="Margin" value="—" hint="No COGS configured" />
                                )}
                            </div>
                        </div>
                    </div>
                </section>

                {/* ── Frequently bought with ──────────────────────────────────── */}
                <section className="rounded-xl border border-zinc-200 bg-white">
                    <header className="border-b border-zinc-100 px-5 py-4">
                        <div className="flex items-center gap-2">
                            <TrendingUp className="h-4 w-4 text-zinc-500" />
                            <h2 className="text-sm font-semibold text-zinc-900">Frequently bought with</h2>
                        </div>
                        <p className="mt-0.5 text-xs text-zinc-500">
                            Pairs computed weekly over the last 90 days. Confidence = P(B | A). Lift {'>'} 1 means a real association, not coincidence.
                            {hero.has_cogs && ' Margin lift = expected € margin from adding B to a basket containing A.'}
                        </p>
                    </header>
                    {fbt.length === 0 ? (
                        <div className="px-5 py-8 text-center text-sm text-zinc-400">
                            No pairs found yet. Needs at least 3 co-occurring orders in the last 90 days.
                        </div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left th-label">
                                    <th className="px-5 py-3">Product</th>
                                    <th className="px-3 py-3 text-right">Confidence</th>
                                    <th className="px-3 py-3 text-right">Support</th>
                                    <th className="px-3 py-3 text-right">Lift</th>
                                    {hero.has_cogs && <th className="px-3 py-3 text-right">Margin lift</th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100">
                                {fbt.map(row => (
                                    <tr key={row.product_id} className="hover:bg-zinc-50">
                                        <td className="px-5 py-3">
                                            <Link
                                                href={wurl(workspace?.slug, `/analytics/products/${row.product_id}`)}
                                                className="flex items-center gap-3"
                                            >
                                                {row.image_url ? (
                                                    <img src={row.image_url} alt="" className="h-8 w-8 rounded object-cover" loading="lazy" />
                                                ) : (
                                                    <div className="flex h-8 w-8 items-center justify-center rounded bg-zinc-100">
                                                        <Package className="h-4 w-4 text-zinc-300" />
                                                    </div>
                                                )}
                                                <span className="truncate font-medium text-zinc-800">{row.name}</span>
                                            </Link>
                                        </td>
                                        <td className="px-3 py-3 text-right tabular-nums text-zinc-700">
                                            {(row.confidence * 100).toFixed(1)}%
                                        </td>
                                        <td className="px-3 py-3 text-right tabular-nums text-zinc-500">
                                            {(row.support * 100).toFixed(2)}%
                                        </td>
                                        <td className="px-3 py-3 text-right tabular-nums font-medium text-zinc-800">
                                            {row.lift.toFixed(2)}×
                                        </td>
                                        {hero.has_cogs && (
                                            <td className="px-3 py-3 text-right tabular-nums font-medium text-green-700">
                                                {row.margin_lift !== null ? formatCurrency(row.margin_lift, currency) : '—'}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>

                {/* ── Variation breakdown + source mix ────────────────────────── */}
                <div className="grid gap-5 md:grid-cols-2">
                    <section className="rounded-xl border border-zinc-200 bg-white">
                        <header className="border-b border-zinc-100 px-5 py-4">
                            <h2 className="text-sm font-semibold text-zinc-900">Variation breakdown</h2>
                            <p className="mt-0.5 text-xs text-zinc-500">Line items grouped by variant name over the last 90 days.</p>
                        </header>
                        {variants.length === 0 ? (
                            <div className="px-5 py-8 text-center text-sm text-zinc-400">No variant data.</div>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left th-label">
                                        <th className="px-5 py-2.5">Variant</th>
                                        <th className="px-3 py-2.5 text-right">Units</th>
                                        <th className="px-3 py-2.5 text-right">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-zinc-100">
                                    {variants.map((v, i) => (
                                        <tr key={`${v.variant_name}-${v.sku ?? i}`}>
                                            <td className="px-5 py-2.5 text-zinc-800">
                                                <div>{v.variant_name}</div>
                                                {v.sku && <div className="text-xs text-zinc-500">{v.sku}</div>}
                                            </td>
                                            <td className="px-3 py-2.5 text-right tabular-nums text-zinc-700">{formatNumber(v.units)}</td>
                                            <td className="px-3 py-2.5 text-right tabular-nums text-zinc-700">
                                                {v.revenue !== null ? formatCurrency(v.revenue, currency) : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>

                    <section className="rounded-xl border border-zinc-200 bg-white">
                        <header className="border-b border-zinc-100 px-5 py-4">
                            <h2 className="text-sm font-semibold text-zinc-900">Attributed source mix</h2>
                            <p className="mt-0.5 text-xs text-zinc-500">Where orders for this product came from (last-touch channel).</p>
                        </header>
                        {sources.length === 0 ? (
                            <div className="px-5 py-8 text-center text-sm text-zinc-400">No source data.</div>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left th-label">
                                        <th className="px-5 py-2.5">Channel</th>
                                        <th className="px-3 py-2.5 text-right">Orders</th>
                                        <th className="px-3 py-2.5 text-right">Revenue</th>
                                        <th className="px-3 py-2.5 text-right">Share</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-zinc-100">
                                    {sources.map(s => (
                                        <tr key={s.channel_type}>
                                            <td className="px-5 py-2.5 text-zinc-800">{CHANNEL_LABEL[s.channel_type] ?? s.channel_type}</td>
                                            <td className="px-3 py-2.5 text-right tabular-nums text-zinc-700">{formatNumber(s.orders)}</td>
                                            <td className="px-3 py-2.5 text-right tabular-nums text-zinc-700">{formatCurrency(s.revenue, currency)}</td>
                                            <td className="px-3 py-2.5 text-right tabular-nums text-zinc-500">
                                                {totalSourceRevenue > 0 ? `${((s.revenue / totalSourceRevenue) * 100).toFixed(0)}%` : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>
                </div>

                {/* ── Recent orders ───────────────────────────────────────────── */}
                <section className="rounded-xl border border-zinc-200 bg-white">
                    <header className="border-b border-zinc-100 px-5 py-4">
                        <h2 className="text-sm font-semibold text-zinc-900">Recent orders</h2>
                        <p className="mt-0.5 text-xs text-zinc-500">Last 10 orders containing this product.</p>
                    </header>
                    {recent_orders.length === 0 ? (
                        <div className="px-5 py-8 text-center text-sm text-zinc-400">No orders yet.</div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left th-label">
                                    <th className="px-5 py-2.5">Order</th>
                                    <th className="px-3 py-2.5">Date</th>
                                    <th className="px-3 py-2.5 text-right">Qty</th>
                                    <th className="px-3 py-2.5 text-right">Line total</th>
                                    <th className="px-3 py-2.5 text-right">Order total</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100">
                                {recent_orders.map(o => (
                                    <tr key={o.id} className="hover:bg-zinc-50">
                                        <td className="px-5 py-2.5">
                                            <Link
                                                href={wurl(workspace?.slug, `/orders/${o.id}`)}
                                                className="font-medium text-zinc-800 hover:text-zinc-900"
                                            >
                                                #{o.external_number ?? o.external_id}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2.5 text-zinc-600">{formatDate(o.occurred_at)}</td>
                                        <td className="px-3 py-2.5 text-right tabular-nums text-zinc-700">{o.qty}</td>
                                        <td className="px-3 py-2.5 text-right tabular-nums text-zinc-700">
                                            {formatCurrency(o.line_total, o.currency)}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums text-zinc-500">
                                            {formatCurrency(o.total, o.currency)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

function Metric({
    label,
    value,
    hint,
    tone,
}: {
    label: string;
    value: string;
    hint?: string;
    tone?: 'positive' | 'negative';
}) {
    const toneCls = tone === 'positive' ? 'text-green-700' : tone === 'negative' ? 'text-red-600' : 'text-zinc-900';
    return (
        <div>
            <p className="text-xs font-medium text-zinc-500">{label}</p>
            <p className={`mt-1 text-xl font-semibold tabular-nums ${toneCls}`}>{value}</p>
            {hint && <p className="mt-0.5 text-xs text-zinc-400">{hint}</p>}
        </div>
    );
}
