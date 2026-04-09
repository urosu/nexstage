import React, { useMemo } from 'react';
import {
    ScatterChart,
    Scatter,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    ReferenceLine,
    ReferenceArea,
    Cell,
} from 'recharts';
import { formatCurrency, formatNumber } from '@/lib/formatters';

export interface QuadrantCampaign {
    id: number;
    name: string;
    platform: string;
    spend: number;
    real_roas: number | null;
    attributed_revenue: number | null;
    attributed_orders: number;
}

interface Props {
    campaigns: QuadrantCampaign[];
    currency: string;
    targetRoas?: number;
}

const PLATFORM_COLORS: Record<string, string> = {
    facebook: '#1877f2',
    google:   '#ea4335',
};

type Quadrant = 'scale' | 'hidden_gem' | 'cut' | 'ignore' | 'no_attribution';

function getQuadrant(
    spend: number,
    roas: number | null,
    medianSpend: number,
    targetRoas: number,
): Quadrant {
    if (roas === null) return 'no_attribution';
    const highSpend = spend >= medianSpend;
    const highRoas  = roas >= targetRoas;
    if (highRoas  && highSpend) return 'scale';
    if (highRoas  && !highSpend) return 'hidden_gem';
    if (!highRoas && highSpend) return 'cut';
    return 'ignore';
}

const QUADRANT_COLORS: Record<Quadrant, string> = {
    scale:          '#16a34a', // green-600
    hidden_gem:     '#0d9488', // teal-600
    cut:            '#dc2626', // red-600
    ignore:         '#71717a', // zinc-500
    no_attribution: '#a1a1aa', // zinc-400
};

const QUADRANT_LABELS: Record<Quadrant, string> = {
    scale:          'Scale',
    hidden_gem:     'Hidden Gem',
    cut:            'Cut / Fix',
    ignore:         'Ignore',
    no_attribution: 'No attribution',
};

// Normalise bubble size between min and max area
function scaleBubble(value: number | null, min: number, max: number): number {
    if (value === null || max === min) return 200;
    const ratio = (value - min) / (max - min);
    return Math.max(40, Math.round(40 + ratio * 500));
}

interface TooltipPayload {
    payload: {
        name: string;
        platform: string;
        spend: number;
        real_roas: number | null;
        attributed_revenue: number | null;
        attributed_orders: number;
        _quadrant: Quadrant;
        r: number;
    };
}

function CustomTooltip({ active, payload }: { active?: boolean; payload?: TooltipPayload[] }) {
    if (!active || !payload?.length) return null;
    const d = payload[0].payload;

    return (
        <div className="rounded-xl border border-zinc-200 bg-white px-4 py-3 shadow-lg text-sm min-w-[220px]">
            <div className="mb-2 flex items-center gap-2">
                <span
                    className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium text-white capitalize"
                    style={{ backgroundColor: PLATFORM_COLORS[d.platform] ?? '#71717a' }}
                >
                    {d.platform}
                </span>
                <span className="font-semibold text-zinc-900 truncate max-w-[160px]" title={d.name}>
                    {d.name}
                </span>
            </div>
            <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                <span className="text-zinc-400">Quadrant</span>
                <span className="font-medium text-zinc-700">{QUADRANT_LABELS[d._quadrant]}</span>

                <span className="text-zinc-400">Spend</span>
                <span className="font-medium tabular-nums text-zinc-700">
                    {formatCurrency(d.spend, 'EUR')}
                </span>

                <span className="text-zinc-400">Real ROAS</span>
                <span className={[
                    'font-medium tabular-nums',
                    d.real_roas != null
                        ? d.real_roas >= 1.5 ? 'text-green-700' : 'text-red-600'
                        : 'text-zinc-400',
                ].join(' ')}>
                    {d.real_roas != null ? `${d.real_roas.toFixed(2)}×` : '—'}
                </span>

                <span className="text-zinc-400">Attr. Revenue</span>
                <span className="font-medium tabular-nums text-zinc-700">
                    {d.attributed_revenue != null ? formatCurrency(d.attributed_revenue, 'EUR') : '—'}
                </span>

                <span className="text-zinc-400">Attr. Orders</span>
                <span className="font-medium tabular-nums text-zinc-700">
                    {d.attributed_orders > 0 ? formatNumber(d.attributed_orders) : '—'}
                </span>
            </div>
        </div>
    );
}

const QuadrantChart = React.memo(function QuadrantChart({
    campaigns,
    currency,
    targetRoas = 1.5,
}: Props) {
    const { points, medianSpend, maxSpend, maxRoas } = useMemo(() => {
        const withSpend = campaigns.filter((c) => c.spend > 0);
        if (withSpend.length === 0) return { points: [], medianSpend: 0, maxSpend: 0, maxRoas: 0 };

        const sorted = [...withSpend].sort((a, b) => a.spend - b.spend);
        const mid    = Math.floor(sorted.length / 2);
        const median = sorted.length % 2 === 0
            ? (sorted[mid - 1].spend + sorted[mid].spend) / 2
            : sorted[mid].spend;

        const revenues = withSpend.map((c) => c.attributed_revenue ?? 0);
        const minRev   = Math.min(...revenues);
        const maxRev   = Math.max(...revenues);
        const maxS     = Math.max(...withSpend.map((c) => c.spend));
        const maxR     = Math.max(...withSpend.map((c) => c.real_roas ?? 0));

        const pts = withSpend.map((c) => ({
            ...c,
            // Campaigns without UTM attribution plot at y=0
            y:         c.real_roas ?? 0,
            r:         scaleBubble(c.attributed_revenue, minRev, maxRev),
            _quadrant: getQuadrant(c.spend, c.real_roas, median, targetRoas),
        }));

        return { points: pts, medianSpend: median, maxSpend: maxS, maxRoas: maxR };
    }, [campaigns, targetRoas]);

    if (points.length === 0) {
        return (
            <div className="flex h-[460px] flex-col items-center justify-center gap-2 text-center">
                <p className="text-sm text-zinc-400">No campaign spend data for this period.</p>
            </div>
        );
    }

    const yMax = Math.max(targetRoas * 2, maxRoas * 1.1, 3);
    const xMax = maxSpend * 1.1;

    return (
        <div>
            <ResponsiveContainer width="100%" height={460}>
                <ScatterChart margin={{ top: 16, right: 24, bottom: 32, left: 8 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" />

                    {/* Quadrant backgrounds */}
                    <ReferenceArea x1={medianSpend} x2={xMax} y1={targetRoas} y2={yMax}
                        fill="#f0fdf4" fillOpacity={0.6} />
                    <ReferenceArea x1={0} x2={medianSpend} y1={targetRoas} y2={yMax}
                        fill="#f0fdfa" fillOpacity={0.6} />
                    <ReferenceArea x1={medianSpend} x2={xMax} y1={0} y2={targetRoas}
                        fill="#fef2f2" fillOpacity={0.6} />
                    <ReferenceArea x1={0} x2={medianSpend} y1={0} y2={targetRoas}
                        fill="#fafafa" fillOpacity={0.6} />

                    {/* Quadrant dividers */}
                    <ReferenceLine x={medianSpend} stroke="#d4d4d8" strokeDasharray="4 4"
                        label={{ value: 'Median spend', position: 'insideTopRight', fontSize: 10, fill: '#a1a1aa' }} />
                    <ReferenceLine y={targetRoas} stroke="#4f46e5" strokeDasharray="4 4" strokeWidth={1.5}
                        label={{ value: `Target ROAS (${targetRoas}×)`, position: 'insideTopLeft', fontSize: 10, fill: '#4f46e5' }} />

                    <XAxis
                        type="number"
                        dataKey="spend"
                        name="Spend"
                        domain={[0, xMax]}
                        tickFormatter={(v) => formatCurrency(v, currency, true)}
                        tick={{ fontSize: 11, fill: '#a1a1aa' }}
                        label={{ value: 'Ad Spend', position: 'insideBottom', offset: -16, fontSize: 11, fill: '#71717a' }}
                    />
                    <YAxis
                        type="number"
                        dataKey="y"
                        name="Real ROAS"
                        domain={[0, yMax]}
                        tickFormatter={(v) => `${v.toFixed(1)}×`}
                        tick={{ fontSize: 11, fill: '#a1a1aa' }}
                        label={{ value: 'Real ROAS', angle: -90, position: 'insideLeft', offset: 12, fontSize: 11, fill: '#71717a' }}
                        width={56}
                    />
                    <Tooltip content={<CustomTooltip />} />

                    <Scatter data={points} shape="circle">
                        {points.map((p, idx) => (
                            <Cell
                                key={`cell-${idx}`}
                                fill={p._quadrant === 'no_attribution'
                                    ? QUADRANT_COLORS.no_attribution
                                    : (PLATFORM_COLORS[p.platform] ?? QUADRANT_COLORS[p._quadrant])}
                                fillOpacity={0.75}
                                stroke={QUADRANT_COLORS[p._quadrant]}
                                strokeWidth={1.5}
                                r={Math.sqrt(p.r / Math.PI)}
                            />
                        ))}
                    </Scatter>
                </ScatterChart>
            </ResponsiveContainer>

            {/* Legend */}
            <div className="mt-4 flex flex-wrap justify-center gap-x-6 gap-y-2">
                {(['scale', 'hidden_gem', 'cut', 'ignore', 'no_attribution'] as Quadrant[]).map((q) => (
                    <div key={q} className="flex items-center gap-1.5 text-xs text-zinc-500">
                        <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: QUADRANT_COLORS[q] }} />
                        {QUADRANT_LABELS[q]}
                    </div>
                ))}
                <div className="flex items-center gap-1.5 text-xs text-zinc-400">
                    <span className="text-zinc-300">●</span>
                    Bubble size = attributed revenue
                </div>
            </div>
        </div>
    );
});

export { QuadrantChart };
