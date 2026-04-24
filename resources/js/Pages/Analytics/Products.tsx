import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level lets us start with
// navigating=true so the skeleton stays visible until the real data is ready.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import { Package, Table2, Grid2X2, AlertTriangle } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { AnalyticsTabBar } from '@/Components/shared/AnalyticsTabBar';
import { StoreFilter } from '@/Components/shared/StoreFilter';
import { MetricCard } from '@/Components/shared/MetricCard';
import { QuadrantChart, type QuadrantPoint, type QuadrantFieldConfig } from '@/Components/charts/QuadrantChart';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface ProductRow {
    id: number | null;
    external_id: string;
    name: string;
    image_url: string | null;
    units: number;
    orders: number | null;
    revenue: number | null;
    total_cogs: number | null;
    contribution_margin: number | null;
    margin_pct: number | null;
    stock_status: string | null;
    stock_quantity: number | null;
    days_of_cover: number | null;
    trend_dots: (boolean | null)[];
    wl_tag: 'winner' | 'loser' | null;
}

interface HeroMetrics {
    total_units: number;
    total_revenue: number;
    total_margin: number | null;
    avg_margin_pct: number | null;
}

interface Props {
    products: ProductRow[];
    products_total_count: number;
    has_cogs: boolean;
    hero: HeroMetrics;
    from: string;
    to: string;
    store_ids: number[];
    sort_by: string;
    sort_dir: 'asc' | 'desc';
    view: 'table' | 'scatter';
    filter: 'all' | 'winners' | 'losers';
    classifier: 'peer' | 'period' | null;
    active_classifier: 'peer' | 'period';
    narrative: string | null;
}

// ─── Stock status badge ──────────────────────────────────────────────────────

const STOCK_COLORS: Record<string, string> = {
    instock:      'bg-green-500',
    outofstock:   'bg-red-500',
    onbackorder:  'bg-amber-500',
};

function StockDot({ status }: { status: string | null }) {
    if (!status) return null;
    const normalized = status.replace(/[_-]/g, '').toLowerCase();
    const color = STOCK_COLORS[normalized] ?? 'bg-zinc-300';
    const label = normalized === 'instock' ? 'In stock'
        : normalized === 'outofstock' ? 'Out of stock'
        : normalized === 'onbackorder' ? 'On backorder'
        : status;
    return (
        <span className="inline-flex items-center gap-1.5" title={label}>
            <span className={cn('h-2 w-2 rounded-full', color)} />
            <span className="text-xs text-zinc-500">{label}</span>
        </span>
    );
}

// ─── Trend dot strip ─────────────────────────────────────────────────────────

function TrendDots({ dots }: { dots: (boolean | null)[] }) {
    return (
        <div className="flex items-center gap-0.5">
            {dots.slice(0, 14).map((dot, i) => (
                <span
                    key={i}
                    className={cn('h-1.5 w-1.5 rounded-full', {
                        'bg-teal-400': dot === true,
                        'bg-red-400': dot === false,
                        'bg-zinc-200': dot === null,
                    })}
                />
            ))}
        </div>
    );
}

// ─── Sort button ─────────────────────────────────────────────────────────────

function SortButton({
    col, label, currentSort, currentDir, onSort,
}: {
    col: string; label: string; currentSort: string; currentDir: 'asc' | 'desc'; onSort: (col: string) => void;
}) {
    const active = currentSort === col;
    return (
        <button
            onClick={() => onSort(col)}
            className={cn('inline-flex items-center gap-1 hover:text-zinc-700 transition-colors whitespace-nowrap', active ? 'text-primary' : 'text-zinc-400')}
        >
            {label}
            {active && <span className="text-[10px]">{currentDir === 'desc' ? '↓' : '↑'}</span>}
        </button>
    );
}

// ─── Main page ───────────────────────────────────────────────────────────────

export default function AnalyticsProducts(props: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const [navigating, setNavigating] = useState(() => _inertiaNavigating);
    const [cogsBannerDismissed, setCogsBannerDismissed] = useState(false);

    const {
        products, products_total_count, has_cogs, hero,
        from, to, store_ids, sort_by, sort_dir, view, filter, active_classifier,
        narrative,
    } = props;

    useEffect(() => {
        const off1 = router.on('start',  () => setNavigating(true));
        const off2 = router.on('finish', () => setNavigating(false));
        return () => { off1(); off2(); };
    }, []);

    // ── Navigation helper ────────────────────────────────────────────────────
    const currentParams = useMemo(() => ({
        from, to,
        ...(store_ids.length > 0 ? { store_ids: store_ids.join(',') } : {}),
        sort_by, sort_dir, view,
        ...(filter !== 'all' ? { filter } : {}),
    }), [from, to, store_ids, sort_by, sort_dir, view, filter]);

    function navigate(params: Record<string, string | undefined>) {
        router.get(
            wurl(workspace?.slug, '/analytics/products'),
            params as Record<string, string>,
            { preserveState: true, replace: true },
        );
    }

    function setSort(col: string) {
        const newDir = sort_by === col && sort_dir === 'desc' ? 'asc' : 'desc';
        navigate({ ...currentParams, sort_by: col, sort_dir: newDir });
    }
    function setView(v: 'table' | 'scatter') {
        navigate({ ...currentParams, view: v });
    }
    function setFilter(f: 'all' | 'winners' | 'losers') {
        navigate({ ...currentParams, ...(f !== 'all' ? { filter: f } : { filter: undefined }) });
    }

    const sortBtn = (col: string, label: string) => (
        <SortButton col={col} label={label} currentSort={sort_by} currentDir={sort_dir} onSort={setSort} />
    );

    // ── Empty state ──────────────────────────────────────────────────────────
    if (!navigating && products.length === 0 && filter === 'all') {
        return (
            <AppLayout dateRangePicker={<DateRangePicker />}>
                <Head title="Analytics — By Product" />
                <PageHeader title="Analytics" subtitle="Product performance" narrative={narrative} />
                <AnalyticsTabBar />
                <StoreFilter selectedStoreIds={store_ids} />
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100">
                        <Package className="h-6 w-6 text-zinc-400" />
                    </div>
                    <h3 className="mb-1 text-base font-semibold text-zinc-900">No product data</h3>
                    <p className="max-w-xs text-sm text-zinc-500">
                        Product data is derived from order snapshots. It appears after the nightly
                        snapshot job has run.
                    </p>
                </div>
            </AppLayout>
        );
    }

    // ── Scatter data ─────────────────────────────────────────────────────────
    const scatterData: QuadrantPoint[] = useMemo(
        () => products
            .filter(p => p.revenue != null && p.revenue > 0)
            .map(p => ({
                id: p.external_id,
                label: p.name,
                x: p.revenue!,
                y: p.margin_pct,
                size: p.units,
                color: p.stock_status ?? 'unknown',
            })),
        [products],
    );

    const scatterConfig: QuadrantFieldConfig = useMemo(() => ({
        xLabel: 'Revenue',
        yLabel: 'Margin %',
        sizeLabel: 'Units',
        colorLabel: 'Stock',
        xFormatter: (v) => formatCurrency(v, currency),
        yFormatter: (v) => v != null ? `${v.toFixed(1)}%` : 'N/A',
        sizeFormatter: (v) => v != null ? formatNumber(v) : '—',
        yThreshold: 0,
        yThresholdLabel: '0% margin',
        colorMode: 'category' as const,
        categoryColors: {
            instock: '#16a34a',
            outofstock: '#dc2626',
            onbackorder: '#d97706',
            unknown: '#a1a1aa',
        },
        topRightLabel: 'Profit Winners',
        topLeftLabel: 'High-Margin Low-Volume',
        bottomRightLabel: 'Revenue Traps',
        bottomLeftLabel: 'Ignore',
    }), [currency]);

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title="Analytics — By Product" />
            <PageHeader title="Analytics" subtitle="Product performance" />
            <AnalyticsTabBar />
            <StoreFilter selectedStoreIds={store_ids} />

            {/* ── COGS empty-state banner ──────────────────────────────────────── */}
            {!has_cogs && !cogsBannerDismissed && (
                <div className="mb-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                    <div className="flex-1">
                        <p className="text-sm font-medium text-amber-800">Add product costs to see real profit</p>
                        <p className="mt-0.5 text-xs text-amber-700">
                            Enable Cost of Goods Sold in WooCommerce (Analytics → Settings) or{' '}
                            <Link
                                href={wurl(workspace?.slug, '/manage/product-costs')}
                                className="underline underline-offset-2 hover:text-amber-900"
                            >
                                enter costs manually / upload a CSV
                            </Link>
                            . Margin and profit columns are hidden until COGS data is available.
                        </p>
                    </div>
                    <button
                        onClick={() => setCogsBannerDismissed(true)}
                        className="text-xs text-amber-600 hover:text-amber-800"
                    >
                        Dismiss
                    </button>
                </div>
            )}

            {/* ── Hero cards ───────────────────────────────────────────────────── */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <MetricCard
                    label="Products Sold"
                    source="store"
                    value={formatNumber(hero.total_units)}
                    loading={navigating}
                    tooltip="Total units sold in the selected period."
                />
                <MetricCard
                    label="Total Revenue"
                    source="store"
                    value={formatCurrency(hero.total_revenue, currency)}
                    loading={navigating}
                    tooltip="Total product revenue in the selected period."
                />
                {has_cogs && (
                    <MetricCard
                        label="Contribution Margin"
                        source="real"
                        value={hero.total_margin != null ? formatCurrency(hero.total_margin, currency) : null}
                        loading={navigating}
                        tooltip="Revenue minus cost of goods sold. Computed from order-level COGS data."
                    />
                )}
                {has_cogs && (
                    <MetricCard
                        label="Avg Margin %"
                        source="real"
                        value={hero.avg_margin_pct != null ? `${hero.avg_margin_pct}%` : null}
                        loading={navigating}
                        tooltip="Total contribution margin divided by total revenue, as a percentage."
                    />
                )}
                {!has_cogs && (
                    <>
                        <MetricCard label="Contribution Margin" value={null} loading={navigating} subtext="Requires COGS" />
                        <MetricCard label="Avg Margin %" value={null} loading={navigating} subtext="Requires COGS" />
                    </>
                )}
            </div>

            {/* ── Filter bar ───────────────────────────────────────────────────── */}
            <div className="mb-4 flex flex-wrap items-center gap-3">
                {/* W/L chips */}
                <div className="flex items-center gap-1">
                    {(['all', 'winners', 'losers'] as const).map(f => (
                        <button
                            key={f}
                            onClick={() => setFilter(f)}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                filter === f
                                    ? f === 'winners'
                                        ? 'border-green-300 bg-green-50 text-green-700'
                                        : f === 'losers'
                                        ? 'border-red-300 bg-red-50 text-red-700'
                                        : 'border-primary bg-primary/10 text-primary'
                                    : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 hover:text-zinc-700',
                            )}
                        >
                            {f === 'all' ? 'All' : f === 'winners' ? 'Winners' : 'Losers'}
                        </button>
                    ))}
                    {filter !== 'all' && (
                        <span className="text-xs text-zinc-400">
                            {products.length} / {products_total_count}
                        </span>
                    )}
                </div>

                {/* View toggle */}
                <div className="ml-auto flex items-center gap-1 rounded-lg border border-zinc-200 bg-zinc-50 p-0.5">
                    <button
                        onClick={() => setView('table')}
                        title="Table view"
                        className={cn(
                            'rounded-md p-1.5 transition-colors',
                            view === 'table' ? 'bg-white shadow-sm text-zinc-800' : 'text-zinc-400 hover:text-zinc-600',
                        )}
                    >
                        <Table2 className="h-4 w-4" />
                    </button>
                    <button
                        onClick={() => setView('scatter')}
                        title="Scatter view"
                        className={cn(
                            'rounded-md p-1.5 transition-colors',
                            view === 'scatter' ? 'bg-white shadow-sm text-zinc-800' : 'text-zinc-400 hover:text-zinc-600',
                        )}
                    >
                        <Grid2X2 className="h-4 w-4" />
                    </button>
                </div>
            </div>

            {/* ── Table view ───────────────────────────────────────────────────── */}
            {view === 'table' && (
                <div className="rounded-xl border border-zinc-200 bg-white">
                    <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4">
                        <div className="text-sm font-medium text-zinc-500">
                            Products
                            {products.length > 0 && (
                                <span className="ml-2 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500">
                                    {products.length}
                                </span>
                            )}
                        </div>
                    </div>

                    {products.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-16 text-center">
                            <p className="text-sm text-zinc-400">No products match the current filter.</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left th-label">
                                        <th className="px-5 py-3">Product</th>
                                        <th className="px-3 py-3 text-right">{sortBtn('units', 'Units')}</th>
                                        <th className="px-3 py-3 text-right">{sortBtn('revenue', 'Revenue')}</th>
                                        {has_cogs && (
                                            <>
                                                <th className="px-3 py-3 text-right">COGS</th>
                                                <th className="px-3 py-3 text-right">{sortBtn('contribution_margin', 'Margin')}</th>
                                                <th className="px-3 py-3 text-right">{sortBtn('margin_pct', 'Margin %')}</th>
                                            </>
                                        )}
                                        <th className="px-3 py-3 text-center">Stock</th>
                                        <th className="px-3 py-3 text-right">Cover</th>
                                        <th className="px-3 py-3">
                                            <abbr title="Daily sales trend over the last 14 days. Teal = day with sales, red = day without, grey = no data." className="cursor-help no-underline">14d</abbr>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-zinc-100">
                                    {products.map(p => (
                                        <tr key={p.external_id} className="hover:bg-zinc-50">
                                            <td className="max-w-[250px] px-5 py-3">
                                                {p.id !== null ? (
                                                    <Link
                                                        href={wurl(workspace?.slug, `/analytics/products/${p.id}`)}
                                                        className="flex items-center gap-3"
                                                    >
                                                        {p.image_url ? (
                                                            <img
                                                                src={p.image_url}
                                                                alt=""
                                                                className="h-8 w-8 rounded object-cover shrink-0"
                                                                loading="lazy"
                                                            />
                                                        ) : (
                                                            <div className="flex h-8 w-8 items-center justify-center rounded bg-zinc-100 shrink-0">
                                                                <Package className="h-4 w-4 text-zinc-300" />
                                                            </div>
                                                        )}
                                                        <span className="truncate font-medium text-zinc-800 hover:text-zinc-950" title={p.name}>
                                                            {p.name}
                                                        </span>
                                                    </Link>
                                                ) : (
                                                    <div className="flex items-center gap-3">
                                                        {p.image_url ? (
                                                            <img
                                                                src={p.image_url}
                                                                alt=""
                                                                className="h-8 w-8 rounded object-cover shrink-0"
                                                                loading="lazy"
                                                            />
                                                        ) : (
                                                            <div className="flex h-8 w-8 items-center justify-center rounded bg-zinc-100 shrink-0">
                                                                <Package className="h-4 w-4 text-zinc-300" />
                                                            </div>
                                                        )}
                                                        <span className="truncate font-medium text-zinc-800" title={p.name}>
                                                            {p.name}
                                                        </span>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-3 text-right tabular-nums text-zinc-700">
                                                {formatNumber(p.units)}
                                            </td>
                                            <td className="px-3 py-3 text-right tabular-nums text-zinc-700">
                                                {p.revenue != null ? formatCurrency(p.revenue, currency) : '—'}
                                            </td>
                                            {has_cogs && (
                                                <>
                                                    <td className="px-3 py-3 text-right tabular-nums text-zinc-500">
                                                        {p.total_cogs != null ? formatCurrency(p.total_cogs, currency) : '—'}
                                                    </td>
                                                    <td className="px-3 py-3 text-right tabular-nums font-medium">
                                                        {p.contribution_margin != null ? (
                                                            <span className={p.contribution_margin >= 0 ? 'text-green-700' : 'text-red-600'}>
                                                                {formatCurrency(p.contribution_margin, currency)}
                                                            </span>
                                                        ) : (
                                                            <span className="text-zinc-400">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-3 text-right tabular-nums">
                                                        {p.margin_pct != null ? (
                                                            <span className={p.margin_pct >= 0 ? 'text-green-700' : 'text-red-600'}>
                                                                {p.margin_pct.toFixed(1)}%
                                                            </span>
                                                        ) : (
                                                            <span className="text-zinc-400">—</span>
                                                        )}
                                                    </td>
                                                </>
                                            )}
                                            <td className="px-3 py-3 text-center">
                                                <StockDot status={p.stock_status} />
                                            </td>
                                            <td className="px-3 py-3 text-right text-xs">
                                                {p.days_of_cover != null && p.days_of_cover <= 7 ? (
                                                    <span className="font-medium text-amber-600">{p.days_of_cover}d left</span>
                                                ) : p.days_of_cover != null ? (
                                                    <span className="text-zinc-400">{p.days_of_cover}d</span>
                                                ) : null}
                                            </td>
                                            <td className="px-3 py-3">
                                                <TrendDots dots={p.trend_dots} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}

            {/* ── Scatter view — QuadrantChart ─────────────────────────────────── */}
            {view === 'scatter' && (
                <div className="rounded-xl border border-zinc-200 bg-white p-5">
                    <div className="mb-3">
                        <div className="text-sm font-medium text-zinc-500">Product performance quadrant</div>
                        <p className="mt-0.5 text-xs text-zinc-400">
                            X = revenue, Y = margin %, bubble size = units, color = stock status.
                            Top-right = profit winners. Bottom-right = revenue traps.
                        </p>
                    </div>
                    {scatterData.length === 0 ? (
                        <div className="flex h-64 items-center justify-center">
                            <p className="text-sm text-zinc-400">No products with revenue data to display.</p>
                        </div>
                    ) : (
                        <QuadrantChart
                            data={scatterData}
                            config={scatterConfig}
                        />
                    )}
                </div>
            )}
        </AppLayout>
    );
}
