import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';

// Module-level flag so the component starts with navigating=true when Inertia
// swaps it mid-flight, preventing stale data flicker before real props arrive.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import { BarChart2, Table2, Grid2X2, Clock, Tags } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { MetricCard } from '@/Components/shared/MetricCard';
import { StatusBadge } from '@/Components/shared/StatusBadge';
import { SortButton } from '@/Components/shared/SortButton';
import { PlatformBadge } from '@/Components/shared/PlatformBadge';
import { ToggleGroup } from '@/Components/shared/ToggleGroup';
import { WlFilterBar } from '@/Components/shared/WlFilterBar';
import type { WlClassifier, WlFilter } from '@/Components/shared/WlFilterBar';
import {
    MotionScoreGauge,
    VerdictPill,
    CreativeGrid,
    PacingChart,
    AdDetailModal,
} from '@/Components/shared';
import type { MotionScore, CreativeCardData, PacingCampaign } from '@/Components/shared';
import {
    LineChart as RechartsLineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip as RechartsTooltip,
    ResponsiveContainer,
} from 'recharts';
import { QuadrantChart } from '@/Components/charts/QuadrantChart';
import type { QuadrantCampaign, QuadrantPoint } from '@/Components/charts/QuadrantChart';
import { formatCurrency, formatDate, formatNumber } from '@/lib/formatters';
import type { Granularity } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { syncDotClass, syncDotTitle } from '@/lib/syncStatus';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

type Level = 'campaign' | 'adset' | 'ad';

interface CampaignMetrics {
    roas:               number | null;
    cpo:                number | null;
    spend:              number | null;
    revenue:            number | null;
    attributed_revenue: number | null;
    attributed_orders:  number;
    real_roas:          number | null;
    real_cpo:           number | null;
    impressions:        number;
    clicks:             number;
    ctr:                number | null;
    cpc:                number | null;
}

interface BaseRow {
    id:                 number;
    name:               string;
    platform:           string;
    status:             string | null;
    spend:              number;
    impressions:        number;
    clicks:             number;
    ctr:                number | null;
    cpc:                number | null;
    cpa:                number | null;
    platform_roas:      number | null;
    real_roas:          number | null;
    real_cpo:           number | null;
    attributed_revenue: number | null;
    attributed_orders:  number;
    target_roas:        number | null;
    wl_tag:             'winner' | 'loser' | null;
}

interface CampaignRow extends BaseRow {
    first_order_roas:    number | null;
    day30_roas:          number | null;
    day30_pending:       boolean;
    day30_locks_in_days: number | null;
    spend_velocity:      number | null;
    motion_score:        MotionScore | null;
    verdict:             string | null;
}

interface AdsetRow extends BaseRow {
    campaign_id:   number;
    campaign_name: string;
}

interface AdRow extends BaseRow {
    effective_status: string | null;
    campaign_id:      number;
    campaign_name:    string;
    adset_id:         number;
    adset_name:       string;
    thumbnail_url:    string | null;
    headline:         string | null;
}

type HierarchyRow = CampaignRow | AdsetRow | AdRow;

interface SpendChartPoint {
    date:     string;
    facebook: number;
    google:   number;
}

interface AdAccountOption {
    id:             number;
    platform:       string;
    name:           string;
    status:         string;
    last_synced_at: string | null;
}

interface TagCategory {
    name:  string;
    label: string;
    tags:  Array<{ name: string; label: string }>;
}

interface Props {
    has_ad_accounts:      boolean;
    ad_accounts:          AdAccountOption[];
    ad_account_ids:       number[];
    level:                Level;
    tab:                  'performance' | 'pacing';
    metrics:              CampaignMetrics | null;
    compare_metrics:      CampaignMetrics | null;
    rows:                 HierarchyRow[];
    rows_total_count:     number;
    creative_cards:       CreativeCardData[];
    pacing_data:          PacingCampaign[];
    campaign_name:        string | null;
    adset_name:           string | null;
    campaign_id:          number | null;
    adset_id:             number | null;
    chart_data:           SpendChartPoint[];
    compare_chart_data:   SpendChartPoint[] | null;
    total_revenue:        number | null;
    unattributed_revenue: number | null;
    not_tracked_pct:      number | null;
    workspace_target_roas: number | null;
    hide_inactive:        boolean;
    wl_has_target:        boolean;
    active_classifier:    'target' | 'peer' | 'period';
    wl_peer_avg_roas:     number | null;
    filter:               WlFilter;
    classifier:           WlClassifier | null;
    from:                 string;
    to:                   string;
    compare_from:         string | null;
    compare_to:           string | null;
    granularity:          Granularity;
    platform:             'all' | 'facebook' | 'google';
    status:               'all' | 'active' | 'paused';
    view:                 'table' | 'quadrant' | 'format_analysis';
    sort:                 string;
    direction:            'asc' | 'desc';
    narrative:            string | null;
    tag_categories:       TagCategory[];
    hit_rate_data:        Record<string, QuadrantPoint[]>;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function pctChange(current: number | null, previous: number | null): number | null {
    if (current === null || previous === null || previous === 0) return null;
    return ((current - previous) / previous) * 100;
}

// ─── Velocity badge ───────────────────────────────────────────────────────────

function SpendVelocityBadge({ velocity }: { velocity: number | null }) {
    if (velocity === null) return <span className="text-zinc-400">—</span>;
    const pct    = Math.round(velocity * 100);
    const isHigh = velocity > 1.15;
    const isLow  = velocity < 0.85;
    const color  = isHigh ? 'text-amber-700' : isLow ? 'text-blue-600' : 'text-green-700';
    return (
        <span className={cn('tabular-nums font-medium', color)} title={`${pct}% of expected pacing`}>
            {pct}%
        </span>
    );
}

// ─── Day-30 ROAS cell ─────────────────────────────────────────────────────────

function Day30Badge({ roas, pending, locksInDays }: {
    roas:        number | null;
    pending:     boolean;
    locksInDays: number | null;
}) {
    if (pending) {
        return (
            <span
                className="inline-flex items-center gap-1 text-zinc-400 text-xs"
                title={locksInDays != null
                    ? `30-day window closes in ${locksInDays} day${locksInDays === 1 ? '' : 's'}`
                    : 'Awaiting 30-day cohort window'}
            >
                <Clock className="h-3 w-3" />
                {locksInDays != null ? `${locksInDays}d` : '—'}
            </span>
        );
    }
    if (roas === null) return <span className="text-zinc-400">—</span>;
    return <span className="tabular-nums font-medium text-zinc-700">{roas.toFixed(2)}×</span>;
}

// ─── Spend chart ──────────────────────────────────────────────────────────────

const SPEND_PLATFORMS = [
    { dataKey: 'facebook' as const, name: 'Facebook', color: 'var(--chart-1)' },
    { dataKey: 'google'   as const, name: 'Google',   color: 'var(--chart-4)' },
];

function SpendChart({
    chartData,
    granularity,
    currency,
    navigating,
}: {
    chartData:   SpendChartPoint[];
    granularity: Granularity;
    currency:    string;
    navigating:  boolean;
}) {
    const activePlatforms = useMemo(
        () => SPEND_PLATFORMS.filter((p) => chartData.some((d) => (d[p.dataKey] ?? 0) > 0)),
        [chartData],
    );

    const [visible, setVisible] = useState<Set<string>>(
        () => new Set(activePlatforms.map((p) => p.dataKey)),
    );

    useEffect(() => {
        setVisible(new Set(activePlatforms.map((p) => p.dataKey)));
    }, [activePlatforms.map((p) => p.dataKey).join(',')]);

    function toggle(key: string) {
        setVisible((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                if (next.size === 1) return prev;
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    }

    return (
        <div className="mb-4 rounded-xl border border-zinc-200 bg-white p-5">
            <div className="mb-1 text-sm font-medium text-zinc-500">Daily ad spend</div>
            {navigating ? (
                <div className="h-52 w-full animate-pulse rounded-lg bg-zinc-100" />
            ) : chartData.length === 0 ? (
                <div className="flex h-52 flex-col items-center justify-center gap-2">
                    <p className="text-sm text-zinc-400">No spend data for this period.</p>
                </div>
            ) : (
                <>
                    {activePlatforms.length > 1 && (
                        <div className="mb-3 flex flex-wrap gap-1.5">
                            {activePlatforms.map((p) => {
                                const on = visible.has(p.dataKey);
                                return (
                                    <button
                                        key={p.dataKey}
                                        onClick={() => toggle(p.dataKey)}
                                        className={cn(
                                            'flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                                            !on && 'border-zinc-200 bg-white text-zinc-400 hover:text-zinc-600',
                                        )}
                                        style={on ? { backgroundColor: p.color, borderColor: p.color, color: 'white' } : undefined}
                                    >
                                        <span
                                            className="h-1.5 w-1.5 rounded-full"
                                            style={{ backgroundColor: on ? 'white' : p.color }}
                                        />
                                        {p.name}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    <ResponsiveContainer width="100%" height={200}>
                        <RechartsLineChart data={chartData} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" vertical={false} />
                            <XAxis
                                dataKey="date"
                                tick={{ fontSize: 11, fill: '#a1a1aa' }}
                                tickFormatter={(d) => formatDate(d, granularity)}
                                tickLine={false}
                                axisLine={false}
                                minTickGap={40}
                            />
                            <YAxis
                                tick={{ fontSize: 11, fill: '#a1a1aa' }}
                                tickFormatter={(v) => formatCurrency(v, currency, true)}
                                tickLine={false}
                                axisLine={false}
                                width={60}
                                domain={[0, 'auto']}
                            />
                            <RechartsTooltip
                                contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e4e4e7' }}
                                formatter={(value: unknown, key: unknown) => {
                                    const p = SPEND_PLATFORMS.find((s) => s.dataKey === String(key));
                                    return [formatCurrency(Number(value), currency), p?.name ?? String(key)] as [string, string];
                                }}
                                labelFormatter={(label) => formatDate(String(label), granularity)}
                            />
                            {activePlatforms.map((p) =>
                                visible.has(p.dataKey) ? (
                                    <Line
                                        key={p.dataKey}
                                        type="monotone"
                                        dataKey={p.dataKey}
                                        stroke={p.color}
                                        strokeWidth={2}
                                        dot={false}
                                        connectNulls
                                    />
                                ) : null,
                            )}
                        </RechartsLineChart>
                    </ResponsiveContainer>
                </>
            )}
        </div>
    );
}

// ─── Hierarchy table ──────────────────────────────────────────────────────────

function HierarchyTable({
    rows,
    level,
    currency,
    sort,
    direction,
    onSort,
    from,
    to,
    workspaceSlug,
    targetRoas,
}: {
    rows:          HierarchyRow[];
    level:         Level;
    currency:      string;
    sort:          string;
    direction:     'asc' | 'desc';
    onSort:        (col: string) => void;
    from:          string;
    to:            string;
    workspaceSlug: string | undefined;
    targetRoas:    number | null;
}) {
    const sortBtn = (col: string, label: string) => (
        <SortButton col={col} label={label} currentSort={sort} currentDir={direction} onSort={onSort} />
    );

    const isCampaignLevel = level === 'campaign';
    const isAdsetLevel    = level === 'adset';
    const isAdLevel       = level === 'ad';
    const levelLabel      = isCampaignLevel ? 'Campaign' : isAdsetLevel ? 'Ad Set' : 'Ad';

    return (
        <div className="rounded-xl border border-zinc-200 bg-white">
            <div className="flex items-center border-b border-zinc-100 px-5 py-4">
                <div className="text-sm font-medium text-zinc-500">
                    {levelLabel}s
                    {rows.length > 0 && (
                        <span className="ml-2 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500">
                            {rows.length}
                        </span>
                    )}
                </div>
            </div>

            {rows.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-16 text-center">
                    <p className="text-sm text-zinc-400">No {levelLabel.toLowerCase()} data for this period.</p>
                    <p className="mt-1 text-xs text-zinc-400">Data appears after the next sync completes.</p>
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left th-label">
                                <th className="px-4 py-3">{levelLabel}</th>
                                {(isAdsetLevel || isAdLevel) && (
                                    <th className="px-4 py-3">Campaign</th>
                                )}
                                <th className="px-4 py-3">Platform</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3 text-right">{sortBtn('spend', 'Spend')}</th>
                                <th className="px-4 py-3 text-right">{sortBtn('platform_roas', 'Plat. ROAS')}</th>
                                <th className="px-4 py-3 text-right">{sortBtn('real_roas', 'Real ROAS')}</th>
                                {isCampaignLevel && (
                                    <>
                                        <th className="px-4 py-3 text-right">
                                            <span title="Revenue from first-time customers attributed to this campaign ÷ spend. §F7">
                                                {sortBtn('first_order_roas', '1st ROAS')}
                                            </span>
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            <span title="30-day cohort revenue from acquired customers ÷ spend. Locks in 30 days after period ends. §F8">
                                                {sortBtn('day30_roas', 'D30 ROAS')}
                                            </span>
                                        </th>
                                    </>
                                )}
                                <th className="px-4 py-3 text-right">{sortBtn('cpa', 'CPA')}</th>
                                {isCampaignLevel && (
                                    <>
                                        <th className="px-4 py-3">Motion</th>
                                        <th className="px-4 py-3">Verdict</th>
                                    </>
                                )}
                                <th className="px-4 py-3 text-right">{sortBtn('real_cpo', 'Real CPO')}</th>
                                <th className="px-4 py-3 text-right">{sortBtn('attributed_revenue', 'Attr. Rev.')}</th>
                                <th className="px-4 py-3 text-right">{sortBtn('attributed_orders', 'Orders')}</th>
                                {isCampaignLevel && (
                                    <th className="px-4 py-3 text-right">
                                        <span title="Spend velocity: actual pace vs expected daily pace. 100% = on pace. Requires a budget set on the campaign.">
                                            {sortBtn('spend_velocity', 'Velocity')}
                                        </span>
                                    </th>
                                )}
                                <th className="px-4 py-3 text-right">{sortBtn('impressions', 'Impr.')}</th>
                                <th className="px-4 py-3 text-right">{sortBtn('clicks', 'Clicks')}</th>
                                <th className="px-4 py-3 text-right">{sortBtn('ctr', 'CTR')}</th>
                                <th className="px-4 py-3 text-right">{sortBtn('cpc', 'CPC')}</th>
                                {!isAdLevel && <th className="px-4 py-3 text-right">Drill</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {rows.map((row) => {
                                const c    = isCampaignLevel ? (row as CampaignRow) : null;
                                const adsr = isAdsetLevel    ? (row as AdsetRow)    : null;
                                const a    = isAdLevel       ? (row as AdRow)       : null;

                                const drillHref = c
                                    ? wurl(workspaceSlug, `/campaigns?level=adset&campaign_id=${row.id}&from=${from}&to=${to}`)
                                    : adsr
                                    ? wurl(workspaceSlug, `/campaigns?level=ad&campaign_id=${adsr.campaign_id}&adset_id=${row.id}&from=${from}&to=${to}`)
                                    : null;

                                const effectiveTarget = row.target_roas ?? targetRoas ?? null;

                                return (
                                    <tr key={row.id} className="hover:bg-zinc-50">
                                        {/* Name */}
                                        <td className="max-w-[180px] px-4 py-3">
                                            {drillHref ? (
                                                <Link
                                                    href={drillHref}
                                                    className="block truncate font-medium text-zinc-800 hover:text-primary transition-colors"
                                                    title={row.name}
                                                >
                                                    {row.name || '—'}
                                                </Link>
                                            ) : (
                                                <span className="block truncate font-medium text-zinc-800" title={row.name}>
                                                    {row.name || '—'}
                                                </span>
                                            )}
                                        </td>
                                        {/* Parent campaign (adset / ad level) */}
                                        {(isAdsetLevel || isAdLevel) && (
                                            <td className="max-w-[140px] px-4 py-3">
                                                <span
                                                    className="block truncate text-xs text-zinc-500"
                                                    title={(adsr ?? a)?.campaign_name}
                                                >
                                                    {(adsr ?? a)?.campaign_name || '—'}
                                                </span>
                                            </td>
                                        )}
                                        <td className="px-4 py-3">
                                            <PlatformBadge platform={row.platform} />
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.status
                                                ? <StatusBadge status={row.status} />
                                                : <span className="text-zinc-400">—</span>}
                                        </td>
                                        {/* Spend */}
                                        <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                            {row.spend > 0 ? formatCurrency(row.spend, currency) : '—'}
                                        </td>
                                        {/* Platform ROAS */}
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {row.platform_roas != null
                                                ? <span className="font-medium text-blue-600">{row.platform_roas.toFixed(2)}×</span>
                                                : <span className="text-zinc-400">—</span>}
                                        </td>
                                        {/* Real ROAS */}
                                        <td className="px-4 py-3 text-right tabular-nums font-medium">
                                            {row.real_roas != null
                                                ? (
                                                    <span className={
                                                        effectiveTarget !== null && row.real_roas >= effectiveTarget
                                                            ? 'text-green-700'
                                                            : 'text-red-600'
                                                    }>
                                                        {row.real_roas.toFixed(2)}×
                                                    </span>
                                                )
                                                : <span className="text-zinc-400" title="No UTM-matched orders">—</span>}
                                        </td>
                                        {/* Campaign-only: 1st ROAS + D30 ROAS */}
                                        {isCampaignLevel && c && (
                                            <>
                                                <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                                    {c.first_order_roas != null
                                                        ? `${c.first_order_roas.toFixed(2)}×`
                                                        : <span className="text-zinc-400">—</span>}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Day30Badge
                                                        roas={c.day30_roas}
                                                        pending={c.day30_pending}
                                                        locksInDays={c.day30_locks_in_days}
                                                    />
                                                </td>
                                            </>
                                        )}
                                        {/* CPA */}
                                        <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                            {row.cpa != null ? formatCurrency(row.cpa, currency) : <span className="text-zinc-400">—</span>}
                                        </td>
                                        {/* Campaign-only: Motion Score + Verdict */}
                                        {isCampaignLevel && c && (
                                            <>
                                                <td className="px-4 py-3">
                                                    <MotionScoreGauge score={c.motion_score} size="sm" showLabels={false} />
                                                </td>
                                                <td className="px-4 py-3">
                                                    {c.verdict
                                                        ? <VerdictPill verdict={c.verdict as any} />
                                                        : <span className="text-zinc-400">—</span>}
                                                </td>
                                            </>
                                        )}
                                        {/* Real CPO */}
                                        <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                            {row.real_cpo != null ? formatCurrency(row.real_cpo, currency) : <span className="text-zinc-400">—</span>}
                                        </td>
                                        {/* Attr. Revenue */}
                                        <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                            {row.attributed_revenue != null ? formatCurrency(row.attributed_revenue, currency) : <span className="text-zinc-400">—</span>}
                                        </td>
                                        {/* Attr. Orders */}
                                        <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                            {row.attributed_orders > 0 ? formatNumber(row.attributed_orders) : <span className="text-zinc-400">—</span>}
                                        </td>
                                        {/* Campaign-only: Velocity */}
                                        {isCampaignLevel && c && (
                                            <td className="px-4 py-3 text-right">
                                                <SpendVelocityBadge velocity={c.spend_velocity} />
                                            </td>
                                        )}
                                        {/* Impressions / Clicks / CTR / CPC */}
                                        <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                            {formatNumber(row.impressions)}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                            {formatNumber(row.clicks)}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                            {row.ctr != null ? `${row.ctr.toFixed(2)}%` : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-zinc-700">
                                            {row.cpc != null ? formatCurrency(row.cpc, currency) : '—'}
                                        </td>
                                        {/* Drill-through */}
                                        {!isAdLevel && (
                                            <td className="px-4 py-3 text-right">
                                                {drillHref ? (
                                                    <Link
                                                        href={drillHref}
                                                        className="text-xs text-zinc-400 hover:text-primary transition-colors"
                                                        title={isCampaignLevel ? 'View ad sets for this campaign' : 'View ads for this ad set'}
                                                    >
                                                        {isCampaignLevel ? 'Ad Sets →' : 'Ads →'}
                                                    </Link>
                                                ) : null}
                                            </td>
                                        )}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function CampaignsIndex(props: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';

    const {
        has_ad_accounts, ad_accounts, ad_account_ids,
        level, tab, campaign_id, adset_id,
        metrics, compare_metrics, rows, rows_total_count,
        creative_cards, pacing_data,
        campaign_name, adset_name,
        chart_data,
        total_revenue, unattributed_revenue, not_tracked_pct,
        workspace_target_roas, wl_has_target, active_classifier, wl_peer_avg_roas,
        filter, classifier,
        from, to, compare_from, compare_to,
        granularity, platform, status, view, sort, direction,
        hide_inactive, narrative, tag_categories, hit_rate_data,
    } = props;

    const [navigating, setNavigating]         = useState(() => _inertiaNavigating);
    const [roasType, setRoasType]             = useState<'real' | 'platform'>('real');
    const [localHideInactive, setLocalHideInactive] = useState(hide_inactive);
    const [selectedCard, setSelectedCard]     = useState<CreativeCardData | null>(null);

    useEffect(() => {
        const off1 = router.on('start',  () => setNavigating(true));
        const off2 = router.on('finish', () => setNavigating(false));
        return () => { off1(); off2(); };
    }, []);

    // Keep local hide-inactive in sync when props change (e.g. after navigation)
    useEffect(() => { setLocalHideInactive(hide_inactive); }, [hide_inactive]);

    const changes = useMemo(() => ({
        roas:    pctChange(metrics?.roas    ?? null, compare_metrics?.roas    ?? null),
        spend:   pctChange(metrics?.spend   ?? null, compare_metrics?.spend   ?? null),
        revenue: pctChange(metrics?.revenue ?? null, compare_metrics?.revenue ?? null),
    }), [metrics, compare_metrics]);

    // ── Navigation ───────────────────────────────────────────────────────────

    function navigate(patch: Record<string, string | number | null | undefined>) {
        const params: Record<string, string> = {};
        for (const [k, v] of Object.entries(patch)) {
            if (v != null) params[k] = String(v);
        }
        router.get(wurl(workspace?.slug, '/campaigns'), params, { preserveState: true, replace: true });
    }

    const baseParams = useMemo<Record<string, string | number | null | undefined>>(() => ({
        from, to,
        ...(compare_from ? { compare_from } : {}),
        ...(compare_to   ? { compare_to }   : {}),
        ...(ad_account_ids.length > 0 ? { ad_account_ids: ad_account_ids.join(',') } : {}),
        granularity, platform, status, level, tab, view, sort, direction,
        ...(filter !== 'all'    ? { filter }     : {}),
        ...(classifier !== null ? { classifier }  : {}),
        ...(campaign_id !== null ? { campaign_id } : {}),
        ...(adset_id    !== null ? { adset_id }    : {}),
    }), [from, to, compare_from, compare_to, ad_account_ids, granularity, platform, status, level, tab, view, sort, direction, filter, classifier, campaign_id, adset_id]);

    function setPlatform(v: 'all' | 'facebook' | 'google') {
        navigate({ ...baseParams, platform: v, ad_account_ids: undefined });
    }
    function setStatus(v: 'all' | 'active' | 'paused') {
        navigate({ ...baseParams, status: v });
    }
    function setView(v: 'table' | 'quadrant' | 'format_analysis') {
        navigate({ ...baseParams, view: v });
    }

    const [selectedCategory, setSelectedCategory] = useState<string>(
        tag_categories[0]?.name ?? 'visual_format',
    );
    function setSort(col: string) {
        const newDir = sort === col && direction === 'desc' ? 'asc' : 'desc';
        navigate({ ...baseParams, sort: col, direction: newDir });
    }
    function setTab(newTab: 'performance' | 'pacing') {
        navigate({ ...baseParams, tab: newTab });
    }
    function setLevel(newLevel: Level) {
        const patch: Record<string, string | number | null | undefined> = {
            ...baseParams,
            level: newLevel,
            // Going up always clears the lower-level filters
            campaign_id: newLevel === 'campaign' ? undefined : campaign_id,
            adset_id:    newLevel !== 'ad'       ? undefined : adset_id,
        };
        navigate(patch);
    }
    function toggleAdAccount(id: number) {
        const next = ad_account_ids.includes(id)
            ? ad_account_ids.filter((x) => x !== id)
            : [...ad_account_ids, id];
        navigate({
            ...baseParams,
            ad_account_ids: next.length > 0 ? next.join(',') : undefined,
        });
    }

    // ── Empty state ──────────────────────────────────────────────────────────
    if (!has_ad_accounts) {
        return (
            <AppLayout dateRangePicker={<DateRangePicker />}>
                <Head title="Campaigns" />
                <PageHeader title="Campaigns" subtitle="Cross-platform ad performance" narrative={narrative} />
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100">
                        <BarChart2 className="h-6 w-6 text-zinc-400" />
                    </div>
                    <h3 className="mb-1 text-base font-semibold text-zinc-900">No ad accounts connected</h3>
                    <p className="mb-5 max-w-xs text-sm text-zinc-500">
                        Connect a Facebook or Google Ads account to view campaign performance and ROAS.
                    </p>
                    <Link
                        href="/settings/integrations"
                        className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        Connect ad accounts →
                    </Link>
                </div>
            </AppLayout>
        );
    }

    // ── Quadrant data ────────────────────────────────────────────────────────
    const quadrantRows: QuadrantCampaign[] = useMemo(
        () => rows
            .map((r) => ({
                id:                 r.id,
                name:               r.name,
                platform:           r.platform,
                spend:              r.spend,
                real_roas:          roasType === 'real' ? r.real_roas : r.platform_roas,
                attributed_revenue: roasType === 'real' ? r.attributed_revenue : null,
                attributed_orders:  roasType === 'real' ? r.attributed_orders  : 0,
            }))
            .filter((r) => r.real_roas !== null),
        [rows, roasType],
    );

    const showCreativePanel = tab === 'performance' && view === 'table';

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title="Campaigns" />
            <PageHeader title="Campaigns" subtitle="Cross-platform ad performance" narrative={narrative} />

            {/* ── Performance / Pacing tabs ── */}
            <div className="mb-4 flex items-center justify-between border-b border-zinc-200">
                <div className="flex">
                    {(['performance', 'pacing'] as const).map((t) => (
                        <button
                            key={t}
                            onClick={() => setTab(t)}
                            className={cn(
                                'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors capitalize',
                                tab === t
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-zinc-500 hover:text-zinc-800 hover:border-zinc-300',
                            )}
                        >
                            {t === 'performance' ? 'Performance' : 'Pacing'}
                        </button>
                    ))}
                </div>
            </div>

            {/* ── Level toggle breadcrumb ── */}
            <div className="mb-4 flex items-center gap-1">
                {([
                    { key: 'campaign', label: 'Campaigns' },
                    { key: 'adset',    label: 'Ad Sets' },
                    { key: 'ad',       label: 'Ads' },
                ] as const).map((l, i) => (
                    <span key={l.key} className="flex items-center gap-1">
                        {i > 0 && <span className="text-zinc-300">/</span>}
                        <button
                            onClick={() => setLevel(l.key)}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                level === l.key
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-zinc-200 bg-white text-zinc-500 hover:border-zinc-300 hover:text-zinc-700',
                            )}
                        >
                            {l.label}
                        </button>
                    </span>
                ))}
                {/* Drill-through breadcrumb labels */}
                {campaign_name && (
                    <span className="ml-2 flex items-center gap-1 text-xs text-zinc-400">
                        <span>→</span>
                        <span className="max-w-[200px] truncate" title={campaign_name}>
                            {campaign_name}
                        </span>
                    </span>
                )}
                {adset_name && (
                    <span className="flex items-center gap-1 text-xs text-zinc-400">
                        <span>→</span>
                        <span className="max-w-[200px] truncate" title={adset_name}>
                            {adset_name}
                        </span>
                    </span>
                )}
            </div>

            {/* ── Ad account pills — grouped by platform ── */}
            {(['facebook', 'google'] as const).map((plat) => {
                const accounts = ad_accounts.filter((a) => a.platform === plat);
                if (accounts.length === 0) return null;
                return (
                    <div key={plat} className="mb-3 flex flex-wrap items-center gap-2">
                        <span className="text-xs font-medium text-zinc-400 shrink-0 capitalize">
                            {plat}
                        </span>
                        {accounts.length > 1 && (
                            <button
                                onClick={() => {
                                    const otherIds = ad_account_ids.filter((id) => !accounts.some((a) => a.id === id));
                                    navigate({
                                        ...baseParams,
                                        ad_account_ids: otherIds.length > 0 ? otherIds.join(',') : undefined,
                                    });
                                }}
                                className={cn(
                                    'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                    accounts.every((a) => !ad_account_ids.includes(a.id))
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                                )}
                            >
                                All
                            </button>
                        )}
                        {accounts.map((a) => (
                            <button
                                key={a.id}
                                onClick={() => toggleAdAccount(a.id)}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                    ad_account_ids.includes(a.id)
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                                )}
                                title={`${a.name} — ${syncDotTitle(a.status, a.last_synced_at)}`}
                            >
                                <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', syncDotClass(a.status, a.last_synced_at, 'ad_account'))} />
                                {a.name}
                            </button>
                        ))}
                    </div>
                );
            })}

            {/* ── Filter bar ── */}
            <div className="mb-6 flex flex-wrap items-center gap-3">
                <ToggleGroup
                    options={[
                        { label: 'All',      value: 'all' },
                        { label: 'Facebook', value: 'facebook' },
                        { label: 'Google',   value: 'google' },
                    ]}
                    value={platform}
                    onChange={setPlatform}
                />
                <ToggleGroup
                    options={[
                        { label: 'All status', value: 'all' },
                        { label: 'Active',     value: 'active' },
                        { label: 'Paused',     value: 'paused' },
                    ]}
                    value={status}
                    onChange={setStatus}
                />

                {/* Table / quadrant toggle — only for performance tab */}
                {tab === 'performance' && (
                    <div className="flex items-center gap-1 rounded-lg border border-zinc-200 bg-zinc-50 p-0.5">
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
                            onClick={() => setView('quadrant')}
                            title="Quadrant view"
                            className={cn(
                                'rounded-md p-1.5 transition-colors',
                                view === 'quadrant' ? 'bg-white shadow-sm text-zinc-800' : 'text-zinc-400 hover:text-zinc-600',
                            )}
                        >
                            <Grid2X2 className="h-4 w-4" />
                        </button>
                        <button
                            onClick={() => setView('format_analysis')}
                            title="Format analysis — Hit Rate × Spend Share by creative tag"
                            className={cn(
                                'rounded-md p-1.5 transition-colors',
                                view === 'format_analysis' ? 'bg-white shadow-sm text-zinc-800' : 'text-zinc-400 hover:text-zinc-600',
                            )}
                        >
                            <Tags className="h-4 w-4" />
                        </button>
                    </div>
                )}

                {/* Attribution window placeholder — Phase 4.1, full impl deferred */}
                <div
                    className="flex items-center gap-1.5 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs text-zinc-400 cursor-not-allowed select-none"
                    title="Attribution window filtering coming in a future update"
                >
                    Window: All Time
                </div>

                {/* Accrual / Cash toggle placeholder — deferred until attribution_last_touch coverage verified */}
                <div
                    className="flex items-center gap-1 cursor-not-allowed select-none"
                    title="Accrual mode coming once attribution coverage is verified."
                >
                    <span className="text-[10px] uppercase tracking-wide text-zinc-400 mr-0.5">Mode</span>
                    {(['Cash', 'Accrual'] as const).map((mode) => (
                        <div
                            key={mode}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs font-medium',
                                mode === 'Cash'
                                    ? 'border-zinc-300 bg-zinc-100 text-zinc-500'
                                    : 'border-zinc-200 bg-white text-zinc-300',
                            )}
                        >
                            {mode}
                        </div>
                    ))}
                </div>

                {/* W/L filter bar */}
                <WlFilterBar
                    filter={filter}
                    totalCount={rows_total_count}
                    filteredCount={rows.length}
                    activeClassifier={active_classifier}
                    hasTarget={wl_has_target}
                    targetRoas={workspace_target_roas}
                    peerAvgRoas={wl_peer_avg_roas}
                    allLabel="Show all campaigns"
                    onFilterChange={(f) => navigate({ ...baseParams, ...(f !== 'all' ? { filter: f } : { filter: undefined }) })}
                    onClassifierChange={(c) => navigate({ ...baseParams, classifier: c })}
                />
            </div>

            {/* ── Hero cards ── */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <MetricCard
                    label="Total Ad Spend"
                    source="real"
                    value={metrics?.spend != null ? formatCurrency(metrics.spend, currency) : null}
                    change={changes.spend}
                    invertTrend
                    loading={navigating}
                    tooltip="Total ad spend reported by the platform for the selected period, converted to your reporting currency."
                />
                <MetricCard
                    label="Real ROAS"
                    source="real"
                    value={metrics?.real_roas != null ? `${metrics.real_roas.toFixed(2)}×` : null}
                    target={workspace_target_roas}
                    targetDirection="above"
                    change={pctChange(metrics?.real_roas ?? null, compare_metrics?.real_roas ?? null)}
                    loading={navigating}
                    tooltip="UTM-attributed revenue divided by ad spend. Calculated from your actual store orders, not platform pixel attribution."
                />
                <MetricCard
                    label="Real CPO"
                    source="real"
                    value={metrics?.real_cpo != null ? formatCurrency(metrics.real_cpo, currency) : null}
                    invertTrend
                    change={pctChange(metrics?.real_cpo ?? null, compare_metrics?.real_cpo ?? null)}
                    loading={navigating}
                    tooltip="Ad spend divided by the number of UTM-attributed orders. Lower is better."
                />
                <MetricCard
                    label="Not Tracked"
                    source="real"
                    value={not_tracked_pct != null ? `${not_tracked_pct.toFixed(1)}%` : null}
                    loading={navigating}
                    subtext={unattributed_revenue != null ? formatCurrency(unattributed_revenue, currency) : undefined}
                    tooltip="Revenue not tracked by any ad platform. Includes organic search, direct, email, affiliates, and untagged traffic. When negative, platforms over-reported — usually due to iOS14+ modeled conversions."
                />
            </div>

            {/* ── Performance tab ── */}
            {tab === 'performance' && (
                <>
                    {/* Table view — split 60/40 layout */}
                    {view === 'table' && (
                        <div className="flex gap-4 items-start">
                            {/* Left 60%: chart + hierarchy table */}
                            <div className="min-w-0 flex-[3]">
                                <SpendChart
                                    chartData={chart_data}
                                    granularity={granularity}
                                    currency={currency}
                                    navigating={navigating}
                                />
                                <HierarchyTable
                                    rows={rows}
                                    level={level}
                                    currency={currency}
                                    sort={sort}
                                    direction={direction}
                                    onSort={setSort}
                                    from={from}
                                    to={to}
                                    workspaceSlug={workspace?.slug}
                                    targetRoas={workspace_target_roas}
                                />
                            </div>
                            {/* Right 40%: creative grid */}
                            <div className="min-w-0 flex-[2] min-h-[600px]">
                                <div className="sticky top-4 rounded-xl border border-zinc-200 bg-white p-4">
                                    <h3 className="mb-3 text-sm font-medium text-zinc-600">Creative Performance</h3>
                                    {navigating ? (
                                        <div className="h-96 animate-pulse rounded-lg bg-zinc-100" />
                                    ) : (
                                        <CreativeGrid
                                            cards={creative_cards}
                                            currency={currency}
                                            hideInactive={localHideInactive}
                                            onHideInactiveChange={setLocalHideInactive}
                                            onCardClick={setSelectedCard}
                                        />
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Quadrant view — full width (creative panel hidden for chart legibility) */}
                    {view === 'quadrant' && (
                        <div className="rounded-xl border border-zinc-200 bg-white p-5">
                            <div className="mb-3 flex items-center justify-between">
                                <div>
                                    <div className="text-sm font-medium text-zinc-500">Performance quadrant</div>
                                    <p className="mt-0.5 text-xs text-zinc-400">
                                        Each bubble is one row. X = ad spend (log), Y = ROAS, bubble size = attributed revenue.
                                    </p>
                                </div>
                                <div className="inline-flex rounded-lg border border-zinc-200 bg-zinc-50 p-0.5 shrink-0">
                                    <button
                                        onClick={() => setRoasType('real')}
                                        className={cn(
                                            'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                                            roasType === 'real' ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-400 hover:text-zinc-600',
                                        )}
                                    >
                                        Real ROAS
                                    </button>
                                    <button
                                        onClick={() => setRoasType('platform')}
                                        className={cn(
                                            'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                                            roasType === 'platform' ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-400 hover:text-zinc-600',
                                        )}
                                    >
                                        Platform ROAS
                                    </button>
                                </div>
                            </div>
                            {navigating ? (
                                <div className="h-[460px] w-full animate-pulse rounded-lg bg-zinc-100" />
                            ) : (
                                <QuadrantChart
                                    campaigns={quadrantRows}
                                    currency={currency}
                                    targetRoas={workspace_target_roas ?? 1.5}
                                    yLabel={roasType === 'real' ? 'Real ROAS' : 'Platform ROAS'}
                                    hiddenCount={rows.length - quadrantRows.length}
                                    hiddenLabel={level === 'campaign' ? 'campaigns' : level === 'adset' ? 'ad sets' : 'ads'}
                                />
                            )}
                        </div>
                    )}

                    {/* Format Analysis view — Hit Rate × Spend Share per creative tag */}
                    {view === 'format_analysis' && (
                        <div className="rounded-xl border border-zinc-200 bg-white p-5">
                            <div className="mb-4 flex items-center justify-between gap-3 flex-wrap">
                                <div>
                                    <div className="text-sm font-medium text-zinc-700">Format analysis</div>
                                    <p className="mt-0.5 text-xs text-zinc-400">
                                        Each bubble = one creative tag. X = % of ads with this tag that are Scale or Iterate. Y = share of total spend.
                                    </p>
                                </div>
                                {tag_categories.length > 0 && (
                                    <select
                                        value={selectedCategory}
                                        onChange={(e) => setSelectedCategory(e.target.value)}
                                        className="rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-xs text-zinc-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-zinc-400"
                                    >
                                        {tag_categories.map((cat) => (
                                            <option key={cat.name} value={cat.name}>{cat.label}</option>
                                        ))}
                                    </select>
                                )}
                            </div>
                            {navigating ? (
                                <div className="h-[460px] w-full animate-pulse rounded-lg bg-zinc-100" />
                            ) : (hit_rate_data[selectedCategory] ?? []).length === 0 ? (
                                <div className="flex h-[460px] flex-col items-center justify-center gap-2 text-center text-zinc-400">
                                    <Tags className="h-8 w-8 opacity-30" />
                                    <p className="text-sm font-medium">No tag data yet</p>
                                    <p className="max-w-xs text-xs">
                                        The creative tagging job runs nightly. Ads will appear here after the first classification run.
                                    </p>
                                </div>
                            ) : (
                                <QuadrantChart
                                    data={hit_rate_data[selectedCategory] ?? []}
                                    config={{
                                        xLabel:          'Hit Rate',
                                        yLabel:          'Spend Share',
                                        xFormatter:      (v) => `${Math.round(v * 100)}%`,
                                        yFormatter:      (v) => v === null ? '—' : `${Math.round(v * 100)}%`,
                                        sizeLabel:       'Spend',
                                        sizeFormatter:   (v) => v === null ? '—' : formatCurrency(v, currency),
                                        xThreshold:      0.3,
                                        colorMode:       'quadrant',
                                        topRightLabel:    'Invest more',
                                        topLeftLabel:     'Hidden winners',
                                        bottomRightLabel: 'Expensive losers',
                                        bottomLeftLabel:  'Skip',
                                    }}
                                />
                            )}
                        </div>
                    )}
                </>
            )}

            {/* ── Pacing tab — full width ── */}
            {tab === 'pacing' && (
                <div className="rounded-xl border border-zinc-200 bg-white p-5">
                    <h3 className="mb-4 text-sm font-medium text-zinc-600">Budget pacing</h3>
                    {navigating ? (
                        <div className="h-64 animate-pulse rounded-lg bg-zinc-100" />
                    ) : (
                        <PacingChart
                            campaigns={pacing_data}
                            currency={currency}
                            from={from}
                            to={to}
                        />
                    )}
                </div>
            )}
            <AdDetailModal
                card={selectedCard}
                currency={currency}
                onClose={() => setSelectedCard(null)}
            />
        </AppLayout>
    );
}
