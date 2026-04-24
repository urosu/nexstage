import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Package, Users } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StoreFilter } from '@/Components/shared/StoreFilter';
import { MetricCard } from '@/Components/shared/MetricCard';
import { DestinationTabs, type DestinationTab } from '@/Components/shared/DestinationTabs';
import { RFMGrid, type RFMCell } from '@/Components/shared/RFMGrid';
import { CohortTable, type CohortRow } from '@/Components/shared/CohortTable';
import { BreakdownView, type BreakdownRow, type BreakdownColumn } from '@/Components/shared/BreakdownView';
import { SortButton } from '@/Components/shared/SortButton';
import { InfoTooltip } from '@/Components/shared/Tooltip';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

// ─── Shared helpers ───────────────────────────────────────────────────────────

const COUNTRY_NAMES: Record<string, string> = {
    AT: 'Austria', AU: 'Australia', BE: 'Belgium', BG: 'Bulgaria', BR: 'Brazil',
    CA: 'Canada', CH: 'Switzerland', CN: 'China', CY: 'Cyprus', CZ: 'Czech Republic',
    DE: 'Germany', DK: 'Denmark', EE: 'Estonia', ES: 'Spain', FI: 'Finland',
    FR: 'France', GB: 'United Kingdom', GR: 'Greece', HR: 'Croatia', HU: 'Hungary',
    IE: 'Ireland', IT: 'Italy', JP: 'Japan', KR: 'South Korea', LT: 'Lithuania',
    LU: 'Luxembourg', LV: 'Latvia', MT: 'Malta', MX: 'Mexico', NL: 'Netherlands',
    NO: 'Norway', NZ: 'New Zealand', PL: 'Poland', PT: 'Portugal', RO: 'Romania',
    RU: 'Russia', SE: 'Sweden', SI: 'Slovenia', SK: 'Slovakia', TR: 'Turkey',
    UA: 'Ukraine', US: 'United States', AD: 'Andorra', AE: 'UAE',
};

function countryFlag(code: string): string {
    const A = 0x1F1E6;
    return code.toUpperCase().split('').map(c => String.fromCodePoint(c.charCodeAt(0) - 65 + A)).join('');
}

function countryName(code: string): string {
    return COUNTRY_NAMES[code] ?? code;
}

// ─── Types ────────────────────────────────────────────────────────────────────

interface ProductRow {
    external_id: string;
    name: string;
    image_url: string | null;
    sku: string | null;
    units: number;
    revenue: number | null;
    contribution_margin: number | null;
    margin_pct: number | null;
    ad_spend: number | null;
    discount_pct: number | null;
    refund_rate: number | null;
    cvr: number | null;
    stock_status: string | null;
    stock_quantity: number | null;
    days_of_cover: number | null;
    is_slow_mover: boolean;
    trend_dots: (boolean | null)[];
    wl_tag: 'winner' | 'loser' | null;
}

interface CountryRow {
    country_code: string;
    orders: number;
    revenue: number;
    share: number;
    gsc_clicks: number | null;
    fb_spend: number | null;
    google_spend: number | null;
    real_roas: number | null;
    contribution_margin: number | null;
    real_profit: number | null;
    wl_tag: 'winner' | 'loser' | null;
}

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

interface Props extends PageProps {
    tab: string;
    from: string;
    to: string;
    store_ids: number[];
    narrative: string | null;

    // Products tab
    products?: ProductRow[];
    products_total_count?: number;
    has_cogs?: boolean;
    winner_rows?: ProductRow[];
    loser_rows?: ProductRow[];
    hero?: Record<string, unknown>;
    sort_by?: string;
    sort_dir?: string;
    view?: string;
    filter?: string;
    classifier?: string | null;
    active_classifier?: string;

    // Customers tab
    rfm_cells?: RFMCell[];
    new_vs_returning?: { day: string; new_customers: number; returning_customers: number }[];

    // Cohorts tab
    cohort_rows?: CohortRow[];
    weighted_avg?: (number | null)[];
    available_channels?: string[];
    active_channel?: string | null;

    // Countries tab
    countries?: CountryRow[];
    countries_total_count?: number;
    has_ads?: boolean;
    top_products?: { product_external_id: string; product_name: string; units: number; revenue: number | null; image_url: string | null }[];
    selected_country?: string | null;

    // Orders tab
    rows?: DailyRow[];
    rows_total_count?: number;
    totals?: Record<string, unknown>;
    hide_empty?: boolean;
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function TrendDots({ dots }: { dots: (boolean | null)[] }) {
    return (
        <div className="flex items-center gap-0.5">
            {dots.slice(0, 14).map((dot, i) => (
                <span
                    key={i}
                    className={cn(
                        'h-1.5 w-1.5 rounded-full flex-shrink-0',
                        dot === true  ? 'bg-green-500'  :
                        dot === false ? 'bg-zinc-200'   : 'bg-zinc-100',
                    )}
                />
            ))}
        </div>
    );
}

function StockDot({ status }: { status: string | null }) {
    if (!status) return null;
    const norm = status.replace(/[_-]/g, '').toLowerCase();
    const color = norm === 'instock' ? 'bg-green-500' : norm === 'outofstock' ? 'bg-red-500' : 'bg-amber-500';
    const label = norm === 'instock' ? 'In stock' : norm === 'outofstock' ? 'Out of stock' : 'On backorder';
    return (
        <span className="inline-flex items-center gap-1.5" title={label}>
            <span className={cn('h-2 w-2 rounded-full', color)} />
            <span className="text-xs text-zinc-500">{label}</span>
        </span>
    );
}

const PRODUCT_FILTERS = [
    { key: 'all',           label: 'All' },
    { key: 'winners',       label: 'Winners' },
    { key: 'losers',        label: 'Losers' },
    { key: 'in_stock',      label: 'In stock' },
    { key: 'unprofitable',  label: 'Unprofitable' },
    { key: 'slow_movers',   label: 'Slow movers' },
    { key: 'stockout_risk', label: 'Stockout risk' },
    { key: 'returns_over_10', label: 'Returns >10%' },
];

// ─── Tab content: Products ────────────────────────────────────────────────────

function ProductsTab({
    products = [], products_total_count = 0, has_cogs = false,
    winner_rows = [], loser_rows = [], hero = {}, sort_by = 'revenue',
    sort_dir = 'desc', view = 'table', filter = 'all', from, to,
    store_ids, currency, navigate,
}: {
    products: ProductRow[]; products_total_count: number; has_cogs: boolean;
    winner_rows: ProductRow[]; loser_rows: ProductRow[]; hero: Record<string, unknown>;
    sort_by: string; sort_dir: string; view: string; filter: string;
    from: string; to: string; store_ids: number[]; currency: string;
    navigate: (params: Record<string, unknown>) => void;
}) {
    function handleSort(field: string) {
        const newDir = sort_by === field && sort_dir === 'desc' ? 'asc' : 'desc';
        navigate({ sort_by: field, sort_dir: newDir, filter });
    }

    function handleFilter(f: string) {
        navigate({ sort_by, sort_dir, filter: f });
    }

    const h = hero as {
        total_units?: number; total_revenue?: number;
        total_margin?: number | null; avg_margin_pct?: number | null;
        stockout_risk_count?: number;
    };

    return (
        <div>
            {/* Hero metrics */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <MetricCard
                    label="Total Revenue"
                    value={h.total_revenue != null ? formatCurrency(h.total_revenue, currency) : '—'}
                    source="store"
                />
                <MetricCard
                    label="Units Sold"
                    value={h.total_units != null ? formatNumber(h.total_units) : '—'}
                    source="store"
                />
                {has_cogs ? (
                    <MetricCard
                        label="Contribution Margin"
                        value={h.total_margin != null ? formatCurrency(h.total_margin, currency) : '—'}
                        source="real"
                    />
                ) : null}
                {has_cogs ? (
                    <MetricCard
                        label="Avg CM %"
                        value={h.avg_margin_pct != null ? `${h.avg_margin_pct}%` : '—'}
                        source="real"
                    />
                ) : null}
                <MetricCard
                    label="Stockout Risk"
                    value={String(h.stockout_risk_count ?? 0)}
                    source="store"
                    actionLine={h.stockout_risk_count ? `${h.stockout_risk_count} product${h.stockout_risk_count !== 1 ? 's' : ''} ≤7 days cover` : 'All products healthy'}
                />
            </div>

            {/* Winners / Losers dual-table */}
            {has_cogs && (winner_rows.length > 0 || loser_rows.length > 0) && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    {[{ label: 'Top Winners', rows: winner_rows, tag: 'winner' as const },
                      { label: 'Top Losers',  rows: loser_rows,  tag: 'loser' as const }].map(({ label, rows: dRows, tag }) => (
                        <div key={tag} className="bg-white border border-zinc-200 rounded-lg p-4">
                            <h3 className={cn(
                                'text-sm font-semibold mb-3',
                                tag === 'winner' ? 'text-green-700' : 'text-red-700',
                            )}>{label}</h3>
                            {dRows.length === 0 ? (
                                <p className="text-xs text-zinc-400">No data</p>
                            ) : (
                                <div className="space-y-2">
                                    {dRows.map((p) => (
                                        <div key={p.external_id} className="flex items-center gap-2">
                                            {p.image_url ? (
                                                <img src={p.image_url} alt="" className="h-7 w-7 rounded object-cover flex-shrink-0" />
                                            ) : (
                                                <div className="h-7 w-7 rounded bg-zinc-100 flex-shrink-0" />
                                            )}
                                            <span className="text-xs text-zinc-700 flex-1 truncate">{p.name}</span>
                                            <span className={cn(
                                                'text-xs font-medium flex-shrink-0',
                                                tag === 'winner' ? 'text-green-700' : 'text-red-700',
                                            )}>
                                                {p.contribution_margin != null ? formatCurrency(p.contribution_margin, currency, true) : '—'}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {/* Filter chips */}
            <div className="flex flex-wrap gap-2 mb-4">
                {PRODUCT_FILTERS.map((f) => (
                    <button
                        key={f.key}
                        type="button"
                        onClick={() => handleFilter(f.key)}
                        className={cn(
                            'px-3 py-1 text-xs rounded-full border transition-colors',
                            filter === f.key
                                ? 'bg-primary text-white border-primary'
                                : 'bg-white text-zinc-600 border-zinc-200 hover:border-zinc-400',
                        )}
                    >
                        {f.label}
                    </button>
                ))}
            </div>

            {/* Products table */}
            <div className="overflow-x-auto rounded-lg border border-zinc-200 bg-white">
                <table className="min-w-full divide-y divide-zinc-100 text-sm">
                    <thead className="bg-zinc-50">
                        <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wide">
                                Product
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wide whitespace-nowrap">
                                <SortButton col="units" label="Units" currentSort={sort_by} currentDir={sort_dir as 'asc'|'desc'} onSort={handleSort} />
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wide whitespace-nowrap">
                                <SortButton col="revenue" label="Revenue" currentSort={sort_by} currentDir={sort_dir as 'asc'|'desc'} onSort={handleSort} />
                            </th>
                            {has_cogs && <>
                                <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wide whitespace-nowrap">
                                    <SortButton col="contribution_margin" label="CM" currentSort={sort_by} currentDir={sort_dir as 'asc'|'desc'} onSort={handleSort} />
                                </th>
                                <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wide whitespace-nowrap">
                                    <SortButton col="margin_pct" label="CM %" currentSort={sort_by} currentDir={sort_dir as 'asc'|'desc'} onSort={handleSort} />
                                </th>
                            </>}
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wide whitespace-nowrap">
                                Ad Spend
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wide whitespace-nowrap">
                                Discount
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wide whitespace-nowrap">
                                Refund rate
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wide whitespace-nowrap">
                                CVR <InfoTooltip content="Views proxy via GSC clicks. Actual CVR requires site tracking." />
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wide whitespace-nowrap">
                                Stock
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wide whitespace-nowrap">
                                <SortButton col="days_of_cover" label="Days cover" currentSort={sort_by} currentDir={sort_dir as 'asc'|'desc'} onSort={handleSort} />
                            </th>
                            <th className="px-3 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wide whitespace-nowrap">
                                14d trend
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-zinc-100 bg-white">
                        {products.length === 0 ? (
                            <tr>
                                <td colSpan={has_cogs ? 12 : 10} className="px-4 py-10 text-center text-zinc-400 text-sm">
                                    No products match this filter.
                                </td>
                            </tr>
                        ) : products.map((p) => (
                            <tr key={p.external_id} className={cn(
                                'hover:bg-zinc-50',
                                p.wl_tag === 'winner' && 'border-l-2 border-green-400',
                                p.wl_tag === 'loser'  && 'border-l-2 border-red-400',
                            )}>
                                {/* Product cell */}
                                <td className="px-4 py-2.5 max-w-[220px]">
                                    <div className="flex items-center gap-2">
                                        {p.image_url ? (
                                            <img src={p.image_url} alt="" className="h-8 w-8 rounded object-cover flex-shrink-0" />
                                        ) : (
                                            <div className="h-8 w-8 rounded bg-zinc-100 flex-shrink-0 flex items-center justify-center">
                                                <Package className="h-4 w-4 text-zinc-300" />
                                            </div>
                                        )}
                                        <div className="min-w-0">
                                            <div className="text-sm font-medium text-zinc-800 truncate">{p.name}</div>
                                            {p.sku && <div className="text-xs text-zinc-400 truncate">{p.sku}</div>}
                                        </div>
                                    </div>
                                </td>
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-700">{formatNumber(p.units)}</td>
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-700">
                                    {p.revenue != null ? formatCurrency(p.revenue, currency, true) : '—'}
                                </td>
                                {has_cogs && <>
                                    <td className={cn(
                                        'px-3 py-2.5 text-right text-sm font-medium',
                                        p.contribution_margin == null ? 'text-zinc-400'
                                        : p.contribution_margin < 0   ? 'text-red-600'
                                        : 'text-green-700',
                                    )}>
                                        {p.contribution_margin != null ? formatCurrency(p.contribution_margin, currency, true) : '—'}
                                    </td>
                                    <td className={cn(
                                        'px-3 py-2.5 text-right text-sm',
                                        p.margin_pct == null ? 'text-zinc-400'
                                        : p.margin_pct < 0   ? 'text-red-600'
                                        : 'text-green-700',
                                    )}>
                                        {p.margin_pct != null ? `${p.margin_pct}%` : '—'}
                                    </td>
                                </>}
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-600">
                                    {p.ad_spend != null ? formatCurrency(p.ad_spend, currency, true) : '—'}
                                </td>
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-600">
                                    {p.discount_pct != null ? `${p.discount_pct}%` : '—'}
                                </td>
                                <td className={cn(
                                    'px-3 py-2.5 text-right text-sm',
                                    p.refund_rate != null && p.refund_rate > 10 ? 'text-red-600 font-medium' : 'text-zinc-600',
                                )}>
                                    {p.refund_rate != null ? `${p.refund_rate}%` : '—'}
                                </td>
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-500">
                                    {p.cvr != null ? (
                                        <span title="Est. via GSC clicks proxy">
                                            {(p.cvr * 100).toFixed(1)}% <span className="text-zinc-400 text-xs">est.</span>
                                        </span>
                                    ) : '—'}
                                </td>
                                <td className="px-3 py-2.5">
                                    <StockDot status={p.stock_status} />
                                </td>
                                <td className={cn(
                                    'px-3 py-2.5 text-right text-sm',
                                    p.days_of_cover != null && p.days_of_cover <= 7 ? 'text-red-600 font-medium' : 'text-zinc-600',
                                )}>
                                    {p.days_of_cover != null ? `${p.days_of_cover}d` : p.stock_quantity === null ? '—' : '∞'}
                                </td>
                                <td className="px-3 py-2.5">
                                    <TrendDots dots={p.trend_dots} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {products_total_count > products.length && (
                <p className="text-xs text-zinc-400 mt-2 text-right">
                    Showing {products.length} of {products_total_count} products
                </p>
            )}
        </div>
    );
}

// ─── Tab content: Customers ───────────────────────────────────────────────────

function CustomersTab({
    hero = {}, rfm_cells = [], new_vs_returning = [], currency,
}: {
    hero: Record<string, unknown>;
    rfm_cells: RFMCell[];
    new_vs_returning: { day: string; new_customers: number; returning_customers: number }[];
    currency: string;
}) {
    const h = hero as { orders_per_customer?: number | null; ltv?: number | null; returning_pct?: number | null };

    return (
        <div className="space-y-8">
            {/* 3 north-star cards */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <MetricCard
                    label="Orders per Customer"
                    value={h.orders_per_customer != null ? h.orders_per_customer.toFixed(2) : '—'}
                    source="store"
                />
                <MetricCard
                    label="Customer LTV (all-time)"
                    value={h.ltv != null ? formatCurrency(h.ltv, currency) : '—'}
                    source="store"
                />
                <MetricCard
                    label="Returning Order %"
                    value={h.returning_pct != null ? `${h.returning_pct}%` : '—'}
                    source="store"
                />
            </div>

            {/* RFM grid */}
            <div className="bg-white border border-zinc-200 rounded-lg p-5">
                <h3 className="text-sm font-semibold text-zinc-800 mb-1">Customer Segments (RFM)</h3>
                <p className="text-xs text-zinc-500 mb-4">
                    Recency × Frequency+Monetary. Click a cell to view that segment's customers (coming soon).
                </p>
                <RFMGrid cells={rfm_cells} />
            </div>

            {/* New vs Returning chart (simple bar representation) */}
            {new_vs_returning.length > 0 && (
                <div className="bg-white border border-zinc-200 rounded-lg p-5">
                    <h3 className="text-sm font-semibold text-zinc-800 mb-4">New vs Returning — Daily</h3>
                    <div className="overflow-x-auto">
                        <div className="flex items-end gap-1 min-w-[400px]" style={{ height: 80 }}>
                            {new_vs_returning.map((d) => {
                                const total = d.new_customers + d.returning_customers;
                                const maxTotal = Math.max(...new_vs_returning.map(r => r.new_customers + r.returning_customers), 1);
                                const barH = total > 0 ? Math.round((total / maxTotal) * 72) : 2;
                                const newPct = total > 0 ? (d.new_customers / total) * 100 : 0;
                                return (
                                    <div
                                        key={d.day}
                                        className="flex-1 flex flex-col justify-end"
                                        title={`${d.day}: ${d.new_customers} new, ${d.returning_customers} returning`}
                                        style={{ height: barH }}
                                    >
                                        <div className="w-full bg-blue-500 rounded-sm" style={{ height: `${100 - newPct}%` }} />
                                        <div className="w-full bg-green-400 rounded-sm" style={{ height: `${newPct}%` }} />
                                    </div>
                                );
                            })}
                        </div>
                        <div className="flex items-center gap-4 mt-2">
                            <span className="flex items-center gap-1.5 text-xs text-zinc-500">
                                <span className="h-2.5 w-2.5 rounded-sm bg-green-400 inline-block" /> New
                            </span>
                            <span className="flex items-center gap-1.5 text-xs text-zinc-500">
                                <span className="h-2.5 w-2.5 rounded-sm bg-blue-500 inline-block" /> Returning
                            </span>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Tab content: Cohorts ─────────────────────────────────────────────────────

function CohortsTab({
    cohort_rows = [], weighted_avg = [], available_channels = [],
    active_channel = null, from, to, store_ids, currency, navigate,
}: {
    cohort_rows: CohortRow[]; weighted_avg: (number | null)[];
    available_channels: string[]; active_channel: string | null;
    from: string; to: string; store_ids: number[]; currency: string;
    navigate: (params: Record<string, unknown>) => void;
}) {
    const [mode, setMode] = useState<'cumulative' | 'non_cumulative'>('cumulative');
    const [format, setFormat] = useState<'absolute' | 'percent'>('absolute');

    const hasSixMonths = cohort_rows.length >= 6;

    if (!hasSixMonths) {
        return (
            <div className="bg-zinc-50 border border-zinc-200 rounded-lg p-8 text-center">
                <Users className="h-8 w-8 text-zinc-300 mx-auto mb-2" />
                <p className="text-sm font-medium text-zinc-600">Cohort analysis available after 6 months of orders</p>
                <p className="text-xs text-zinc-400 mt-1">
                    {cohort_rows.length} month{cohort_rows.length !== 1 ? 's' : ''} of data so far.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {/* Controls */}
            <div className="flex flex-wrap gap-3 items-center">
                <div className="flex rounded-md border border-zinc-200 overflow-hidden text-xs">
                    {(['cumulative', 'non_cumulative'] as const).map((m) => (
                        <button
                            key={m}
                            type="button"
                            onClick={() => setMode(m)}
                            className={cn(
                                'px-3 py-1.5 transition-colors',
                                mode === m ? 'bg-primary text-white' : 'bg-white text-zinc-600 hover:bg-zinc-50',
                            )}
                        >
                            {m === 'cumulative' ? 'Cumulative' : 'Non-cumulative'}
                        </button>
                    ))}
                </div>
                <div className="flex rounded-md border border-zinc-200 overflow-hidden text-xs">
                    {(['absolute', 'percent'] as const).map((f) => (
                        <button
                            key={f}
                            type="button"
                            onClick={() => setFormat(f)}
                            className={cn(
                                'px-3 py-1.5 transition-colors',
                                format === f ? 'bg-primary text-white' : 'bg-white text-zinc-600 hover:bg-zinc-50',
                            )}
                        >
                            {f === 'absolute' ? 'Absolute' : '% of M0'}
                        </button>
                    ))}
                </div>
                {available_channels.length > 0 && (
                    <select
                        value={active_channel ?? ''}
                        onChange={(e) => navigate({ channel: e.target.value || undefined })}
                        className="text-xs border border-zinc-200 rounded px-2 py-1.5 bg-white text-zinc-700"
                    >
                        <option value="">All channels</option>
                        {available_channels.map((ch) => (
                            <option key={ch} value={ch}>{ch}</option>
                        ))}
                    </select>
                )}
            </div>

            <div className="bg-white border border-zinc-200 rounded-lg p-4">
                <CohortTable
                    rows={cohort_rows}
                    weightedAvg={weighted_avg}
                    mode={mode}
                    format={format}
                    currency={currency}
                />
            </div>
        </div>
    );
}

// ─── Tab content: Countries ───────────────────────────────────────────────────

function CountriesTab({
    countries = [], countries_total_count = 0, has_ads = false,
    hero = {}, top_products = [], selected_country = null,
    sort_by = 'revenue', sort_dir = 'desc', filter = 'all',
    from, to, store_ids, currency, navigate,
}: {
    countries: CountryRow[]; countries_total_count: number; has_ads: boolean;
    hero: Record<string, unknown>; top_products: Props['top_products'];
    selected_country: string | null; sort_by: string; sort_dir: string;
    filter: string; from: string; to: string; store_ids: number[];
    currency: string; navigate: (params: Record<string, unknown>) => void;
}) {
    const h = hero as {
        countries_with_orders?: number; top_country_share?: number;
        countries_above_avg_margin?: number; profitable_roas_countries?: number;
    };

    function handleSort(field: string) {
        const newDir = sort_by === field && sort_dir === 'desc' ? 'asc' : 'desc';
        navigate({ sort_by: field, sort_dir: newDir });
    }

    function handleCountry(code: string) {
        navigate({ country: selected_country === code ? undefined : code });
    }

    return (
        <div className="space-y-6">
            {/* Hero */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <MetricCard label="Countries with Orders" value={String(h.countries_with_orders ?? 0)} source="store" />
                <MetricCard label="Top Country Share" value={h.top_country_share != null ? `${h.top_country_share}%` : '—'} source="store" />
                <MetricCard label="Above Avg Margin" value={String(h.countries_above_avg_margin ?? 0)} source="real" />
                <MetricCard label="Profitable ROAS" value={String(h.profitable_roas_countries ?? 0)} source="real" />
            </div>

            {/* Filter chips */}
            <div className="flex gap-2">
                {['all', 'winners', 'losers'].map((f) => (
                    <button
                        key={f}
                        type="button"
                        onClick={() => navigate({ filter: f })}
                        className={cn(
                            'px-3 py-1 text-xs rounded-full border transition-colors',
                            filter === f
                                ? 'bg-primary text-white border-primary'
                                : 'bg-white text-zinc-600 border-zinc-200 hover:border-zinc-400',
                        )}
                    >
                        {f.charAt(0).toUpperCase() + f.slice(1)}
                    </button>
                ))}
            </div>

            {/* Countries table */}
            <div className="overflow-x-auto rounded-lg border border-zinc-200 bg-white">
                <table className="min-w-full divide-y divide-zinc-100 text-sm">
                    <thead className="bg-zinc-50">
                        <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-zinc-500 uppercase">Country</th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">
                                <SortButton col="orders" label="Orders" currentSort={sort_by} currentDir={sort_dir as 'asc'|'desc'} onSort={handleSort} />
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">
                                <SortButton col="revenue" label="Revenue" currentSort={sort_by} currentDir={sort_dir as 'asc'|'desc'} onSort={handleSort} />
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase">Share</th>
                            {has_ads && <>
                                <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">FB Spend</th>
                                <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">Google Spend</th>
                                <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">Real ROAS</th>
                                <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">Real Profit</th>
                            </>}
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">GSC Clicks</th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">CM</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-zinc-100 bg-white">
                        {countries.length === 0 ? (
                            <tr><td colSpan={has_ads ? 10 : 6} className="px-4 py-10 text-center text-zinc-400">No countries to display.</td></tr>
                        ) : countries.map((c) => (
                            <tr
                                key={c.country_code}
                                onClick={() => handleCountry(c.country_code)}
                                className={cn(
                                    'cursor-pointer hover:bg-zinc-50 transition-colors',
                                    selected_country === c.country_code && 'bg-blue-50',
                                    c.wl_tag === 'winner' && 'border-l-2 border-green-400',
                                    c.wl_tag === 'loser'  && 'border-l-2 border-red-400',
                                )}
                            >
                                <td className="px-4 py-2.5 whitespace-nowrap">
                                    <span className="mr-2 text-base">{countryFlag(c.country_code)}</span>
                                    <span className="text-sm text-zinc-800">{countryName(c.country_code)}</span>
                                </td>
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-700">{formatNumber(c.orders)}</td>
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-700">{formatCurrency(c.revenue, currency, true)}</td>
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-500">{c.share}%</td>
                                {has_ads && <>
                                    <td className="px-3 py-2.5 text-right text-sm text-zinc-600">{c.fb_spend != null ? formatCurrency(c.fb_spend, currency, true) : '—'}</td>
                                    <td className="px-3 py-2.5 text-right text-sm text-zinc-600">{c.google_spend != null ? formatCurrency(c.google_spend, currency, true) : '—'}</td>
                                    <td className={cn('px-3 py-2.5 text-right text-sm font-medium', c.real_roas == null ? 'text-zinc-400' : c.real_roas >= 1 ? 'text-green-700' : 'text-red-600')}>
                                        {c.real_roas != null ? `${c.real_roas}x` : '—'}
                                    </td>
                                    <td className={cn('px-3 py-2.5 text-right text-sm', c.real_profit == null ? 'text-zinc-400' : c.real_profit >= 0 ? 'text-green-700' : 'text-red-600')}>
                                        {c.real_profit != null ? formatCurrency(c.real_profit, currency, true) : '—'}
                                    </td>
                                </>}
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-500">{c.gsc_clicks != null ? formatNumber(c.gsc_clicks) : '—'}</td>
                                <td className={cn('px-3 py-2.5 text-right text-sm font-medium', c.contribution_margin == null ? 'text-zinc-400' : c.contribution_margin >= 0 ? 'text-green-700' : 'text-red-600')}>
                                    {c.contribution_margin != null ? formatCurrency(c.contribution_margin, currency, true) : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Drill-down: top products for selected country */}
            {selected_country && (top_products?.length ?? 0) > 0 && (
                <div className="bg-white border border-zinc-200 rounded-lg p-4">
                    <h3 className="text-sm font-semibold text-zinc-800 mb-3">
                        Top products in {countryFlag(selected_country)} {countryName(selected_country)}
                    </h3>
                    <div className="space-y-2">
                        {top_products?.map((p) => (
                            <div key={p.product_external_id} className="flex items-center gap-2">
                                {p.image_url ? (
                                    <img src={p.image_url} alt="" className="h-7 w-7 rounded object-cover flex-shrink-0" />
                                ) : (
                                    <div className="h-7 w-7 rounded bg-zinc-100 flex-shrink-0" />
                                )}
                                <span className="text-xs text-zinc-700 flex-1 truncate">{p.product_name}</span>
                                <span className="text-xs text-zinc-500 flex-shrink-0">{formatNumber(p.units)} units</span>
                                {p.revenue != null && (
                                    <span className="text-xs font-medium text-zinc-700 flex-shrink-0">{formatCurrency(p.revenue, currency, true)}</span>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Tab content: Orders ──────────────────────────────────────────────────────

function OrdersTab({
    rows = [], totals = {}, hero = {}, has_ads = false,
    sort_by = 'date', sort_dir = 'desc', hide_empty = false,
    from, to, store_ids, currency, navigate,
}: {
    rows: DailyRow[]; totals: Record<string, unknown>; hero: Record<string, unknown>;
    has_ads: boolean; sort_by: string; sort_dir: string; hide_empty: boolean;
    from: string; to: string; store_ids: number[]; currency: string;
    navigate: (params: Record<string, unknown>) => void;
}) {
    const h = hero as { comparison?: Record<string, unknown>; streak?: { type: string; days: number } | null };
    const comp = h.comparison as { revenue_current?: number; revenue_delta?: number | null; orders_current?: number; orders_delta?: number | null } | undefined;
    const t = totals as { revenue?: number; orders?: number; aov?: number | null; ad_spend?: number | null; roas?: number | null; marketing_pct?: number | null };

    function handleSort(field: string) {
        const newDir = sort_by === field && sort_dir === 'desc' ? 'asc' : 'desc';
        navigate({ sort_by: field, sort_dir: newDir });
    }

    return (
        <div className="space-y-6">
            {/* Hero */}
            {comp && (
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <MetricCard
                        label="Revenue"
                        value={comp.revenue_current != null ? formatCurrency(comp.revenue_current, currency, true) : '—'}
                        change={comp.revenue_delta ?? undefined}
                        source="store"
                    />
                    <MetricCard
                        label="Orders"
                        value={comp.orders_current != null ? formatNumber(comp.orders_current) : '—'}
                        change={comp.orders_delta ?? undefined}
                        source="store"
                    />
                    <MetricCard
                        label="AOV"
                        value={t.aov != null ? formatCurrency(t.aov, currency) : '—'}
                        source="store"
                    />
                    {has_ads && (
                        <MetricCard
                            label="ROAS"
                            value={t.roas != null ? `${t.roas}x` : '—'}
                            source="real"
                        />
                    )}
                </div>
            )}

            {/* Daily breakdown table */}
            <div className="overflow-x-auto rounded-lg border border-zinc-200 bg-white">
                <table className="min-w-full divide-y divide-zinc-100 text-sm">
                    <thead className="bg-zinc-50">
                        <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-zinc-500 uppercase">
                                <SortButton col="date" label="Date" currentSort={sort_by} currentDir={sort_dir as 'asc'|'desc'} onSort={handleSort} />
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">
                                <SortButton col="revenue" label="Revenue" currentSort={sort_by} currentDir={sort_dir as 'asc'|'desc'} onSort={handleSort} />
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">
                                <SortButton col="orders" label="Orders" currentSort={sort_by} currentDir={sort_dir as 'asc'|'desc'} onSort={handleSort} />
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">
                                <SortButton col="aov" label="AOV" currentSort={sort_by} currentDir={sort_dir as 'asc'|'desc'} onSort={handleSort} />
                            </th>
                            <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">Items</th>
                            {has_ads && <>
                                <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">Ad Spend</th>
                                <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">ROAS</th>
                                <th className="px-3 py-3 text-right text-xs font-medium text-zinc-500 uppercase whitespace-nowrap">Mkt %</th>
                            </>}
                            <th className="px-3 py-3 text-left text-xs font-medium text-zinc-500 uppercase">Note</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-zinc-100 bg-white">
                        {rows.length === 0 ? (
                            <tr><td colSpan={has_ads ? 9 : 6} className="px-4 py-10 text-center text-zinc-400">No daily data in this range.</td></tr>
                        ) : rows.map((r) => (
                            <tr key={r.date} className={cn(
                                'hover:bg-zinc-50',
                                r.wl_tag === 'winner' && 'border-l-2 border-green-400',
                                r.wl_tag === 'loser'  && 'border-l-2 border-red-400',
                            )}>
                                <td className="px-4 py-2.5 text-sm font-medium text-zinc-800 whitespace-nowrap">{r.date}</td>
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-700">{formatCurrency(r.revenue, currency, true)}</td>
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-700">{formatNumber(r.orders)}</td>
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-600">{r.aov != null ? formatCurrency(r.aov, currency, true) : '—'}</td>
                                <td className="px-3 py-2.5 text-right text-sm text-zinc-600">{formatNumber(r.items_sold)}</td>
                                {has_ads && <>
                                    <td className="px-3 py-2.5 text-right text-sm text-zinc-600">{r.ad_spend != null ? formatCurrency(r.ad_spend, currency, true) : '—'}</td>
                                    <td className={cn('px-3 py-2.5 text-right text-sm font-medium', r.roas == null ? 'text-zinc-400' : r.roas >= 1 ? 'text-green-700' : 'text-red-600')}>
                                        {r.roas != null ? `${r.roas}x` : '—'}
                                    </td>
                                    <td className="px-3 py-2.5 text-right text-sm text-zinc-500">{r.marketing_pct != null ? `${r.marketing_pct}%` : '—'}</td>
                                </>}
                                <td className="px-3 py-2.5 text-xs text-zinc-400 max-w-[160px] truncate">{r.note ?? ''}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function StorePage(props: Props) {
    const { workspace } = usePage<PageProps>().props;
    const w = (path: string) => wurl(workspace?.slug, path);
    const currency = workspace?.reporting_currency ?? 'EUR';

    const {
        tab, from, to, store_ids = [], narrative,
        sort_by = 'revenue', sort_dir = 'desc',
        filter = 'all', selected_country = null,
    } = props;

    function buildTabUrl(key: string): string {
        const params = new URLSearchParams({ tab: key, from, to });
        if (store_ids.length > 0) params.set('store_ids', store_ids.join(','));
        return `${w('/store')}?${params.toString()}`;
    }

    const TABS: DestinationTab[] = [
        { key: 'products',  label: 'Products',  href: buildTabUrl('products') },
        { key: 'customers', label: 'Customers', href: buildTabUrl('customers') },
        { key: 'cohorts',   label: 'Cohorts',   href: buildTabUrl('cohorts') },
        { key: 'countries', label: 'Countries', href: buildTabUrl('countries') },
        { key: 'orders',    label: 'Orders',    href: buildTabUrl('orders') },
    ];

    function navigate(extra: Record<string, unknown> = {}) {
        const params: Record<string, string> = { tab, from, to };
        if (store_ids.length > 0) params.store_ids = store_ids.join(',');
        Object.entries(extra).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') {
                params[k] = String(v);
            }
        });
        router.get(w('/store'), params, { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="Store" />
            <div className="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
                <PageHeader
                    title="Store"
                    narrative={narrative}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <DateRangePicker />
                            <StoreFilter selectedStoreIds={store_ids} />
                        </div>
                    }
                />

                <DestinationTabs tabs={TABS} activeKey={tab} />

                {tab === 'products' && (
                    <ProductsTab
                        products={props.products ?? []}
                        products_total_count={props.products_total_count ?? 0}
                        has_cogs={props.has_cogs ?? false}
                        winner_rows={props.winner_rows ?? []}
                        loser_rows={props.loser_rows ?? []}
                        hero={props.hero ?? {}}
                        sort_by={sort_by}
                        sort_dir={sort_dir}
                        view={props.view ?? 'table'}
                        filter={filter}
                        from={from}
                        to={to}
                        store_ids={store_ids}
                        currency={currency}
                        navigate={navigate}
                    />
                )}

                {tab === 'customers' && (
                    <CustomersTab
                        hero={props.hero ?? {}}
                        rfm_cells={props.rfm_cells ?? []}
                        new_vs_returning={props.new_vs_returning ?? []}
                        currency={currency}
                    />
                )}

                {tab === 'cohorts' && (
                    <CohortsTab
                        cohort_rows={props.cohort_rows ?? []}
                        weighted_avg={props.weighted_avg ?? []}
                        available_channels={props.available_channels ?? []}
                        active_channel={props.active_channel ?? null}
                        from={from}
                        to={to}
                        store_ids={store_ids}
                        currency={currency}
                        navigate={navigate}
                    />
                )}

                {tab === 'countries' && (
                    <CountriesTab
                        countries={props.countries ?? []}
                        countries_total_count={props.countries_total_count ?? 0}
                        has_ads={props.has_ads ?? false}
                        hero={props.hero ?? {}}
                        top_products={props.top_products ?? []}
                        selected_country={selected_country}
                        sort_by={sort_by}
                        sort_dir={sort_dir}
                        filter={filter}
                        from={from}
                        to={to}
                        store_ids={store_ids}
                        currency={currency}
                        navigate={(extra) => navigate({ ...extra, tab: 'countries' })}
                    />
                )}

                {tab === 'orders' && (
                    <OrdersTab
                        rows={props.rows ?? []}
                        totals={props.totals ?? {}}
                        hero={props.hero ?? {}}
                        has_ads={props.has_ads ?? false}
                        sort_by={sort_by}
                        sort_dir={sort_dir}
                        hide_empty={props.hide_empty ?? false}
                        from={from}
                        to={to}
                        store_ids={store_ids}
                        currency={currency}
                        navigate={(extra) => navigate({ ...extra, tab: 'orders' })}
                    />
                )}
            </div>
        </AppLayout>
    );
}
