import { useCallback, useEffect } from 'react';
import { router } from '@inertiajs/react';

export type Granularity = 'hourly' | 'daily' | 'weekly';

export interface DateRange {
    from: string;
    to: string;
    compare_from?: string;
    compare_to?: string;
    granularity?: Granularity;
}

const STORAGE_KEY = 'nexstage_date_range';

function saveToStorage(range: DateRange): void {
    if (typeof window === 'undefined') return;
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(range));
    } catch {
        // Ignore storage errors (e.g. private browsing quota)
    }
}

function loadFromStorage(): DateRange | null {
    if (typeof window === 'undefined') return null;
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (!stored) return null;
        return JSON.parse(stored) as DateRange;
    } catch {
        return null;
    }
}

function getDefaultRange(): DateRange {
    const today = new Date();
    const to = today.toISOString().slice(0, 10);
    const from = new Date(today.getTime() - 29 * 86400000).toISOString().slice(0, 10);
    return { from, to, granularity: 'daily' };
}

export function useDateRange(): {
    range: DateRange;
    setRange: (newRange: Partial<DateRange>) => void;
} {
    const params = new URLSearchParams(
        typeof window !== 'undefined' ? window.location.search : '',
    );

    const stored = loadFromStorage();
    const defaults = getDefaultRange();

    const range: DateRange = {
        from: params.get('from') ?? stored?.from ?? defaults.from,
        to: params.get('to') ?? stored?.to ?? defaults.to,
        compare_from: params.get('compare_from') ?? stored?.compare_from ?? undefined,
        compare_to: params.get('compare_to') ?? stored?.compare_to ?? undefined,
        granularity: (params.get('granularity') as Granularity) ?? stored?.granularity ?? 'daily',
    };

    // If the URL has no date params but we have a stored range, redirect immediately
    // so the backend receives the correct dates and renders the right data.
    const urlHasDates = params.has('from') && params.has('to');
    useEffect(() => {
        if (urlHasDates || !stored) return;
        const currentParams = new URLSearchParams(window.location.search);
        const dateKeys = new Set(['from', 'to', 'granularity', 'compare_from', 'compare_to']);
        const query: Record<string, string> = {};
        currentParams.forEach((value, key) => {
            if (!dateKeys.has(key)) query[key] = value;
        });
        query.from = range.from;
        query.to = range.to;
        if (range.granularity) query.granularity = range.granularity;
        if (range.compare_from) query.compare_from = range.compare_from;
        if (range.compare_to) query.compare_to = range.compare_to;
        router.get(window.location.pathname, query, { preserveState: true, replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [window.location.pathname]);

    const setRange = useCallback(
        (newRange: Partial<DateRange>) => {
            const merged = { ...range, ...newRange };

            // Preserve non-date URL params (e.g. sort, sort_dir, property_id)
            const currentParams = new URLSearchParams(window.location.search);
            const dateKeys = new Set(['from', 'to', 'granularity', 'compare_from', 'compare_to']);
            const query: Record<string, string> = {};
            currentParams.forEach((value, key) => {
                if (!dateKeys.has(key)) query[key] = value;
            });

            query.from = merged.from;
            query.to = merged.to;
            if (merged.granularity) query.granularity = merged.granularity;
            if (merged.compare_from) query.compare_from = merged.compare_from;
            if (merged.compare_to) query.compare_to = merged.compare_to;

            saveToStorage(merged);
            router.get(window.location.pathname, query, { preserveState: true });
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [range.from, range.to, range.compare_from, range.compare_to, range.granularity],
    );

    return { range, setRange };
}
