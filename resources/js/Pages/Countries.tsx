import { useEffect, useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level lets us start with
// navigating=true so the skeleton stays visible until the real data is ready.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import { Globe, Package } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { AnalyticsTabBar } from '@/Components/shared/AnalyticsTabBar';
import { StoreFilter } from '@/Components/shared/StoreFilter';
import { MetricCard } from '@/Components/shared/MetricCard';
import { SortButton } from '@/Components/shared/SortButton';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

// ── Country helpers ──────────────────────────────────────────────────────────

const COUNTRY_NAMES: Record<string, string> = {
    AD: 'Andorra',         AE: 'UAE',              AT: 'Austria',
    AU: 'Australia',       BE: 'Belgium',           BG: 'Bulgaria',
    BR: 'Brazil',          CA: 'Canada',            CH: 'Switzerland',
    CN: 'China',           CY: 'Cyprus',            CZ: 'Czech Republic',
    DE: 'Germany',         DK: 'Denmark',           EE: 'Estonia',
    ES: 'Spain',           FI: 'Finland',           FR: 'France',
    GB: 'United Kingdom',  GR: 'Greece',            HR: 'Croatia',
    HU: 'Hungary',         IE: 'Ireland',           IT: 'Italy',
    JP: 'Japan',           KR: 'South Korea',       LT: 'Lithuania',
    LU: 'Luxembourg',      LV: 'Latvia',            MT: 'Malta',
    MX: 'Mexico',          NL: 'Netherlands',       NO: 'Norway',
    NZ: 'New Zealand',     PL: 'Poland',            PT: 'Portugal',
    RO: 'Romania',         RU: 'Russia',            SE: 'Sweden',
    SI: 'Slovenia',        SK: 'Slovakia',          TR: 'Turkey',
    UA: 'Ukraine',         US: 'United States',
};

function countryFlag(code: string): string {
    const A = 0x1F1E6;
    return code.toUpperCase().split('').map(c =>
        String.fromCodePoint(c.charCodeAt(0) - 65 + A)
    ).join('');
}

function countryName(code: string): string {
    return COUNTRY_NAMES[code] ?? code;
}

// ── Types ────────────────────────────────────────────────────────────────────

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

interface HeroMetrics {
    countries_with_orders: number;
    top_country_share: number;
    countries_above_avg_margin: number;
    profitable_roas_countries: number;
}

interface TopProduct {
    product_external_id: string;
    product_name: string;
    units: number;
    revenue: number | null;
    image_url: string | null;
}

interface Props {
    countries: CountryRow[];
    countries_total_count: number;
    has_ads: boolean;
    hero: HeroMetrics;
    top_products: TopProduct[];
    selected_country: string | null;
    from: string;
    to: string;
    store_ids: number[];
    sort_by: string;
    sort_dir: 'asc' | 'desc';
    filter: 'all' | 'winners' | 'losers';
    classifier: 'peer' | 'period' | null;
    active_classifier: 'peer' | 'period';
    narrative: string | null;
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function Countries(props: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const [navigating, setNavigating] = useState(() => _inertiaNavigating);

    const {
        countries, countries_total_count, has_ads, hero,
        top_products, selected_country,
        from, to, store_ids, sort_by, sort_dir, filter, active_classifier,
        narrative,
    } = props;

    useEffect(() => {
        const off1 = router.on('start',  () => setNavigating(true));
        const off2 = router.on('finish', () => setNavigating(false));
        return () => { off1(); off2(); };
    }, []);

    const currentParams = useMemo(() => ({
        from, to,
        ...(store_ids.length > 0 ? { store_ids: store_ids.join(',') } : {}),
        sort_by, sort_dir,
        ...(filter !== 'all' ? { filter } : {}),
        ...(selected_country ? { country: selected_country } : {}),
    }), [from, to, store_ids, sort_by, sort_dir, filter, selected_country]);

    function navigate(params: Record<string, string | undefined>) {
        router.get(
            wurl(workspace?.slug, '/countries'),
            params as Record<string, string>,
            { preserveState: true, replace: true },
        );
    }

    function setSort(col: string) {
        const newDir = sort_by === col && sort_dir === 'desc' ? 'asc' : 'desc';
        navigate({ ...currentParams, sort_by: col, sort_dir: newDir });
    }

    function setFilter(f: 'all' | 'winners' | 'losers') {
        navigate({ ...currentParams, ...(f !== 'all' ? { filter: f } : { filter: undefined }) });
    }

    function handleCountryClick(code: string) {
        const next = selected_country === code ? undefined : code;
        navigate({ ...currentParams, country: next });
    }

    const sortBtn = (col: string, label: string) => (
        <SortButton col={col} label={label} currentSort={sort_by} currentDir={sort_dir} onSort={setSort} />
    );

    const selectedName = selected_country ? countryName(selected_country) : null;

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title="Analytics — By Country" />
            <PageHeader title="Analytics" subtitle="Revenue by country" narrative={narrative} />
            <AnalyticsTabBar />
            <StoreFilter selectedStoreIds={store_ids} />

            {/* ── Hero cards ───────────────────────────────────────────────────── */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <MetricCard
                    label="Countries"
                    source="store"
                    value={String(hero.countries_with_orders)}
                    loading={navigating}
                    tooltip="Number of countries with at least one order in this period."
                />
                <MetricCard
                    label="Top Country Share"
                    source="store"
                    value={`${hero.top_country_share.toFixed(1)}%`}
                    loading={navigating}
                    tooltip="Revenue share of the largest country."
                />
                <MetricCard
                    label="Above Avg Margin"
                    source="real"
                    value={String(hero.countries_above_avg_margin)}
                    loading={navigating}
                    tooltip="Countries with contribution margin above the workspace average."
                />
                {has_ads && (
                    <MetricCard
                        label="Profitable ROAS"
                        source="real"
                        value={String(hero.profitable_roas_countries)}
                        loading={navigating}
                        tooltip="Countries where Real ROAS >= 1.0 (revenue covers ad spend)."
                    />
                )}
            </div>

            {/* ── W/L filter chips ─────────────────────────────────────────────── */}
            {has_ads && countries.length > 0 && (
                <div className="mb-4 flex items-center gap-2">
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
                                {countries.length} / {countries_total_count}
                            </span>
                        )}
                    </div>
                </div>
            )}

            {/* ── Main content ─────────────────────────────────────────────────── */}
            {countries.length === 0 && !navigating ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100">
                        <Globe className="h-6 w-6 text-zinc-400" />
                    </div>
                    <h3 className="mb-1 text-base font-semibold text-zinc-900">No country data</h3>
                    <p className="max-w-xs text-sm text-zinc-500">
                        Country data is derived from customer billing addresses on orders.
                    </p>
                </div>
            ) : (
                <div className={selected_country ? 'grid grid-cols-1 gap-6 lg:grid-cols-2' : 'block'}>
                    {/* ── Side-by-side country table ──────────────────────────── */}
                    <div className="rounded-xl border border-zinc-200 bg-white">
                        <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4">
                            <div className="text-sm font-medium text-zinc-500">
                                Countries
                                <span className="ml-2 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500">
                                    {countries.length}
                                </span>
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left th-label">
                                        <th className="px-5 py-3">Country</th>
                                        <th className="px-3 py-3 text-right">{sortBtn('orders', 'Orders')}</th>
                                        <th className="px-3 py-3 text-right">{sortBtn('revenue', 'Revenue')}</th>
                                        <th className="px-3 py-3 text-right">Share</th>
                                        <th className="px-3 py-3 text-right">{sortBtn('gsc_clicks', 'GSC')}</th>
                                        {has_ads && (
                                            <>
                                                <th className="px-3 py-3 text-right">{sortBtn('fb_spend', 'FB Spend')}</th>
                                                <th className="px-3 py-3 text-right">{sortBtn('google_spend', 'G Spend')}</th>
                                                <th className="px-3 py-3 text-right">{sortBtn('real_roas', 'Real ROAS')}</th>
                                            </>
                                        )}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-zinc-100">
                                    {countries.map(c => (
                                        <tr
                                            key={c.country_code}
                                            onClick={() => handleCountryClick(c.country_code)}
                                            className={cn(
                                                'cursor-pointer transition-colors',
                                                selected_country === c.country_code
                                                    ? 'bg-primary/5'
                                                    : 'hover:bg-zinc-50',
                                            )}
                                        >
                                            <td className="px-5 py-3">
                                                <span className="font-medium text-zinc-800">
                                                    {countryFlag(c.country_code)}{' '}
                                                    {countryName(c.country_code)}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3 text-right tabular-nums text-zinc-700">
                                                {formatNumber(c.orders)}
                                            </td>
                                            <td className="px-3 py-3 text-right tabular-nums text-zinc-700">
                                                {formatCurrency(c.revenue, currency)}
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <div className="h-1.5 w-16 rounded-full bg-zinc-100 overflow-hidden">
                                                        <div
                                                            className="h-full rounded-full bg-primary/60"
                                                            style={{ width: `${Math.min(c.share, 100)}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-xs tabular-nums text-zinc-500 w-10 text-right">
                                                        {c.share.toFixed(1)}%
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-3 py-3 text-right tabular-nums text-zinc-500">
                                                {c.gsc_clicks != null ? formatNumber(c.gsc_clicks) : '—'}
                                            </td>
                                            {has_ads && (
                                                <>
                                                    <td className="px-3 py-3 text-right tabular-nums text-zinc-500">
                                                        {c.fb_spend != null ? formatCurrency(c.fb_spend, currency) : '—'}
                                                    </td>
                                                    <td className="px-3 py-3 text-right tabular-nums text-zinc-500">
                                                        {c.google_spend != null ? formatCurrency(c.google_spend, currency) : '—'}
                                                    </td>
                                                    <td className="px-3 py-3 text-right tabular-nums font-medium">
                                                        {c.real_roas != null ? (
                                                            <span className={c.real_roas >= 1 ? 'text-green-700' : 'text-red-600'}>
                                                                {c.real_roas.toFixed(2)}×
                                                            </span>
                                                        ) : (
                                                            <span className="text-zinc-400">—</span>
                                                        )}
                                                    </td>
                                                </>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* ── Drill-down panel ─────────────────────────────────────── */}
                    {selected_country && (
                        <div className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
                            <div className="border-b border-zinc-100 px-4 py-3">
                                <h3 className="text-sm font-semibold text-zinc-900">
                                    Top products in {selectedName}
                                </h3>
                                <p className="text-xs text-zinc-400 mt-0.5">
                                    Based on revenue share of orders with {selected_country} billing address
                                </p>
                            </div>
                            {top_products.length === 0 ? (
                                <div className="flex flex-col items-center justify-center px-6 py-12 text-center">
                                    <p className="text-sm text-zinc-400">No product data for this country.</p>
                                </div>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-zinc-100 text-left th-label">
                                            <th className="px-4 py-3 w-10">#</th>
                                            <th className="px-4 py-3">Product</th>
                                            <th className="px-4 py-3 text-right hidden sm:table-cell">Units</th>
                                            <th className="px-4 py-3 text-right">Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-zinc-100">
                                        {top_products.map((p, index) => (
                                            <tr key={p.product_external_id} className="hover:bg-zinc-50 transition-colors">
                                                <td className="px-4 py-3 text-zinc-400 tabular-nums">{index + 1}</td>
                                                <td className="px-4 py-3">
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
                                                        <div className="font-medium text-zinc-900 truncate max-w-[200px]" title={p.product_name}>{p.product_name}</div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-right text-zinc-500 tabular-nums hidden sm:table-cell">
                                                    {formatNumber(p.units)}
                                                </td>
                                                <td className="px-4 py-3 text-right font-medium text-zinc-900 tabular-nums">
                                                    {p.revenue != null ? formatCurrency(p.revenue, currency) : '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    )}
                </div>
            )}
        </AppLayout>
    );
}
