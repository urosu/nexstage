import axios from 'axios';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level lets us start with
// navigating=true so the skeleton stays visible until the real data is ready.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import { Calendar, TrendingUp, TrendingDown } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { AnalyticsTabBar } from '@/Components/shared/AnalyticsTabBar';
import { StoreFilter } from '@/Components/shared/StoreFilter';
import { MetricCard } from '@/Components/shared/MetricCard';
import { BreakdownView, type BreakdownRow, type BreakdownColumn } from '@/Components/shared/BreakdownView';
import { InfoTooltip } from '@/Components/shared/Tooltip';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
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
    wl_tag: 'winner' | 'loser' | null;
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

interface Comparison {
    revenue_current: number;
    revenue_delta: number | null;
    orders_current: number;
    orders_delta: number | null;
    aov_current: number | null;
    aov_delta: number | null;
    roas_current: number | null;
    roas_delta: number | null;
}

interface Hero {
    comparison: Comparison;
    streak: { type: 'winner' | 'loser'; days: number } | null;
}

interface Props {
    rows: DailyRow[];
    rows_total_count: number;
    totals: Totals;
    hero: Hero;
    has_ads: boolean;
    from: string;
    to: string;
    store_ids: number[];
    sort_by: string;
    sort_dir: 'asc' | 'desc';
    hide_empty: boolean;
    narrative: string | null;
}

// ── Formatters ─────────────────────────────────────────────────────────────

function fmtDate(iso: string): string {
    const d = new Date(iso + 'T00:00:00');
    const weekday = d.toLocaleDateString('en-GB', { weekday: 'short' });
    const day     = d.getDate();
    const month   = d.getMonth() + 1;
    const year    = String(d.getFullYear()).slice(-2);
    return `${weekday} ${day}.${month}.${year}`;
}

function fmtRoas(v: number | null): string {
    return v !== null ? `${v.toFixed(2)}×` : '—';
}

function fmtPct(v: number | null): string {
    return v !== null ? `${v.toFixed(1)}%` : '—';
}

// ── Column definitions ──────────────────────────────────────────────────────

function buildColumns(currency: string): BreakdownColumn[] {
    return [
        { key: 'ad_spend',        label: 'Ad Spend',  format: 'currency', currency },
        { key: 'revenue',         label: 'Revenue',   format: 'currency', currency },
        { key: 'orders',          label: 'Orders',    format: 'number' },
        { key: 'items_sold',      label: 'Items',     format: 'number' },
        { key: 'items_per_order', label: 'IPO',       format: 'raw' },
        { key: 'marketing_pct',   label: 'Mktg %',    format: 'raw' },
        { key: 'aov',             label: 'AOV',       format: 'currency', currency },
        { key: 'roas',            label: 'ROAS',      format: 'raw' },
    ];
}

// ── Inline note cell ────────────────────────────────────────────────────────

function NoteCell({ date, initialNote }: { date: string; initialNote: string | null }) {
    const [value, setValue]   = useState(initialNote ?? '');
    const [saving, setSaving] = useState(false);
    const focusedRef           = useRef(false);
    const lastSavedRef         = useRef(initialNote ?? '');

    useEffect(() => {
        if (!focusedRef.current) {
            const v = initialNote ?? '';
            setValue(v);
            lastSavedRef.current = v;
        }
    }, [initialNote]);

    function handleBlur(e: React.FocusEvent<HTMLInputElement>): void {
        focusedRef.current = false;
        const current = e.currentTarget.value;
        if (current === lastSavedRef.current) return;
        setSaving(true);
        axios
            .post(`/analytics/notes/${date}`, { note: current })
            .then(() => {
                lastSavedRef.current = current;
                setValue(current);
            })
            .catch(() => { setValue(lastSavedRef.current); })
            .finally(() => setSaving(false));
    }

    function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>): void {
        if (e.key === 'Enter')  { e.currentTarget.blur(); }
        if (e.key === 'Escape') { setValue(lastSavedRef.current); e.currentTarget.blur(); }
    }

    return (
        <td className="px-4 py-2.5" onClick={e => e.stopPropagation()}>
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
                        'focus:border-primary/40 focus:bg-white focus:shadow-sm',
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
        </td>
    );
}

// ── Totals summary panel ────────────────────────────────────────────────────

function TotalsSummary({ totals, currency }: { totals: Totals; currency: string }) {
    const items = [
        { label: 'Ad Spend',  value: totals.ad_spend != null ? formatCurrency(totals.ad_spend, currency) : '—' },
        { label: 'Revenue',   value: formatCurrency(totals.revenue, currency) },
        { label: 'Orders',    value: formatNumber(totals.orders) },
        { label: 'Items',     value: formatNumber(totals.items_sold) },
        { label: 'IPO',       value: totals.items_per_order != null ? totals.items_per_order.toFixed(2) : '—' },
        { label: 'Mktg %',    value: fmtPct(totals.marketing_pct) },
        { label: 'AOV',       value: totals.aov != null ? formatCurrency(totals.aov, currency) : '—' },
        { label: 'ROAS',      value: fmtRoas(totals.roas) },
    ];

    return (
        <div className="flex divide-x divide-zinc-200 overflow-x-auto rounded-xl border border-zinc-200 bg-zinc-50">
            {items.map(item => (
                <div key={item.label} className="flex min-w-[80px] flex-1 flex-col items-center gap-0.5 px-4 py-3">
                    <span className="whitespace-nowrap text-xs text-zinc-400">{item.label}</span>
                    <span className="text-sm font-bold tabular-nums text-zinc-800">{item.value}</span>
                </div>
            ))}
        </div>
    );
}

// ── Main component ──────────────────────────────────────────────────────────

export default function AnalyticsDaily({
    rows, totals, hero, has_ads, from, to, store_ids, hide_empty, narrative,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const [navigating, setNavigating]         = useState(() => _inertiaNavigating);
    const [showHighlight, setShowHighlight]   = useState(false);

    useEffect(() => {
        const off1 = router.on('start',  () => setNavigating(true));
        const off2 = router.on('finish', () => setNavigating(false));
        return () => { off1(); off2(); };
    }, []);

    function buildParams(overrides: Record<string, string | undefined>): Record<string, string | undefined> {
        const base: Record<string, string | undefined> = { from, to };
        if (store_ids.length > 0) base.store_ids = store_ids.join(',');
        if (hide_empty) base.hide_empty = '1';
        return { ...base, ...overrides };
    }

    function toggleHideEmpty(): void {
        const next = hide_empty ? '0' : '1';
        router.get(wurl(workspace?.slug, '/analytics/daily'), buildParams({ hide_empty: next }), {
            preserveState: true, replace: true,
        });
    }

    // Map to BreakdownRow[]. Adds date_ts (epoch ms) as a hidden sort key so BreakdownView
    // can sort rows by date natively (ISO date strings are lexicographically sortable but
    // BreakdownView's sort uses numeric comparison; a timestamp avoids type issues).
    const breakdownRows: BreakdownRow[] = rows.map(r => ({
        id:      r.date,
        label:   fmtDate(r.date),
        wl_tag:  r.wl_tag,
        metrics: {
            // Hidden sort key — not in columns array, used only by defaultSortBy
            date_ts:         new Date(r.date + 'T00:00:00').getTime(),
            ad_spend:        r.ad_spend,
            revenue:         r.revenue,
            orders:          r.orders,
            items_sold:      r.items_sold,
            items_per_order: r.items_per_order,
            // 'raw' format renders the number directly; pre-format it for display
            marketing_pct:   r.marketing_pct,
            aov:             r.aov,
            roas:            r.roas,
        },
    }));

    const columns  = useMemo(() => buildColumns(currency), [currency]);
    const noteMap  = useMemo(() => new Map(rows.map(r => [r.date, r.note])),  [rows]);
    const wlTagMap = useMemo(() => new Map(rows.map(r => [r.date, r.wl_tag])), [rows]);

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title="Analytics — Daily Report" />
            <PageHeader title="Analytics" subtitle="Daily breakdown" narrative={narrative} />
            <AnalyticsTabBar />
            <StoreFilter selectedStoreIds={store_ids} />

            <div className="space-y-6">
                {/* Hero: period comparison + streak */}
                <div className="space-y-3">
                    <p className="text-[11px] font-semibold uppercase tracking-widest text-zinc-400">
                        vs prior {Math.round((new Date(to).getTime() - new Date(from).getTime()) / 86400000) + 1}-day period
                    </p>
                    <div className={cn(
                        'grid gap-5',
                        has_ads ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-3',
                    )}>
                        <MetricCard
                            label="Revenue"
                            value={formatCurrency(hero.comparison.revenue_current, currency)}
                            source="store"
                            change={hero.comparison.revenue_delta}
                        />
                        <MetricCard
                            label="Orders"
                            value={formatNumber(hero.comparison.orders_current)}
                            source="store"
                            change={hero.comparison.orders_delta}
                        />
                        <MetricCard
                            label="Avg Order Value"
                            value={hero.comparison.aov_current != null ? formatCurrency(hero.comparison.aov_current, currency) : null}
                            source="store"
                            change={hero.comparison.aov_delta}
                        />
                        {has_ads && (
                            <MetricCard
                                label="Real ROAS"
                                value={hero.comparison.roas_current != null ? `${hero.comparison.roas_current.toFixed(2)}×` : null}
                                source="real"
                                change={hero.comparison.roas_delta}
                                tooltip="Store revenue divided by total ad spend for this period."
                            />
                        )}
                    </div>
                    {hero.streak && (
                        <div className={cn(
                            'flex items-center gap-3 rounded-xl border px-5 py-3 text-sm shadow-sm',
                            hero.streak.type === 'winner'
                                ? 'border-green-200 bg-green-50'
                                : 'border-red-200 bg-red-50',
                        )}>
                            {hero.streak.type === 'winner'
                                ? <TrendingUp className="h-4 w-4 shrink-0 text-green-600" />
                                : <TrendingDown className="h-4 w-4 shrink-0 text-red-500" />}
                            <span className={cn(
                                'font-semibold',
                                hero.streak.type === 'winner' ? 'text-green-700' : 'text-red-600',
                            )}>
                                {hero.streak.days}-day {hero.streak.type === 'winner' ? 'winning' : 'losing'} streak
                            </span>
                            <span className="text-xs text-zinc-400">
                                {hero.streak.days === 1
                                    ? `${hero.streak.type === 'winner' ? 'Above' : 'Below'} weekday average today`
                                    : `${hero.streak.days} consecutive days ${hero.streak.type === 'winner' ? 'above' : 'below'} weekday average`}
                            </span>
                        </div>
                    )}
                </div>

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
                    <div className="space-y-4">
                        <div>
                            <p className="mb-3 text-[11px] font-semibold uppercase tracking-widest text-zinc-400">Period totals</p>
                            <TotalsSummary totals={totals} currency={currency} />
                        </div>
                        <BreakdownView
                            breakdownBy="date"
                            cardData="store"
                            columns={columns}
                            data={breakdownRows}
                            defaultView="table"
                            viewKey="analytics_daily"
                            currency={currency}
                            defaultSortBy="date_ts"
                            defaultSortDir="desc"
                            suffixColumnLabel="Note"
                            renderRowSuffix={(row) => (
                                <NoteCell
                                    date={row.id as string}
                                    initialNote={noteMap.get(row.id as string) ?? null}
                                />
                            )}
                            emptyMessage="No data for this period."
                            getRowClassName={showHighlight ? (row) => {
                                const tag = wlTagMap.get(row.id as string);
                                if (tag === 'winner') return 'bg-emerald-50';
                                if (tag === 'loser')  return 'bg-red-50';
                            } : undefined}
                            leftSlot={
                                <div className="flex items-center gap-1.5">
                                    <button
                                        onClick={() => setShowHighlight(v => !v)}
                                        className={cn(
                                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                            showHighlight
                                                ? 'border-primary/30 bg-primary/10 text-primary'
                                                : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 hover:text-zinc-700',
                                        )}
                                    >
                                        Highlight W/L
                                    </button>
                                    <InfoTooltip content="Green = revenue above the 4-week same-weekday average. Red = below. In graph view, winners and losers appear as colored dots on the line." />
                                    <span className="h-4 w-px bg-zinc-200" />
                                    <button
                                        onClick={toggleHideEmpty}
                                        className={cn(
                                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                            hide_empty
                                                ? 'border-primary/30 bg-primary/10 text-primary'
                                                : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 hover:text-zinc-700',
                                        )}
                                    >
                                        Hide no activity
                                    </button>
                                    <InfoTooltip content="Hides days with zero orders and zero ad spend from the table, cards, and chart." />
                                </div>
                            }
                        />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
