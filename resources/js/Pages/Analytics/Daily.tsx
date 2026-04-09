import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, Calendar } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { AnalyticsTabBar } from '@/Components/shared/AnalyticsTabBar';
import { StoreFilter } from '@/Components/shared/StoreFilter';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

// ── Types ──────────────────────────────────────────────────────────────────

interface DailyRow {
    date: string;
    revenue: number;
    orders: number;
    items_sold: number;
    items_per_order: number | null;
    aov: number | null;
    ad_spend: number | null;
    roas: number | null;
    marketing_pct: number | null;
    note: string | null;
}

interface Totals {
    revenue: number;
    orders: number;
    items_sold: number;
    items_per_order: number | null;
    aov: number | null;
    ad_spend: number | null;
    roas: number | null;
    marketing_pct: number | null;
}

type SortBy =
    | 'date' | 'revenue' | 'orders' | 'items_sold'
    | 'items_per_order' | 'aov' | 'ad_spend' | 'roas' | 'marketing_pct';
type SortDir = 'asc' | 'desc';

interface Props {
    rows: DailyRow[];
    totals: Totals;
    from: string;
    to: string;
    store_ids: number[];
    sort_by: SortBy;
    sort_dir: SortDir;
    hide_empty: boolean;
}

// ── Sort header icon ────────────────────────────────────────────────────────

function SortIcon({ column, sortBy, sortDir }: { column: SortBy; sortBy: SortBy; sortDir: SortDir }) {
    if (column !== sortBy) return <ArrowUpDown className="ml-1 h-3 w-3 opacity-30 shrink-0" />;
    return sortDir === 'asc'
        ? <ArrowUp className="ml-1 h-3 w-3 text-indigo-500 shrink-0" />
        : <ArrowDown className="ml-1 h-3 w-3 text-indigo-500 shrink-0" />;
}

// ── Inline note cell ────────────────────────────────────────────────────────

interface NoteCellProps {
    date: string;
    initialNote: string | null;
}

function NoteCell({ date, initialNote }: NoteCellProps) {
    const [value, setValue] = useState(initialNote ?? '');
    const [saving, setSaving] = useState(false);
    const focusedRef = useRef(false);
    const lastSavedRef = useRef(initialNote ?? '');

    // Sync when props update (e.g. page navigation refreshes rows)
    useEffect(() => {
        if (!focusedRef.current) {
            const v = initialNote ?? '';
            setValue(v);
            lastSavedRef.current = v;
        }
    }, [initialNote]);

    function handleBlur(e: React.FocusEvent<HTMLInputElement>): void {
        focusedRef.current = false;
        // Read directly from the DOM — no stale closure risk
        const current = e.currentTarget.value;
        if (current === lastSavedRef.current) return;
        setSaving(true);
        axios
            .post(`/analytics/notes/${date}`, { note: current })
            .then(() => {
                lastSavedRef.current = current;
                setValue(current);
            })
            .catch(() => {
                setValue(lastSavedRef.current);
            })
            .finally(() => setSaving(false));
    }

    function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>): void {
        if (e.key === 'Enter') {
            e.currentTarget.blur();
        }
        if (e.key === 'Escape') {
            setValue(lastSavedRef.current);
            e.currentTarget.blur();
        }
    }

    return (
        <div className="relative group">
            <input
                type="text"
                value={value}
                onChange={(e) => setValue(e.target.value)}
                onFocus={() => { focusedRef.current = true; }}
                onBlur={handleBlur}
                onKeyDown={handleKeyDown}
                placeholder="Add note…"
                maxLength={1000}
                className={cn(
                    'w-full min-w-[160px] rounded border bg-transparent px-2 py-0.5 text-xs outline-none transition-colors',
                    'placeholder:text-zinc-300',
                    'focus:border-indigo-300 focus:bg-white focus:shadow-sm',
                    value ? 'border-transparent text-zinc-700' : 'border-transparent text-zinc-400',
                    'hover:border-zinc-200',
                )}
            />
            {saving && (
                <span className="absolute right-1 top-1/2 -translate-y-1/2 text-[10px] text-zinc-400">
                    saving…
                </span>
            )}
        </div>
    );
}

// ── Formatters ─────────────────────────────────────────────────────────────

function fmtDate(iso: string): string {
    const d = new Date(iso);
    const weekday = d.toLocaleDateString('en-GB', { weekday: 'short' });
    const day     = d.getDate();
    const month   = d.getMonth() + 1;
    const year    = String(d.getFullYear()).slice(-2);
    return `${weekday} ${day}.${month}.${year}`;
}

function fmtPct(v: number | null): string {
    return v !== null ? `${v.toFixed(1)}%` : '—';
}

function fmtRoas(v: number | null): string {
    return v !== null ? `${v.toFixed(2)}×` : '—';
}

function fmtIpo(v: number | null): string {
    return v !== null ? v.toFixed(2) : '—';
}

// ── Main component ──────────────────────────────────────────────────────────

export default function AnalyticsDaily({
    rows, totals, from, to, store_ids, sort_by, sort_dir, hide_empty,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const [navigating, setNavigating] = useState(false);

    useEffect(() => {
        const off1 = router.on('start',  () => setNavigating(true));
        const off2 = router.on('finish', () => setNavigating(false));
        return () => { off1(); off2(); };
    }, []);

    function buildParams(overrides: Record<string, string | undefined>): Record<string, string | undefined> {
        const base: Record<string, string | undefined> = { from, to };
        if (store_ids.length > 0) base.store_ids = store_ids.join(',');
        base.sort_by  = sort_by;
        base.sort_dir = sort_dir;
        if (hide_empty) base.hide_empty = '1';
        return { ...base, ...overrides };
    }

    function toggleHideEmpty(): void {
        const next = hide_empty ? '0' : '1';
        router.get('/analytics/daily', buildParams({ hide_empty: next }), {
            preserveState: true, replace: true,
        });
    }

    function sortByColumn(column: SortBy): void {
        const nextDir: SortDir = sort_by === column && sort_dir === 'desc' ? 'asc' : 'desc';
        router.get('/analytics/daily', buildParams({ sort_by: column, sort_dir: nextDir }), {
            preserveState: true, replace: true,
        });
    }

    const th = (column: SortBy, label: string, align: 'left' | 'right' = 'right') => (
        <th className={cn('px-3 py-2.5', align === 'right' ? 'text-right' : 'text-left')}>
            <button
                onClick={() => sortByColumn(column)}
                className={cn(
                    'flex items-center gap-0 text-xs font-medium text-zinc-400 hover:text-zinc-700 transition-colors whitespace-nowrap',
                    align === 'right' && 'ml-auto',
                )}
            >
                {label}
                <SortIcon column={column} sortBy={sort_by} sortDir={sort_dir} />
            </button>
        </th>
    );

    return (
        <AppLayout dateRangePicker={
            <>
                <DateRangePicker />
                <StoreFilter selectedStoreIds={store_ids} />
                <button
                    onClick={toggleHideEmpty}
                    className={cn(
                        'flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors whitespace-nowrap',
                        hide_empty
                            ? 'border-indigo-300 bg-indigo-50 text-indigo-700'
                            : 'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50',
                    )}
                >
                    Hide empty days
                </button>
            </>
        }>
            <Head title="Analytics — Daily Report" />
            <PageHeader title="Analytics" subtitle="Daily breakdown" />
            <AnalyticsTabBar />

            {navigating ? (
                <div className="space-y-1.5">
                    {[...Array(12)].map((_, i) => (
                        <div key={i} className="h-10 animate-pulse rounded-lg bg-zinc-100" />
                    ))}
                </div>
            ) : rows.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100">
                        <Calendar className="h-6 w-6 text-zinc-400" />
                    </div>
                    <h3 className="mb-1 text-base font-semibold text-zinc-900">No data for this period</h3>
                    <p className="max-w-xs text-sm text-zinc-500">
                        Daily data appears after the nightly snapshot job has run.
                    </p>
                </div>
            ) : (
                <div className="rounded-xl border border-zinc-200 bg-white overflow-x-auto">
                    <table className="w-full text-sm min-w-[900px]">
                        <thead>
                            <tr className="border-b border-zinc-100 bg-zinc-50">
                                {th('date', 'Date', 'left')}
                                {th('ad_spend', 'Ad Spend')}
                                {th('revenue', 'Revenue')}
                                {th('orders', 'Orders')}
                                {th('items_sold', 'Items')}
                                {th('items_per_order', 'IPO')}
                                {th('marketing_pct', 'Mktg %')}
                                {th('aov', 'AOV')}
                                {th('roas', 'ROAS')}
                                <th className="px-3 py-2.5 text-left text-xs font-medium text-zinc-400 min-w-[180px]">
                                    Note
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {/* Totals / summary row */}
                            <tr className="border-b-2 border-zinc-200 bg-zinc-50 font-semibold text-zinc-900">
                                <td className="px-3 py-2.5 text-xs text-zinc-500 uppercase tracking-wide">
                                    Total
                                </td>
                                <td className="px-3 py-2.5 text-right tabular-nums">
                                    {totals.ad_spend != null ? formatCurrency(totals.ad_spend, currency) : '—'}
                                </td>
                                <td className="px-3 py-2.5 text-right tabular-nums">
                                    {formatCurrency(totals.revenue, currency)}
                                </td>
                                <td className="px-3 py-2.5 text-right tabular-nums">
                                    {formatNumber(totals.orders)}
                                </td>
                                <td className="px-3 py-2.5 text-right tabular-nums">
                                    {formatNumber(totals.items_sold)}
                                </td>
                                <td className="px-3 py-2.5 text-right tabular-nums">
                                    {fmtIpo(totals.items_per_order)}
                                </td>
                                <td className="px-3 py-2.5 text-right tabular-nums">
                                    {fmtPct(totals.marketing_pct)}
                                </td>
                                <td className="px-3 py-2.5 text-right tabular-nums">
                                    {totals.aov != null ? formatCurrency(totals.aov, currency) : '—'}
                                </td>
                                <td className="px-3 py-2.5 text-right tabular-nums">
                                    {fmtRoas(totals.roas)}
                                </td>
                                <td className="px-3 py-2.5" />
                            </tr>

                            {/* Data rows */}
                            {rows.map((row) => (
                                <tr
                                    key={row.date}
                                    className="border-b border-zinc-100 hover:bg-zinc-50/70 transition-colors"
                                >
                                    <td className="px-3 py-2.5 text-zinc-700 font-medium whitespace-nowrap">
                                        {fmtDate(row.date)}
                                    </td>
                                    <td className={cn(
                                        'px-3 py-2.5 text-right tabular-nums',
                                        row.ad_spend != null ? 'text-zinc-700' : 'text-zinc-300',
                                    )}>
                                        {row.ad_spend != null ? formatCurrency(row.ad_spend, currency) : '—'}
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums font-medium text-zinc-900">
                                        {formatCurrency(row.revenue, currency)}
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums text-zinc-700">
                                        {formatNumber(row.orders)}
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums text-zinc-700">
                                        {formatNumber(row.items_sold)}
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums text-zinc-600">
                                        {fmtIpo(row.items_per_order)}
                                    </td>
                                    <td className={cn(
                                        'px-3 py-2.5 text-right tabular-nums',
                                        row.marketing_pct !== null && row.marketing_pct > 25
                                            ? 'text-amber-600 font-medium'
                                            : 'text-zinc-600',
                                    )}>
                                        {fmtPct(row.marketing_pct)}
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums text-zinc-600">
                                        {row.aov != null ? formatCurrency(row.aov, currency) : '—'}
                                    </td>
                                    <td className={cn(
                                        'px-3 py-2.5 text-right tabular-nums font-medium',
                                        row.roas !== null && row.roas >= 4
                                            ? 'text-green-700'
                                            : row.roas !== null && row.roas < 2
                                                ? 'text-red-600'
                                                : 'text-zinc-700',
                                    )}>
                                        {fmtRoas(row.roas)}
                                    </td>
                                    <td className="px-3 py-2">
                                        <NoteCell date={row.date} initialNote={row.note} />
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
