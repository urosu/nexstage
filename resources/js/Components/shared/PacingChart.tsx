import { useMemo } from 'react';
import {
    ComposedChart,
    Bar,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ReferenceLine,
    ResponsiveContainer,
    Legend,
} from 'recharts';
import { cn } from '@/lib/utils';

export interface PacingCampaign {
    campaign_id:        number;
    campaign_name:      string;
    daily_budget:       number | null;
    lifetime_budget:    number | null;
    budget_type:        string | null;
    budget_for_period:  number | null;
    total_spend:        number;
    velocity:           number | null;
    pacing_status:      'on_pace' | 'over' | 'under' | 'no_budget';
    daily_points:       Array<{ date: string; spend: number }>;
}

interface Props {
    campaigns:   PacingCampaign[];
    currency:    string;
    from:        string;
    to:          string;
    className?:  string;
}

const STATUS_COLOR: Record<PacingCampaign['pacing_status'], string> = {
    on_pace:  'text-emerald-700 bg-emerald-50 border-emerald-200',
    over:     'text-red-700     bg-red-50     border-red-200',
    under:    'text-yellow-700  bg-yellow-50  border-yellow-200',
    no_budget:'text-zinc-500   bg-zinc-50    border-zinc-200',
};

const STATUS_LABEL: Record<PacingCampaign['pacing_status'], string> = {
    on_pace:  'On pace',
    over:     'Over-pacing',
    under:    'Under-pacing',
    no_budget:'No budget set',
};

function fmt(currency: string, v: number | null): string {
    if (v == null) return '—';
    return new Intl.NumberFormat(undefined, {
        style: 'currency', currency,
        minimumFractionDigits: 0, maximumFractionDigits: 0,
    }).format(v);
}

/**
 * Pacing tab chart + at-risk list.
 *
 * Left: cumulative spend bar chart vs expected-pace line for the selected campaign.
 * Right: campaign list with velocity badge. Click row selects the campaign for the chart.
 */
export function PacingChart({ campaigns, currency, from, to, className }: Props) {
    const campaignsWithBudget = campaigns.filter((c) => c.pacing_status !== 'no_budget');
    const atRisk = campaigns.filter((c) => c.pacing_status === 'over' || c.pacing_status === 'under');

    if (campaigns.length === 0) {
        return (
            <div className={cn('flex items-center justify-center py-16 text-sm text-zinc-400', className)}>
                No campaign budget data for this period.
            </div>
        );
    }

    return (
        <div className={cn('space-y-6', className)}>
            {/* At-risk list — budget alerts per §F10 */}
            {atRisk.length > 0 && (
                <section>
                    <h3 className="mb-3 text-sm font-semibold text-zinc-700">Budget at risk</h3>
                    <div className="divide-y divide-zinc-100 rounded-xl border border-zinc-200">
                        {atRisk.map((c) => (
                            <AtRiskRow key={c.campaign_id} campaign={c} currency={currency} />
                        ))}
                    </div>
                </section>
            )}

            {/* Daily burn charts — one per campaign with budget */}
            {campaignsWithBudget.slice(0, 5).map((c) => (
                <CampaignBurnChart key={c.campaign_id} campaign={c} currency={currency} from={from} to={to} />
            ))}

            {campaignsWithBudget.length === 0 && (
                <p className="text-sm text-zinc-400">
                    No campaigns have budgets configured. Set a daily or lifetime budget in Meta / Google Ads.
                </p>
            )}
        </div>
    );
}

function AtRiskRow({ campaign: c, currency }: { campaign: PacingCampaign; currency: string }) {
    const velPct = c.velocity != null ? `${(c.velocity * 100).toFixed(0)}%` : '—';
    return (
        <div className="flex items-center justify-between px-4 py-3">
            <div>
                <p className="text-sm font-medium text-zinc-800 truncate max-w-xs">{c.campaign_name}</p>
                <p className="text-xs text-zinc-500 mt-0.5">
                    Spend {fmt(currency, c.total_spend)} · Budget {fmt(currency, c.budget_for_period)}
                </p>
            </div>
            <div className="flex items-center gap-3">
                <span className="text-sm font-semibold text-zinc-800">{velPct} of pace</span>
                <span className={cn(
                    'rounded border px-2 py-0.5 text-xs font-medium',
                    STATUS_COLOR[c.pacing_status],
                )}>
                    {STATUS_LABEL[c.pacing_status]}
                </span>
            </div>
        </div>
    );
}

function CampaignBurnChart({
    campaign: c,
    currency,
    from,
    to,
}: { campaign: PacingCampaign; currency: string; from: string; to: string }) {
    // Build cumulative daily spend + expected-pace line
    const chartData = useMemo(() => {
        const days = c.daily_points;
        if (days.length === 0) return [];

        const totalDays = Math.max(1, daysBetween(from, to) + 1);
        const budget    = c.budget_for_period ?? 0;

        let cumulative = 0;
        return days.map((point, i) => {
            cumulative += point.spend;
            const dayIndex  = i + 1;
            const expected  = budget > 0 ? (dayIndex / totalDays) * budget : null;
            return {
                date:        formatDateShort(point.date),
                spend:       round2(cumulative),
                expected:    expected !== null ? round2(expected) : null,
            };
        });
    }, [c, from, to]);

    return (
        <section>
            <div className="mb-2 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-zinc-700 truncate max-w-xs">{c.campaign_name}</h3>
                <span className={cn(
                    'rounded border px-2 py-0.5 text-xs font-medium',
                    STATUS_COLOR[c.pacing_status],
                )}>
                    {STATUS_LABEL[c.pacing_status]}
                </span>
            </div>
            <ResponsiveContainer width="100%" height={180}>
                <ComposedChart data={chartData} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                    <XAxis
                        dataKey="date"
                        tick={{ fontSize: 10, fill: '#94a3b8' }}
                        tickLine={false}
                        axisLine={false}
                        interval="preserveStartEnd"
                    />
                    <YAxis
                        tick={{ fontSize: 10, fill: '#94a3b8' }}
                        tickLine={false}
                        axisLine={false}
                        tickFormatter={(v) => fmt(currency, v)}
                        width={60}
                    />
                    <Tooltip
                        formatter={(value, name) => [fmt(currency, Number(value ?? 0)), String(name ?? '')]}
                        labelStyle={{ fontSize: 11 }}
                        contentStyle={{ fontSize: 11, borderRadius: 8 }}
                    />
                    {c.budget_for_period && (
                        <ReferenceLine
                            y={c.budget_for_period}
                            stroke="#94a3b8"
                            strokeDasharray="4 4"
                            label={{ value: 'Budget', fontSize: 10, fill: '#94a3b8', position: 'insideTopRight' }}
                        />
                    )}
                    <Bar dataKey="spend" name="Actual spend" fill="#6366f1" fillOpacity={0.7} radius={[2, 2, 0, 0]} />
                    {chartData.some((d) => d.expected != null) && (
                        <Line
                            dataKey="expected"
                            name="Expected pace"
                            stroke="#94a3b8"
                            strokeDasharray="4 4"
                            dot={false}
                            strokeWidth={1.5}
                        />
                    )}
                </ComposedChart>
            </ResponsiveContainer>
        </section>
    );
}

function daysBetween(from: string, to: string): number {
    return Math.floor((new Date(to).getTime() - new Date(from).getTime()) / 86_400_000);
}

function formatDateShort(dateStr: string): string {
    const d = new Date(dateStr);
    return `${d.getMonth() + 1}/${d.getDate()}`;
}

function round2(v: number): number {
    return Math.round(v * 100) / 100;
}
