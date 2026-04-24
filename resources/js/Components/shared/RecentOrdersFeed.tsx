import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { formatCurrency } from '@/lib/formatters';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

export interface RecentOrder {
    id: number;
    order_number: string;
    status: string;
    total: number;
    currency: string;
    occurred_at: string;
}

export interface RecentOrdersData {
    orders: RecentOrder[];
    feed_source: 'webhook' | 'polling';
    last_synced_at: string | null;
}

function relativeTime(iso: string): string {
    const diff  = Date.now() - new Date(iso).getTime();
    const mins  = Math.floor(diff / 60_000);
    const hours = Math.floor(mins / 60);
    const days  = Math.floor(hours / 24);
    if (mins < 1)   return 'just now';
    if (mins < 60)  return `${mins}m ago`;
    if (hours < 24) return `${hours}h ago`;
    return `${days}d ago`;
}

interface Props {
    feed: RecentOrdersData | null;
    currency: string;
}

/**
 * Recent orders feed — "the business is alive" pulse on the Home page.
 *
 * Shows the last 5 processing/completed orders. Webhook-sourced stores get a
 * live indicator; polling-only stores see a nudge to enable webhooks.
 *
 * Extracted from Dashboard.tsx LatestOrdersFeed (was inline, Phase 1.4).
 * @see PROGRESS.md §Phase 3.6 — Home rebuild — RecentOrdersFeed
 */
export function RecentOrdersFeed({ feed, currency }: Props) {
    const { props } = usePage<PageProps>();
    const workspaceSlug = (props as any).workspace?.slug as string | undefined;

    if (!feed) return null;

    if (feed.feed_source === 'polling') {
        const lastSync = feed.last_synced_at ? relativeTime(feed.last_synced_at) : null;
        return (
            <div className="rounded-lg border border-zinc-100 bg-zinc-50 px-4 py-3">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-1.5 text-xs font-medium text-zinc-500">
                        <span className="h-1.5 w-1.5 rounded-full bg-amber-400" />
                        {lastSync ? `Synced ${lastSync}` : 'Polling mode'}
                    </div>
                    <Link
                        href={wurl(workspaceSlug, '/settings/integrations')}
                        className="text-xs font-medium text-primary hover:text-primary/80"
                    >
                        Enable webhooks for live orders →
                    </Link>
                </div>
            </div>
        );
    }

    if (feed.orders.length === 0) {
        return (
            <div className="rounded-lg border border-zinc-100 bg-zinc-50 px-4 py-4 text-center text-xs text-zinc-400">
                No recent orders
            </div>
        );
    }

    return (
        <div className="overflow-hidden rounded-lg border border-zinc-100 bg-zinc-50">
            <div className="flex items-center justify-between border-b border-zinc-100 px-4 py-2">
                <div className="flex items-center gap-1.5 text-xs font-medium text-zinc-500">
                    <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-green-400" />
                    Live via webhook
                </div>
                <span className="text-[10px] text-zinc-400">{feed.orders.length} recent orders</span>
            </div>
            <div className="divide-y divide-zinc-100">
                {feed.orders.slice(0, 5).map((order) => (
                    <Link
                        key={order.id}
                        href={wurl(workspaceSlug, `/orders/${order.id}`)}
                        className="flex items-center justify-between px-4 py-2 transition-colors hover:bg-white"
                    >
                        <div className="flex min-w-0 items-center gap-2">
                            <span className="shrink-0 text-xs font-medium text-zinc-700">
                                #{order.order_number}
                            </span>
                            <span
                                className={cn(
                                    'rounded-full px-1.5 py-0.5 text-[10px] font-medium capitalize',
                                    order.status === 'completed'
                                        ? 'bg-green-50 text-green-700'
                                        : 'bg-zinc-100 text-zinc-500',
                                )}
                            >
                                {order.status}
                            </span>
                        </div>
                        <div className="flex items-center gap-3">
                            <span className="tabular-nums text-xs font-semibold text-zinc-900">
                                {formatCurrency(order.total, currency)}
                            </span>
                            <span className="shrink-0 text-[10px] text-zinc-400">
                                {relativeTime(order.occurred_at)}
                            </span>
                        </div>
                    </Link>
                ))}
            </div>
        </div>
    );
}
