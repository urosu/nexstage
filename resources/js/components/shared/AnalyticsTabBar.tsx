import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

interface Tab {
    key: string;
    label: string;
    href: (params: URLSearchParams) => string;
    matchPaths: string[];
}

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

const TABS: Tab[] = [
    {
        key: 'overview',
        label: 'Overview',
        href: (params) => buildHref('/dashboard', params, CARRY_KEYS),
        matchPaths: ['/dashboard'],
    },
    {
        key: 'daily',
        label: 'Daily Report',
        href: (params) => buildHref('/analytics/daily', params, ['from', 'to', 'store_ids']),
        matchPaths: ['/analytics/daily'],
    },
    {
        key: 'countries',
        label: 'By Country',
        href: (params) => buildHref('/countries', params, ['from', 'to', 'store_ids']),
        matchPaths: ['/countries'],
    },
    {
        key: 'products',
        label: 'By Product',
        href: (params) => buildHref('/analytics/products', params, ['from', 'to', 'store_ids']),
        matchPaths: ['/analytics/products'],
    },
];

export function AnalyticsTabBar() {
    if (typeof window === 'undefined') return null;

    const pathname = window.location.pathname;
    const params = new URLSearchParams(window.location.search);

    return (
        <div className="mb-6 flex border-b border-zinc-200">
            {TABS.map((tab) => {
                const active = tab.matchPaths.some((p) => pathname === p || pathname.startsWith(p + '/'));
                return (
                    <Link
                        key={tab.key}
                        href={tab.href(params)}
                        className={cn(
                            'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
                            active
                                ? 'border-indigo-600 text-indigo-600'
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
