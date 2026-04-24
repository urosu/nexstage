import { useEffect, useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level lets us start with
// navigating=true so the skeleton stays visible until the real data is ready.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import { AlertTriangle, Info } from 'lucide-react';
import {
    AreaChart, Area, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { MetricCard } from '@/Components/shared/MetricCard';
import { formatCurrency, formatNumber, formatDate } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────────

interface CampaignRow {
    campaign_id: number;
    campaign_name: string;
    platform: string;
    spend: number;
    platform_revenue: number;
    platform_conversions: number | null;
    platform_roas: number | null;
    attributed_revenue: number;
    real_roas: number | null;
    delta: number;
    delta_pct: number | null;
}

interface ChartPoint {
    date: string;
    platform_revenue: number;
    attributed_revenue: number;
    gap: number;
}

interface HeroMetrics {
    total_spend: number;
    total_platform_revenue: number;
    total_attributed_revenue: number;
    total_delta: number;
    total_delta_pct: number | null;
    platform_roas: number | null;
    real_roas: number | null;
}

interface Props {
    campaigns: CampaignRow[];
    chart_data: ChartPoint[];
    hero: HeroMetrics;
    from: string;
    to: string;
    platform: string;
    narrative: string | null;
}

// ── Platform badge ──────────────────────────────────────────────────────────

const PLATFORM_COLORS: Record<string, string> = {
    facebook: 'bg-blue-50 text-blue-700 border-blue-200',
    google: 'bg-violet-50 text-violet-700 border-violet-200',
};

function PlatformBadge({ platform }: { platform: string }) {
    return (
        <span className={cn(
            'inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium',
            PLATFORM_COLORS[platform] ?? 'bg-zinc-50 text-zinc-600 border-zinc-200',
        )}>
            {platform === 'facebook' ? 'Meta' : platform === 'google' ? 'Google' : platform}
        </span>
    );
}

// ── Main page ───────────────────────────────────────────────────────────────

export default function Discrepancy(props: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const [navigating, setNavigating] = useState(() => _inertiaNavigating);

    const { campaigns, chart_data, hero, from, to, platform, narrative } = props;

    useEffect(() => {
        const off1 = router.on('start', () => setNavigating(true));
        const off2 = router.on('finish', () => setNavigating(false));
        return () => { off1(); off2(); };
    }, []);

    function navigate(params: Record<string, string | undefined>) {
        router.get(
            wurl(workspace?.slug, '/analytics/discrepancy'),
            params as Record<string, string>,
            { preserveState: true, replace: true },
        );
    }

    function setPlatform(p: string) {
        navigate({ from, to, ...(p !== 'all' ? { platform: p } : {}) });
    }

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title="Discrepancy — Platform vs Real" />
            <PageHeader title="Discrepancy" subtitle="Where do your ad platforms disagree with your store data?" narrative={narrative} />

            {/* ── iOS14 explanation banner ────────────────────────────────────── */}
            <div className="mb-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3">
                <Info className="mt-0.5 h-4 w-4 shrink-0 text-blue-600" />
                <div>
                    <p className="text-sm text-blue-800">
                        Ad platforms use modeled conversions (especially post-iOS 14.5) which often
                        over-report revenue. The "Real" column shows store-verified attributed revenue
                        matched via UTM parameters. The delta reveals the gap.
                    </p>
                </div>
            </div>

            {/* ── Hero cards ─────────────────────────────────────────────────── */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <MetricCard
                    label="Platform ROAS"
                    source="facebook"
                    value={hero.platform_roas != null ? `${hero.platform_roas}x` : null}
                    loading={navigating}
                    tooltip="ROAS as reported by ad platforms (includes modeled conversions)."
                />
                <MetricCard
                    label="Real ROAS"
                    source="real"
                    value={hero.real_roas != null ? `${hero.real_roas}x` : null}
                    loading={navigating}
                    tooltip="ROAS computed from store-verified orders matched via UTM attribution."
                />
                <MetricCard
                    label="Revenue Gap"
                    value={formatCurrency(Math.abs(hero.total_delta), currency)}
                    subtext={
                        hero.total_delta_pct != null
                            ? `${hero.total_delta > 0 ? '+' : ''}${hero.total_delta_pct}% vs Real`
                            : undefined
                    }
                    loading={navigating}
                    tooltip="Difference between platform-reported and store-attributed revenue. Positive = platform over-reports."
                />
                <MetricCard
                    label="Total Ad Spend"
                    value={formatCurrency(hero.total_spend, currency)}
                    loading={navigating}
                    tooltip="Total spend across all campaigns in the period."
                />
            </div>

            {/* ── Platform filter ─────────────────────────────────────────────── */}
            <div className="mb-4 flex items-center gap-2">
                {(['all', 'facebook', 'google'] as const).map(p => (
                    <button
                        key={p}
                        onClick={() => setPlatform(p)}
                        className={cn(
                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            platform === p
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 hover:text-zinc-700',
                        )}
                    >
                        {p === 'all' ? 'All Platforms' : p === 'facebook' ? 'Meta Ads' : 'Google Ads'}
                    </button>
                ))}
            </div>

            {/* ── Gap over time chart ────────────────────────────────────────── */}
            {chart_data.length > 0 && (
                <div className="mb-6 rounded-xl border border-zinc-200 bg-white p-5">
                    <div className="mb-3">
                        <div className="text-sm font-medium text-zinc-500">Revenue gap over time</div>
                        <p className="mt-0.5 text-xs text-zinc-400">
                            Platform-reported revenue vs store-attributed revenue per day.
                        </p>
                    </div>
                    <ResponsiveContainer width="100%" height={280}>
                        <AreaChart data={chart_data} margin={{ top: 5, right: 20, bottom: 5, left: 0 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" vertical={false} />
                            <XAxis
                                dataKey="date"
                                tick={{ fontSize: 11, fill: '#a1a1aa' }}
                                tickFormatter={(v) => formatDate(v, 'daily')}
                                tickLine={false}
                                axisLine={false}
                            />
                            <YAxis
                                tick={{ fontSize: 11, fill: '#a1a1aa' }}
                                tickFormatter={(v) => formatCurrency(v, currency, true)}
                                domain={[0, 'auto']}
                                tickLine={false}
                                axisLine={false}
                            />
                            <Tooltip
                                contentStyle={{
                                    backgroundColor: '#fff',
                                    border: '1px solid #e4e4e7',
                                    borderRadius: '8px',
                                    fontSize: '12px',
                                }}
                                formatter={((value: any, name: string) => [
                                    formatCurrency(Number(value), currency),
                                    name === 'platform_revenue' ? 'Platform Reported' : 'Store Attributed',
                                ]) as any}
                                labelFormatter={(label) => formatDate(label as string, 'daily')}
                            />
                            <Legend
                                iconType="circle"
                                iconSize={8}
                                wrapperStyle={{ fontSize: '12px', paddingTop: '8px' }}
                                formatter={(value) =>
                                    value === 'platform_revenue' ? 'Platform Reported' : 'Store Attributed'
                                }
                            />
                            <Area
                                type="monotone"
                                dataKey="platform_revenue"
                                name="platform_revenue"
                                stroke="#3b82f6"
                                strokeWidth={2}
                                fill="#3b82f6"
                                fillOpacity={0.06}
                                dot={false}
                            />
                            <Area
                                type="monotone"
                                dataKey="attributed_revenue"
                                name="attributed_revenue"
                                stroke="#16a34a"
                                strokeWidth={2}
                                fill="#16a34a"
                                fillOpacity={0.06}
                                dot={false}
                            />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
            )}

            {/* ── Campaign table ──────────────────────────────────────────────── */}
            <div className="rounded-xl border border-zinc-200 bg-white">
                <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4">
                    <div className="text-sm font-medium text-zinc-500">
                        Campaign Discrepancies
                        <span className="ml-2 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500">
                            {campaigns.length}
                        </span>
                    </div>
                </div>

                {!navigating && campaigns.length === 0 ? (
                    <div className="flex flex-col items-center justify-center px-6 py-20 text-center">
                        <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100">
                            <AlertTriangle className="h-6 w-6 text-zinc-400" />
                        </div>
                        <h3 className="mb-1 text-base font-semibold text-zinc-900">No ad campaign data</h3>
                        <p className="max-w-xs text-sm text-zinc-500">
                            {platform !== 'all'
                                ? 'No campaigns found for this platform in the selected date range.'
                                : 'Connect an ad account and wait for the first sync to see platform vs store discrepancies.'}
                        </p>
                    </div>
                ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left th-label">
                                <th className="px-5 py-3">Campaign</th>
                                <th className="px-3 py-3 text-right">Spend</th>
                                <th className="px-3 py-3 text-right">Platform Revenue</th>
                                <th className="px-3 py-3 text-right">Real Revenue</th>
                                <th className="px-3 py-3 text-right">Delta</th>
                                <th className="px-3 py-3 text-right">Delta %</th>
                                <th className="px-3 py-3 text-right">Platform ROAS</th>
                                <th className="px-3 py-3 text-right">Real ROAS</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {campaigns.map(c => (
                                <tr key={c.campaign_id} className="hover:bg-zinc-50">
                                    <td className="max-w-[280px] px-5 py-3">
                                        <div className="flex items-center gap-2">
                                            <PlatformBadge platform={c.platform} />
                                            <span className="truncate font-medium text-zinc-800" title={c.campaign_name}>
                                                {c.campaign_name}
                                            </span>
                                        </div>
                                    </td>
                                    <td className="px-3 py-3 text-right tabular-nums text-zinc-700">
                                        {formatCurrency(c.spend, currency)}
                                    </td>
                                    <td className="px-3 py-3 text-right tabular-nums text-blue-600">
                                        {formatCurrency(c.platform_revenue, currency)}
                                    </td>
                                    <td className="px-3 py-3 text-right tabular-nums text-green-700 font-medium">
                                        {formatCurrency(c.attributed_revenue, currency)}
                                    </td>
                                    <td className="px-3 py-3 text-right tabular-nums">
                                        <span className={c.delta > 0 ? 'text-red-600' : c.delta < 0 ? 'text-green-700' : 'text-zinc-500'}>
                                            {c.delta > 0 ? '+' : ''}{formatCurrency(c.delta, currency)}
                                        </span>
                                    </td>
                                    <td className="px-3 py-3 text-right tabular-nums">
                                        {c.delta_pct != null ? (
                                            <span className={c.delta_pct > 0 ? 'text-red-600' : c.delta_pct < 0 ? 'text-green-700' : 'text-zinc-500'}>
                                                {c.delta_pct > 0 ? '+' : ''}{c.delta_pct}%
                                            </span>
                                        ) : (
                                            <span className="text-zinc-300">—</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-3 text-right tabular-nums text-blue-600">
                                        {c.platform_roas != null ? `${c.platform_roas}x` : '—'}
                                    </td>
                                    <td className="px-3 py-3 text-right tabular-nums text-green-700 font-medium">
                                        {c.real_roas != null ? `${c.real_roas}x` : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                )}
            </div>
        </AppLayout>
    );
}
