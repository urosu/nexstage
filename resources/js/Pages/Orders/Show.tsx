import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatCurrency } from '@/lib/formatters';
import type { PageProps } from '@/types';

/**
 * Order detail — shows totals, line items, refunds, and the full attribution
 * journey (first-touch, last-touch, click IDs, source badge).
 *
 * Reads from `orders.attribution_*` JSONB written by AttributionParserService.
 * Linked from the dashboard "Latest orders" feed.
 *
 * @see PLANNING.md Phase 1.6 "Order detail page with attribution journey"
 */

interface Touch {
    source?: string | null;
    medium?: string | null;
    campaign?: string | null;
    content?: string | null;
    term?: string | null;
    landing_page?: string | null;
    referrer?: string | null;
    channel?: string | null;
    channel_type?: string | null;
    [k: string]: string | null | undefined;
}

interface OrderData {
    id: number;
    external_id: string;
    external_number: string | null;
    status: string;
    currency: string;
    total: number;
    subtotal: number;
    tax: number;
    shipping: number;
    discount: number;
    refund_amount: number;
    customer_country: string | null;
    shipping_country: string | null;
    payment_method_title: string | null;
    occurred_at: string | null;
    store: { id: number; name: string; slug: string } | null;
    utm_source: string | null;
    utm_medium: string | null;
    utm_campaign: string | null;
    utm_content: string | null;
    utm_term: string | null;
    attribution_source: string | null;
    attribution_first_touch: Touch | null;
    attribution_last_touch: Touch | null;
    attribution_click_ids: Record<string, string> | null;
    attribution_parsed_at: string | null;
    cogs_note: string | null;
}

interface ItemRow {
    id: number;
    product_name: string | null;
    variant_name: string | null;
    sku: string | null;
    quantity: number;
    unit_price: number;
    unit_cost: number | null;
    discount_amount: number;
    line_total: number;
}

interface RefundRow {
    id: number;
    amount: number;
    reason: string | null;
    refunded_at: string | null;
}

interface Props extends PageProps {
    order: OrderData;
    items: ItemRow[];
    refunds: RefundRow[];
}

// ─── Source badge ────────────────────────────────────────────────────────────

// The six Nexstage source badges (see PLANNING.md trust thesis). For an order,
// "Real" and "Site" are not meaningful — we only show the parser source_type
// that matched, which is one of store / facebook / google / direct / other.
function sourceBadgeFor(sourceType: string | null): { label: string; className: string } {
    if (!sourceType) {
        return { label: 'Not Tracked', className: 'bg-zinc-100 text-zinc-600 border-zinc-200' };
    }
    const map: Record<string, { label: string; className: string }> = {
        facebook_ads: { label: 'Facebook', className: 'bg-blue-50 text-blue-700 border-blue-200' },
        facebook:     { label: 'Facebook', className: 'bg-blue-50 text-blue-700 border-blue-200' },
        google_ads:   { label: 'Google',   className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
        google:       { label: 'Google',   className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
        google_organic: { label: 'GSC',    className: 'bg-teal-50 text-teal-700 border-teal-200' },
        store:        { label: 'Store',    className: 'bg-amber-50 text-amber-700 border-amber-200' },
        direct:       { label: 'Direct',   className: 'bg-zinc-50 text-zinc-700 border-zinc-200' },
        email:        { label: 'Email',    className: 'bg-purple-50 text-purple-700 border-purple-200' },
    };
    return map[sourceType] ?? { label: sourceType, className: 'bg-zinc-50 text-zinc-700 border-zinc-200' };
}

function TouchTable({ label, touch }: { label: string; touch: Touch | null }) {
    if (!touch || Object.values(touch).every(v => v == null || v === '')) {
        return (
            <div>
                <p className="text-xs font-medium text-zinc-500 mb-1">{label}</p>
                <p className="text-xs text-zinc-400 italic">no data</p>
            </div>
        );
    }
    const entries = Object.entries(touch).filter(([, v]) => v != null && v !== '');
    return (
        <div>
            <p className="text-xs font-medium text-zinc-500 mb-1">{label}</p>
            <dl className="space-y-0.5">
                {entries.map(([k, v]) => (
                    <div key={k} className="flex gap-2 text-xs">
                        <dt className="text-zinc-500 w-28 shrink-0">{k}</dt>
                        <dd className="font-mono text-zinc-800 break-all">{String(v)}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

export default function OrderShow({ order, items, refunds, workspace }: Props) {
    const badge = sourceBadgeFor(order.attribution_source);
    const net = order.total - order.refund_amount;

    return (
        <AppLayout>
            <Head title={`Order #${order.external_number ?? order.external_id}`} />

            <div className="max-w-5xl mx-auto px-4 py-6 space-y-6">
                <PageHeader
                    title={`Order #${order.external_number ?? order.external_id}`}
                    subtitle={`${order.store?.name ?? '—'} · ${formatDate(order.occurred_at)} · ${order.status}`}
                />

                {/* Totals + attribution source at a glance */}
                <section className="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div className="rounded-lg border border-zinc-200 bg-white p-4">
                        <p className="text-xs font-medium text-zinc-500">Total</p>
                        <p className="mt-1 text-xl font-semibold tabular-nums text-zinc-900">
                            {formatCurrency(order.total, order.currency)}
                        </p>
                        {order.refund_amount > 0 && (
                            <p className="mt-0.5 text-xs text-zinc-500">
                                Net {formatCurrency(net, order.currency)} (refund {formatCurrency(order.refund_amount, order.currency)})
                            </p>
                        )}
                    </div>
                    <div className="rounded-lg border border-zinc-200 bg-white p-4">
                        <p className="text-xs font-medium text-zinc-500">Subtotal / Tax / Ship</p>
                        <p className="mt-1 text-sm tabular-nums text-zinc-800">
                            {formatCurrency(order.subtotal, order.currency)}
                        </p>
                        <p className="text-xs text-zinc-500">
                            +{formatCurrency(order.tax, order.currency)} tax · {formatCurrency(order.shipping, order.currency)} ship
                        </p>
                    </div>
                    <div className="rounded-lg border border-zinc-200 bg-white p-4">
                        <p className="text-xs font-medium text-zinc-500">Customer</p>
                        <p className="mt-1 text-sm text-zinc-800">
                            {order.customer_country ?? order.shipping_country ?? '—'}
                        </p>
                        <p className="text-xs text-zinc-500">
                            {order.payment_method_title ?? 'payment —'}
                        </p>
                    </div>
                    <div className="rounded-lg border border-zinc-200 bg-white p-4">
                        <p className="text-xs font-medium text-zinc-500">Attribution source</p>
                        <div className="mt-1">
                            <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${badge.className}`}>
                                {badge.label}
                            </span>
                        </div>
                        {order.attribution_parsed_at && (
                            <p className="mt-1 text-[10px] text-zinc-400">
                                parsed {formatDate(order.attribution_parsed_at)}
                            </p>
                        )}
                    </div>
                </section>

                {/* Attribution journey */}
                <section className="rounded-lg border border-zinc-200 bg-white p-5">
                    <h2 className="text-sm font-semibold text-zinc-900">Attribution journey</h2>
                    <p className="mt-0.5 text-xs text-zinc-500">
                        From <code className="font-mono">orders.attribution_*</code>, written by AttributionParserService during order sync.
                    </p>

                    <div className="mt-4 grid grid-cols-1 gap-6 md:grid-cols-2">
                        <TouchTable label="First touch" touch={order.attribution_first_touch} />
                        <TouchTable label="Last touch"  touch={order.attribution_last_touch} />
                    </div>

                    {order.attribution_click_ids && Object.keys(order.attribution_click_ids).length > 0 && (
                        <div className="mt-4">
                            <p className="text-xs font-medium text-zinc-500 mb-1">Click IDs</p>
                            <dl className="space-y-0.5">
                                {Object.entries(order.attribution_click_ids).map(([k, v]) => (
                                    <div key={k} className="flex gap-2 text-xs">
                                        <dt className="text-zinc-500 w-28 shrink-0">{k}</dt>
                                        <dd className="font-mono text-zinc-800 break-all">{v}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    )}

                    {/* Parser input fields — UTM columns kept for transparency during rollout */}
                    {(order.utm_source || order.utm_medium || order.utm_campaign) && (
                        <details className="mt-4">
                            <summary className="cursor-pointer text-xs font-medium text-zinc-500 hover:text-zinc-700">
                                Parser inputs (legacy utm_* columns)
                            </summary>
                            <dl className="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs">
                                {([
                                    ['utm_source', order.utm_source],
                                    ['utm_medium', order.utm_medium],
                                    ['utm_campaign', order.utm_campaign],
                                    ['utm_content', order.utm_content],
                                    ['utm_term', order.utm_term],
                                ] as const).map(([k, v]) => (
                                    <div key={k} className="contents">
                                        <dt className="text-zinc-500">{k}</dt>
                                        <dd className="font-mono text-zinc-800">
                                            {v ?? <span className="italic text-zinc-400">null</span>}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </details>
                    )}
                </section>

                {/* Line items */}
                <section className="rounded-lg border border-zinc-200 bg-white">
                    <div className="border-b border-zinc-200 px-5 py-3">
                        <h2 className="text-sm font-semibold text-zinc-900">Line items ({items.length})</h2>
                    </div>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-zinc-100 text-xs font-medium text-zinc-500">
                                <th className="px-5 py-2 text-left">Product</th>
                                <th className="px-3 py-2 text-right">Qty</th>
                                <th className="px-3 py-2 text-right">Unit price</th>
                                <th className="px-3 py-2 text-right">Unit cost</th>
                                <th className="px-5 py-2 text-right">Line total</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {items.map(item => (
                                <tr key={item.id}>
                                    <td className="px-5 py-2.5">
                                        <div className="text-zinc-900">{item.product_name ?? '—'}</div>
                                        {(item.variant_name || item.sku) && (
                                            <div className="text-xs text-zinc-500">
                                                {item.variant_name}{item.variant_name && item.sku ? ' · ' : ''}{item.sku}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums text-zinc-800">{item.quantity}</td>
                                    <td className="px-3 py-2.5 text-right tabular-nums text-zinc-800">
                                        {formatCurrency(item.unit_price, order.currency)}
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums text-zinc-500">
                                        {item.unit_cost !== null ? (
                                            formatCurrency(item.unit_cost, order.currency)
                                        ) : order.cogs_note === 'pre_snapshot' ? (
                                            <span className="inline-flex items-center gap-1">
                                                <span className="text-zinc-400">—</span>
                                                <span className="rounded bg-amber-100 px-1 py-0.5 text-[10px] font-medium text-amber-700" title="No inventory cost snapshot before this order date">Est.</span>
                                            </span>
                                        ) : '—'}
                                    </td>
                                    <td className="px-5 py-2.5 text-right tabular-nums font-medium text-zinc-900">
                                        {formatCurrency(item.line_total, order.currency)}
                                    </td>
                                </tr>
                            ))}
                            {items.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-5 py-6 text-center text-sm text-zinc-500">
                                        No line items recorded for this order.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </section>

                {/* Refunds */}
                {refunds.length > 0 && (
                    <section className="rounded-lg border border-zinc-200 bg-white">
                        <div className="border-b border-zinc-200 px-5 py-3">
                            <h2 className="text-sm font-semibold text-zinc-900">Refunds ({refunds.length})</h2>
                        </div>
                        <ul className="divide-y divide-zinc-100">
                            {refunds.map(r => (
                                <li key={r.id} className="flex items-center justify-between px-5 py-2.5 text-sm">
                                    <div>
                                        <span className="tabular-nums font-medium text-zinc-900">
                                            {formatCurrency(r.amount, order.currency)}
                                        </span>
                                        {r.reason && <span className="ml-2 text-xs text-zinc-500">{r.reason}</span>}
                                    </div>
                                    <span className="text-xs text-zinc-500">{formatDate(r.refunded_at)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {order.store && workspace && (
                    <div className="text-xs">
                        <Link
                            href={`/${workspace.slug}/stores/${order.store.slug}/overview`}
                            className="text-primary hover:text-primary/80"
                        >
                            ← Back to {order.store.name}
                        </Link>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
