import { X, MapPin, ShoppingCart } from 'lucide-react';
import { formatCurrency, formatDatetime } from '@/lib/formatters';
import { cn } from '@/lib/utils';

// ── Types ─────────────────────────────────────────────────────────────────────

export interface TouchPoint {
    source: string | null;
    medium: string | null;
    campaign: string | null;
    channel: string | null;
    channel_type: string | null;
    timestamp: string | null;
    landing_page: string | null;
}

export interface ClickIds {
    fbc?: string | null;
    fbp?: string | null;
    gclid?: string | null;
    msclkid?: string | null;
}

export interface JourneyOrder {
    id: number;
    occurred_at: string;
    revenue: number;
    is_first_for_customer: boolean;
    customer_email_hash: string | null;
    attribution_first_touch: TouchPoint | null;
    attribution_last_touch: TouchPoint | null;
    attribution_click_ids: ClickIds | null;
}

interface Props {
    order: JourneyOrder;
    currency: string;
    open: boolean;
    onClose: () => void;
}

// ── Channel colors ────────────────────────────────────────────────────────────

const CHANNEL_COLORS: Record<string, string> = {
    paid_search:    'bg-violet-100 text-violet-700',
    paid_social:    'bg-blue-100 text-blue-700',
    organic_search: 'bg-green-100 text-green-700',
    organic_social: 'bg-emerald-100 text-emerald-700',
    email:          'bg-amber-100 text-amber-700',
    direct:         'bg-zinc-100 text-zinc-600',
    referral:       'bg-zinc-100 text-zinc-600',
};

function channelChip(touch: TouchPoint | null) {
    if (!touch?.channel_type) return null;
    const colorCls = CHANNEL_COLORS[touch.channel_type] ?? 'bg-zinc-100 text-zinc-600';
    return (
        <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium', colorCls)}>
            {touch.channel ?? touch.channel_type}
        </span>
    );
}

// ── Touch row ────────────────────────────────────────────────────────────────

function TouchRow({
    label,
    touch,
    icon,
    clickIds,
}: {
    label: string;
    touch: TouchPoint | null;
    icon: React.ReactNode;
    clickIds?: ClickIds | null;
}) {
    if (!touch) {
        return (
            <div className="flex items-start gap-3 py-3">
                <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-400">
                    {icon}
                </div>
                <div>
                    <div className="text-xs font-medium text-zinc-500">{label}</div>
                    <div className="mt-0.5 text-xs text-zinc-400 italic">No data recorded for this order</div>
                </div>
            </div>
        );
    }

    // Collect non-null click IDs for supplemental metadata
    const clickIdEntries = clickIds
        ? (Object.entries(clickIds) as [string, string | null | undefined][]).filter(
            ([, v]) => v != null && v !== '',
          )
        : [];

    return (
        <div className="flex items-start gap-3 py-3">
            <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-zinc-100">
                {icon}
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-1.5 flex-wrap">
                    <div className="text-xs font-medium text-zinc-700">{label}</div>
                    {channelChip(touch)}
                </div>
                <dl className="mt-1 space-y-0.5 text-xs text-zinc-500">
                    {touch.source && (
                        <div className="flex gap-1.5">
                            <dt className="text-zinc-400 shrink-0">Source</dt>
                            <dd className="truncate">{touch.source}</dd>
                        </div>
                    )}
                    {touch.medium && (
                        <div className="flex gap-1.5">
                            <dt className="text-zinc-400 shrink-0">Medium</dt>
                            <dd className="truncate">{touch.medium}</dd>
                        </div>
                    )}
                    {touch.campaign && (
                        <div className="flex gap-1.5">
                            <dt className="text-zinc-400 shrink-0">Campaign</dt>
                            <dd className="truncate" title={touch.campaign}>{touch.campaign}</dd>
                        </div>
                    )}
                    {touch.landing_page && (
                        <div className="flex gap-1.5">
                            <dt className="text-zinc-400 shrink-0">Landing</dt>
                            <dd className="truncate text-[11px]" title={touch.landing_page}>
                                {touch.landing_page.replace(/^https?:\/\/[^/]+/, '')}
                            </dd>
                        </div>
                    )}
                    {touch.timestamp && (
                        <div className="flex gap-1.5">
                            <dt className="text-zinc-400 shrink-0">Time</dt>
                            <dd>{formatDatetime ? formatDatetime(touch.timestamp) : touch.timestamp}</dd>
                        </div>
                    )}
                </dl>

                {/* Supplemental click ID metadata — not separate timeline steps */}
                {clickIdEntries.length > 0 && (
                    <details className="mt-2">
                        <summary className="cursor-pointer text-[11px] text-zinc-400 hover:text-zinc-600">
                            Supplemental metadata ({clickIdEntries.length})
                        </summary>
                        <dl className="mt-1 space-y-0.5 text-[11px] text-zinc-400">
                            {clickIdEntries.map(([key, val]) => (
                                <div key={key} className="flex gap-1.5">
                                    <dt className="shrink-0 uppercase">{key}</dt>
                                    <dd className="truncate font-mono text-[10px]">{val}</dd>
                                </div>
                            ))}
                        </dl>
                    </details>
                )}
            </div>
        </div>
    );
}

// ── Main component ────────────────────────────────────────────────────────────

/**
 * Journey timeline modal — shows first touch → last touch for a single order.
 *
 * Click IDs (fbc, fbp, gclid, msclkid) are shown as supplemental metadata on
 * the last-touch row, not as separate timeline steps (per locked decision).
 *
 * Tooltip: "Showing first and last recorded touch. Full multi-touch path requires
 * Phase 6 plugin."
 */
export function JourneyTimeline({ order, currency, open, onClose }: Props) {
    if (!open) return null;

    const isSingleTouch =
        order.attribution_first_touch &&
        order.attribution_last_touch &&
        order.attribution_first_touch.source === order.attribution_last_touch.source &&
        order.attribution_first_touch.medium === order.attribution_last_touch.medium &&
        order.attribution_first_touch.campaign === order.attribution_last_touch.campaign;

    const customerLabel = order.customer_email_hash
        ? `customer-${order.customer_email_hash.slice(0, 8)}…`
        : 'anonymous';

    return (
        /* Backdrop */
        <div
            className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
            onClick={onClose}
        >
            <div className="absolute inset-0 bg-black/40" />

            {/* Panel */}
            <div
                className="relative z-10 w-full max-w-md rounded-t-2xl sm:rounded-2xl bg-white shadow-xl max-h-[90vh] overflow-y-auto"
                onClick={(e) => e.stopPropagation()}
            >
                {/* Header */}
                <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4">
                    <div>
                        <div className="text-sm font-semibold text-zinc-900">
                            {formatCurrency(order.revenue, currency)} order
                            {order.is_first_for_customer && (
                                <span className="ml-2 rounded-full bg-green-50 px-2 py-0.5 text-[11px] font-medium text-green-700 border border-green-200">
                                    New customer
                                </span>
                            )}
                        </div>
                        <div className="mt-0.5 text-xs text-zinc-400">{customerLabel}</div>
                    </div>
                    <button onClick={onClose} className="rounded-full p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600">
                        <X className="h-4 w-4" />
                    </button>
                </div>

                {/* Timeline */}
                <div className="px-5">
                    <div className="divide-y divide-zinc-100">
                        {isSingleTouch ? (
                            /* Single-touch order */
                            <TouchRow
                                label="Direct Purchase (single touch)"
                                touch={order.attribution_last_touch}
                                icon={<ShoppingCart className="h-3.5 w-3.5 text-zinc-500" />}
                                clickIds={order.attribution_click_ids}
                            />
                        ) : (
                            <>
                                <TouchRow
                                    label="First Touch"
                                    touch={order.attribution_first_touch}
                                    icon={<MapPin className="h-3.5 w-3.5 text-zinc-500" />}
                                />
                                <TouchRow
                                    label="Last Touch → Purchase"
                                    touch={order.attribution_last_touch}
                                    icon={<ShoppingCart className="h-3.5 w-3.5 text-zinc-500" />}
                                    clickIds={order.attribution_click_ids}
                                />
                            </>
                        )}
                    </div>
                </div>

                {/* Footer disclaimer */}
                <div className="border-t border-zinc-100 px-5 py-3">
                    <p className="text-[11px] text-zinc-400">
                        Showing first and last recorded touch. Full multi-touch path requires Phase 6 plugin.
                    </p>
                </div>
            </div>
        </div>
    );
}
