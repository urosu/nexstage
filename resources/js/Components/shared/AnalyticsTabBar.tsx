import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

/** Keys preserved when switching between analytics tabs */
const CARRY_KEYS = ['from', 'to', 'compare_from', 'compare_to', 'granularity', 'store_ids'] as const;

function buildHref(base: string, params: URLSearchParams, keys: readonly string[]): string {
    const p = new URLSearchParams();
    for (const key of keys) {
        const val = params.get(key);
        if (val) p.set(key, val);
    }
    const qs = p.toString();
    return `${base}${qs ? `?${qs}` : ''}`;
}

export function AnalyticsTabBar() {
    const { workspace } = usePage<PageProps>().props;
    const w = (path: string) => wurl(workspace?.slug, path);

    if (typeof window === 'undefined') return null;

    const pathname = window.location.pathname;
    const params = new URLSearchParams(window.location.search);

    const TABS = [
        {
            key: 'daily',
            label: 'Daily Report',
            href: buildHref(w('/analytics/daily'), params, ['from', 'to', 'store_ids']),
            matchPaths: [w('/analytics/daily')],
        },
        {
            key: 'countries',
            label: 'By Country',
            href: buildHref(w('/countries'), params, ['from', 'to', 'store_ids']),
            matchPaths: [w('/countries')],
        },
        {
            key: 'products',
            label: 'By Product',
            href: buildHref(w('/analytics/products'), params, ['from', 'to', 'store_ids']),
            matchPaths: [w('/analytics/products')],
        },
        {
            key: 'winners',
            label: 'Winners & Losers',
            href: buildHref(w('/analytics/winners'), params, ['from', 'to']),
            matchPaths: [w('/analytics/winners')],
        },
    ];

    return (
        <div className="mb-6 flex border-b border-zinc-200">
            {TABS.map((tab) => {
                const active = tab.matchPaths.some((p) => pathname === p || pathname.startsWith(p + '/'));
                return (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        className={cn(
                            'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
                            active
                                ? 'border-primary text-primary'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 hover:border-zinc-300',
                        )}
                    >
                        {tab.label}
                    </Link>
                );
            })}
        </div>
    );
}
