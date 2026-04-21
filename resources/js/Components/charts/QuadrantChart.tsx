import React, { useLayoutEffect, useRef, useState, useMemo } from 'react';
import {
    ScatterChart,
    Scatter,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ReferenceLine,
    ReferenceArea,
    Cell,
} from 'recharts';
import { formatCurrency, formatNumber } from '@/lib/formatters';

// ─── Legacy campaigns-mode types (kept for backward compat) ─────────────────

export interface QuadrantCampaign {
    id: number;
    name: string;
    platform: string;
    spend: number;
    real_roas: number | null;
    attributed_revenue: number | null;
    attributed_orders: number;
}

// ─── Generic mode types ──────────────────────────────────────────────────────

/**
 * A data row for the generic QuadrantChart mode.
 * All values are numbers (null = missing / not available).
 * The label is shown in the tooltip.
 */
export interface QuadrantPoint {
    id: number | string;
    label: string;
    /** Value for the X axis */
    x: number;
    /** Value for the Y axis */
    y: number | null;
    /** Value used to size the bubble (e.g. revenue, units). Null = minimum size. */
    size?: number | null;
    /**
     * Value used to color the bubble. Interpretation depends on colorMode:
     *   'quadrant'  — color by quadrant position (default)
     *   'category'  — colorField is a category string key for CATEGORY_COLORS
     *   'continuous' — colorField is a number, interpolated green↔red
     */
    color?: string | number | null;
    /** Arbitrary extra fields surfaced in the tooltip */
    meta?: Record<string, string | number | null>;
}

/**
 * Field configuration for the generic QuadrantChart.
 *
 * Mirrors the prop names in PLANNING.md section 12.5 (scatter view on
 * /analytics/products: x=revenue, y=margin %, size=units, color=stock_status).
 */
export interface QuadrantFieldConfig {
    xLabel: string;          // Axis label + tooltip row label
    yLabel: string;
    sizeLabel?: string;      // Tooltip row label when size != null
    colorLabel?: string;     // Tooltip row label for color value

    /** How values are formatted for display */
    xFormatter?: (v: number) => string;
    yFormatter?: (v: number | null) => string;
    sizeFormatter?: (v: number | null) => string;
    colorFormatter?: (v: string | number | null) => string;

    /**
     * The threshold line on the Y axis (= target ROAS for campaigns, = 0 for
     * margin %, etc.). The quadrant label language adapts when labelMode is set.
     */
    yThreshold?: number;
    yThresholdLabel?: string;  // Label for the threshold reference line

    /** Median-based or fixed X threshold. Default: computed median of x values. */
    xThreshold?: number;
    xThresholdLabel?: string;

    /**
     * 'quadrant' — color by quadrant (Scale / Hidden Gem / Cut / Ignore).
     * 'category' — color by color field string via CATEGORY_COLORS map.
     */
    colorMode?: 'quadrant' | 'category';

    /** Map of category strings to hex colors (for colorMode='category') */
    categoryColors?: Record<string, string>;

    /**
     * Quadrant label overrides. Defaults to the campaign-oriented labels:
     *   topRight=Scale, topLeft=Hidden Gem, bottomRight=Cut/Fix, bottomLeft=Ignore
     */
    topRightLabel?:    string;
    topLeftLabel?:     string;
    bottomRightLabel?: string;
    bottomLeftLabel?:  string;
}

// ─── Shared internals ────────────────────────────────────────────────────────

const PLATFORM_COLORS: Record<string, string> = {
    facebook: '#1877f2',
    google:   '#ea4335',
};

type Quadrant = 'scale' | 'hidden_gem' | 'cut' | 'ignore' | 'no_data';

const QUADRANT_COLORS_DEFAULT: Record<Quadrant, string> = {
    scale:      '#16a34a',
    hidden_gem: '#0d9488',
    cut:        '#dc2626',
    ignore:     '#71717a',
    no_data:    '#a1a1aa',
};

function getQuadrant(
    x: number,
    y: number | null,
    xThreshold: number,
    yThreshold: number,
): Quadrant {
    if (y === null) return 'no_data';
    const highX = x >= xThreshold;
    const highY = y >= yThreshold;
    if (highY && highX)  return 'scale';
    if (highY && !highX) return 'hidden_gem';
    if (!highY && highX) return 'cut';
    return 'ignore';
}

function scaleBubble(value: number | null, min: number, max: number): number {
    if (value === null || max === min) return 200;
    const ratio = (value - min) / (max - min);
    return Math.max(40, Math.round(40 + ratio * 500));
}

// ─── Legacy campaigns-mode component (original implementation) ────────────────

interface LegacyProps {
    campaigns: QuadrantCampaign[];
    currency: string;
    targetRoas?: number;
    yLabel?: string;
    hiddenCount?: number;
    hiddenLabel?: string;
}

interface LegacyTooltipPayload {
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

function makeLegacyTooltip(currency: string, yLabel: string) {
    return function CustomTooltip({ active, payload }: { active?: boolean; payload?: LegacyTooltipPayload[] }) {
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
                    <span className="text-zinc-400">Spend</span>
                    <span className="font-medium tabular-nums text-zinc-700">
                        {formatCurrency(d.spend, currency)}
                    </span>
                    <span className="text-zinc-400">{yLabel}</span>
                    <span className={[
                        'font-medium tabular-nums',
                        d.real_roas != null
                            ? d.real_roas >= 1.5 ? 'text-green-700' : 'text-red-600'
                            : 'text-zinc-400',
                    ].join(' ')}>
                        {d.real_roas != null ? `${d.real_roas.toFixed(2)}×` : '—'}
                    </span>
                    {d.attributed_revenue !== null && (
                        <>
                            <span className="text-zinc-400">Attr. Revenue</span>
                            <span className="font-medium tabular-nums text-zinc-700">
                                {formatCurrency(d.attributed_revenue, currency)}
                            </span>
                        </>
                    )}
                    {d.attributed_orders > 0 && (
                        <>
                            <span className="text-zinc-400">Attr. Orders</span>
                            <span className="font-medium tabular-nums text-zinc-700">
                                {formatNumber(d.attributed_orders)}
                            </span>
                        </>
                    )}
                </div>
            </div>
        );
    };
}

function LegacyQuadrantChart({
    campaigns,
    currency,
    targetRoas = 1.5,
    yLabel = 'Real ROAS',
    hiddenCount = 0,
    hiddenLabel = 'items',
}: LegacyProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const [chartWidth, setChartWidth] = useState<number | null>(null);
    useLayoutEffect(() => {
        const el = containerRef.current;
        if (!el) return;
        const { width } = el.getBoundingClientRect();
        if (width > 0) setChartWidth(width);
        const ro = new ResizeObserver(([entry]) => {
            setChartWidth(entry.contentBoxSize[0].inlineSize);
        });
        ro.observe(el);
        return () => ro.disconnect();
    }, []);

    const { points, medianSpend, minSpend, maxSpend, minRoas, maxRoas } = useMemo(() => {
        const withSpend = campaigns.filter((c) => c.spend > 0);
        if (withSpend.length === 0) return { points: [], medianSpend: 0, minSpend: 0, maxSpend: 0, minRoas: 0, maxRoas: 0 };

        const sorted = [...withSpend].sort((a, b) => a.spend - b.spend);
        const mid    = Math.floor(sorted.length / 2);
        const median = sorted.length % 2 === 0
            ? (sorted[mid - 1].spend + sorted[mid].spend) / 2
            : sorted[mid].spend;

        const revenues = withSpend.map((c) => c.attributed_revenue ?? 0);
        const minRev   = Math.min(...revenues);
        const maxRev   = Math.max(...revenues);
        const minS     = Math.min(...withSpend.map((c) => c.spend));
        const maxS     = Math.max(...withSpend.map((c) => c.spend));
        const withRoas = withSpend.filter((c) => c.real_roas != null);
        const minR     = withRoas.length > 0 ? Math.min(...withRoas.map((c) => c.real_roas!)) : 0;
        const maxR     = withRoas.length > 0 ? Math.max(...withRoas.map((c) => c.real_roas!)) : 0;

        const pts = withSpend.map((c) => ({
            ...c,
            y:         c.real_roas ?? 0.01,
            r:         scaleBubble(c.attributed_revenue, minRev, maxRev),
            _quadrant: getQuadrant(c.spend, c.real_roas, median, targetRoas),
        }));

        return { points: pts, medianSpend: median, minSpend: minS, maxSpend: maxS, minRoas: minR, maxRoas: maxR };
    }, [campaigns, targetRoas]);

    if (points.length === 0) {
        return (
            <div className="flex h-[460px] flex-col items-center justify-center gap-2 text-center">
                <p className="text-sm text-zinc-400">No spend data for this period.</p>
            </div>
        );
    }

    const yMax  = Math.max(targetRoas * 2, maxRoas * 1.1, 3);
    const xMax  = maxSpend * 2;
    const xMin  = minSpend * 0.5;
    const yMin  = Math.min(minRoas * 0.7, targetRoas * 0.5);

    return (
        <div ref={containerRef}>
            {chartWidth && <ScatterChart width={chartWidth} height={460} margin={{ top: 16, right: 24, bottom: 32, left: 8 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" />

                    <ReferenceArea x1={medianSpend} x2={xMax} y1={targetRoas} y2={yMax}
                        fill="#f0fdf4" fillOpacity={0.6}
                        label={{ value: 'Scale', position: 'insideTopRight', fontSize: 11, fill: '#16a34a', fontWeight: 600 }} />
                    <ReferenceArea x1={xMin} x2={medianSpend} y1={targetRoas} y2={yMax}
                        fill="#f0fdfa" fillOpacity={0.6}
                        label={{ value: 'Hidden Gem', position: 'insideTopLeft', fontSize: 11, fill: '#0d9488', fontWeight: 600 }} />
                    <ReferenceArea x1={medianSpend} x2={xMax} y1={yMin} y2={targetRoas}
                        fill="#fef2f2" fillOpacity={0.6}
                        label={{ value: 'Cut / Fix', position: 'insideBottomRight', fontSize: 11, fill: '#dc2626', fontWeight: 600 }} />
                    <ReferenceArea x1={xMin} x2={medianSpend} y1={yMin} y2={targetRoas}
                        fill="#fafafa" fillOpacity={0.6}
                        label={{ value: 'Ignore', position: 'insideBottomLeft', fontSize: 11, fill: '#71717a', fontWeight: 600 }} />

                    <ReferenceLine x={medianSpend} stroke="#d4d4d8" strokeDasharray="4 4"
                        label={{ value: 'Median spend', position: 'insideTopRight', fontSize: 10, fill: '#a1a1aa' }} />
                    <ReferenceLine y={targetRoas} stroke="#4f46e5" strokeDasharray="4 4" strokeWidth={1.5}
                        label={{ value: `Target ROAS (${targetRoas}×)`, position: 'insideTopLeft', fontSize: 10, fill: '#4f46e5' }} />

                    {/* Log scale on X: spreads out power-law spend distributions */}
                    <XAxis
                        type="number"
                        dataKey="spend"
                        name="Spend"
                        scale="log"
                        domain={[xMin, xMax]}
                        tickFormatter={(v) => formatCurrency(v, currency, true)}
                        tick={{ fontSize: 11, fill: '#a1a1aa' }}
                        label={{ value: 'Ad Spend', position: 'insideBottom', offset: -16, fontSize: 11, fill: '#71717a' }}
                    />
                    {/* Log scale on Y: spreads campaigns near ROAS=0 from high performers */}
                    <YAxis
                        type="number"
                        dataKey="y"
                        name={yLabel}
                        scale="log"
                        domain={[yMin, yMax]}
                        tickFormatter={(v) => v < 0.1 ? '' : `${v.toFixed(1)}×`}
                        tick={{ fontSize: 11, fill: '#a1a1aa' }}
                        label={{ value: yLabel, angle: -90, position: 'insideLeft', offset: 12, fontSize: 11, fill: '#71717a' }}
                        width={56}
                    />
                    <Tooltip content={React.createElement(makeLegacyTooltip(currency, yLabel))} />

                    <Scatter data={points} shape="circle">
                        {points.map((p, idx) => (
                            <Cell
                                key={`cell-${idx}`}
                                fill={p._quadrant === 'no_data'
                                    ? QUADRANT_COLORS_DEFAULT.no_data
                                    : (PLATFORM_COLORS[p.platform] ?? QUADRANT_COLORS_DEFAULT[p._quadrant])}
                                fillOpacity={0.75}
                                stroke={QUADRANT_COLORS_DEFAULT[p._quadrant]}
                                strokeWidth={1.5}
                                r={Math.sqrt(p.r / Math.PI)}
                            />
                        ))}
                    </Scatter>
                </ScatterChart>}

            {/* Quadrant breakdown counts */}
            {(() => {
                const counts: Record<Quadrant, number> = { scale: 0, hidden_gem: 0, cut: 0, ignore: 0, no_data: 0 };
                for (const p of points) counts[p._quadrant]++;
                const visible: { q: Exclude<Quadrant, 'no_data'>; label: string; color: string }[] = [
                    { q: 'scale',      label: 'Scale',       color: '#16a34a' },
                    { q: 'hidden_gem', label: 'Hidden Gem',  color: '#0d9488' },
                    { q: 'cut',        label: 'Cut / Fix',   color: '#dc2626' },
                    { q: 'ignore',     label: 'Ignore',      color: '#71717a' },
                ];
                return (
                    <div className="mt-4 flex flex-wrap items-center justify-center gap-x-5 gap-y-1.5 text-xs">
                        {visible.map(({ q, label, color }) => (
                            <div key={q} className="flex items-center gap-1.5">
                                <span className="h-2 w-2 rounded-full flex-shrink-0" style={{ backgroundColor: color }} />
                                <span className="text-zinc-500">{label}</span>
                                <span className="tabular-nums font-semibold text-zinc-800">{counts[q]}</span>
                            </div>
                        ))}
                        {hiddenCount > 0 && (
                            <div className="flex items-center gap-1.5" title={`${hiddenCount} ${hiddenLabel} have no ROAS signal`}>
                                <span className="h-2 w-2 rounded-full flex-shrink-0 bg-zinc-300" />
                                <span className="text-zinc-400">No attribution</span>
                                <span className="tabular-nums font-semibold text-zinc-400">{hiddenCount}</span>
                            </div>
                        )}
                    </div>
                );
            })()}

            {/* Quadrant guide — 2×2 grid explaining each zone */}
            <div className="mt-5 grid grid-cols-2 gap-2 text-xs">
                <div className="rounded-lg border border-teal-100 bg-teal-50 px-3 py-2.5">
                    <div className="flex items-center gap-1.5 font-semibold text-teal-700 mb-0.5">
                        <span className="h-2 w-2 rounded-full bg-teal-600" />
                        Hidden Gem — grow budget now
                    </div>
                    <p className="text-zinc-500 leading-snug">High ROAS, low spend. These are your best opportunities — increase budget before competitors notice them.</p>
                </div>
                <div className="rounded-lg border border-green-100 bg-green-50 px-3 py-2.5">
                    <div className="flex items-center gap-1.5 font-semibold text-green-700 mb-0.5">
                        <span className="h-2 w-2 rounded-full bg-green-600" />
                        Scale — keep investing
                    </div>
                    <p className="text-zinc-500 leading-snug">High ROAS, high spend. Already working at scale. Maintain or increase budget while ROAS holds.</p>
                </div>
                <div className="rounded-lg border border-zinc-100 bg-zinc-50 px-3 py-2.5">
                    <div className="flex items-center gap-1.5 font-semibold text-zinc-500 mb-0.5">
                        <span className="h-2 w-2 rounded-full bg-zinc-400" />
                        Ignore — low priority
                    </div>
                    <p className="text-zinc-500 leading-snug">Low ROAS, low spend. Not worth fixing yet — the impact is small. Revisit if spend grows.</p>
                </div>
                <div className="rounded-lg border border-red-100 bg-red-50 px-3 py-2.5">
                    <div className="flex items-center gap-1.5 font-semibold text-red-700 mb-0.5">
                        <span className="h-2 w-2 rounded-full bg-red-600" />
                        Cut / Fix — act now
                    </div>
                    <p className="text-zinc-500 leading-snug">Low ROAS, high spend. Burning budget. Pause and test new creative, or reduce budget while you diagnose.</p>
                </div>
            </div>
            <p className="mt-2 text-center text-[11px] text-zinc-400">Bubble size = attributed revenue · X axis = ad spend · Y axis = {yLabel}</p>
        </div>
    );
}

// ─── Generic mode component ──────────────────────────────────────────────────

interface GenericTooltipPayload {
    payload: QuadrantPoint & {
        _quadrant: Quadrant;
        _x: number;
        _y: number | null;
        _r: number;
        _color: string;
    };
}

function makeGenericTooltip(config: QuadrantFieldConfig) {
    const {
        xLabel, yLabel, sizeLabel, colorLabel,
        xFormatter = (v) => formatNumber(v),
        yFormatter = (v) => v == null ? '—' : formatNumber(v),
        sizeFormatter = (v) => v == null ? '—' : formatNumber(v),
        colorFormatter = (v) => v == null ? '—' : String(v),
    } = config;

    return function CustomTooltip({ active, payload }: { active?: boolean; payload?: GenericTooltipPayload[] }) {
        if (!active || !payload?.length) return null;
        const d = payload[0].payload;

        return (
            <div className="rounded-xl border border-zinc-200 bg-white px-4 py-3 shadow-lg text-sm min-w-[200px]">
                <p className="mb-2 font-semibold text-zinc-900 truncate max-w-[200px]" title={d.label}>{d.label}</p>
                <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                    <span className="text-zinc-400">{xLabel}</span>
                    <span className="font-medium tabular-nums text-zinc-700">{xFormatter(d._x)}</span>
                    <span className="text-zinc-400">{yLabel}</span>
                    <span className="font-medium tabular-nums text-zinc-700">{yFormatter(d._y)}</span>
                    {sizeLabel && d.size != null && (
                        <>
                            <span className="text-zinc-400">{sizeLabel}</span>
                            <span className="font-medium tabular-nums text-zinc-700">{sizeFormatter(d.size)}</span>
                        </>
                    )}
                    {colorLabel && d.color != null && (
                        <>
                            <span className="text-zinc-400">{colorLabel}</span>
                            <span className="font-medium tabular-nums text-zinc-700">{colorFormatter(d.color)}</span>
                        </>
                    )}
                    {d.meta && Object.entries(d.meta).map(([k, v]) => (
                        <React.Fragment key={k}>
                            <span className="text-zinc-400">{k}</span>
                            <span className="font-medium tabular-nums text-zinc-700">{v ?? '—'}</span>
                        </React.Fragment>
                    ))}
                </div>
            </div>
        );
    };
}

interface GenericProps {
    data: QuadrantPoint[];
    config: QuadrantFieldConfig;
    /**
     * If true, use a log scale on the X axis. Useful when X values span
     * orders of magnitude (e.g. spend). Default: false.
     */
    xLogScale?: boolean;
    /** Same for Y. Default: false. */
    yLogScale?: boolean;
}

function GenericQuadrantChart({ data, config, xLogScale = false, yLogScale = false }: GenericProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const [chartWidth, setChartWidth] = useState<number | null>(null);
    useLayoutEffect(() => {
        const el = containerRef.current;
        if (!el) return;
        const { width } = el.getBoundingClientRect();
        if (width > 0) setChartWidth(width);
        const ro = new ResizeObserver(([entry]) => {
            setChartWidth(entry.contentBoxSize[0].inlineSize);
        });
        ro.observe(el);
        return () => ro.disconnect();
    }, []);

    const {
        xLabel, yLabel,
        yThreshold = 0,
        yThresholdLabel,
        xThreshold: xThresholdOverride,
        xThresholdLabel,
        colorMode = 'quadrant',
        categoryColors = {},
        topRightLabel    = 'Scale',
        topLeftLabel     = 'Hidden Gem',
        bottomRightLabel = 'Cut / Fix',
        bottomLeftLabel  = 'Ignore',
    } = config;

    const processed = useMemo(() => {
        const valid = data.filter((d) => d.x > 0 || !xLogScale);
        if (valid.length === 0) return { points: [], xThreshold: 0, xMin: 0, xMax: 0, yMin: 0, yMax: 0 };

        const xs = valid.map((d) => d.x);
        const ys = valid.map((d) => d.y ?? 0);
        const sizes = valid.map((d) => d.size ?? 0);
        const minX = Math.min(...xs), maxX = Math.max(...xs);
        const minY = Math.min(...ys), maxY = Math.max(...ys);
        const minS = Math.min(...sizes), maxS = Math.max(...sizes);

        // Median x as default x-threshold
        const sortedXs = [...xs].sort((a, b) => a - b);
        const mid = Math.floor(sortedXs.length / 2);
        const medianX = sortedXs.length % 2 === 0
            ? (sortedXs[mid - 1] + sortedXs[mid]) / 2
            : sortedXs[mid];
        const resolvedXThreshold = xThresholdOverride ?? medianX;

        const pts = valid.map((d) => {
            const q = getQuadrant(d.x, d.y, resolvedXThreshold, yThreshold);
            let dotColor = QUADRANT_COLORS_DEFAULT[q];
            if (colorMode === 'category' && typeof d.color === 'string') {
                dotColor = categoryColors[d.color] ?? dotColor;
            }
            return {
                ...d,
                _x:        d.x,
                _y:        d.y ?? (yLogScale ? 0.01 : 0),
                _r:        scaleBubble(d.size ?? null, minS, maxS),
                _quadrant: q,
                _color:    dotColor,
            };
        });

        return {
            points: pts,
            xThreshold: resolvedXThreshold,
            xMin: minX,
            xMax: maxX,
            yMin: minY,
            yMax: maxY,
        };
    }, [data, xThresholdOverride, yThreshold, colorMode, categoryColors, xLogScale, yLogScale]);

    if (processed.points.length === 0) {
        return (
            <div className="flex h-[460px] flex-col items-center justify-center gap-2 text-center">
                <p className="text-sm text-zinc-400">No data for this period.</p>
            </div>
        );
    }

    const { points, xThreshold, xMin, xMax, yMin, yMax } = processed;
    const xPad = (xMax - xMin) * 0.15 || 1;
    const yPad = (yMax - yMin) * 0.15 || 1;
    const chartXMin = xLogScale ? Math.max(xMin * 0.5, 0.01) : xMin - xPad;
    const chartXMax = xLogScale ? xMax * 2 : xMax + xPad;
    const chartYMin = yLogScale ? Math.max(yMin * 0.7, 0.01) : yMin - yPad;
    const chartYMax = yLogScale ? yMax * 1.5 : yMax + yPad;

    const quadrantLabels = [
        { x1: xThreshold, x2: chartXMax, y1: yThreshold, y2: chartYMax, fill: '#f0fdf4', label: topRightLabel,    labelPos: 'insideTopRight' as const,    color: '#16a34a' },
        { x1: chartXMin,  x2: xThreshold, y1: yThreshold, y2: chartYMax, fill: '#f0fdfa', label: topLeftLabel,     labelPos: 'insideTopLeft'  as const,    color: '#0d9488' },
        { x1: xThreshold, x2: chartXMax, y1: chartYMin,  y2: yThreshold, fill: '#fef2f2', label: bottomRightLabel, labelPos: 'insideBottomRight' as const, color: '#dc2626' },
        { x1: chartXMin,  x2: xThreshold, y1: chartYMin,  y2: yThreshold, fill: '#fafafa', label: bottomLeftLabel,  labelPos: 'insideBottomLeft'  as const, color: '#71717a' },
    ];

    const { xFormatter = (v) => formatNumber(v), yFormatter = (v) => v == null ? '' : formatNumber(v) } = config;

    return (
        <div ref={containerRef}>
            {chartWidth && <ScatterChart width={chartWidth} height={460} margin={{ top: 16, right: 24, bottom: 32, left: 8 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" />

                    {quadrantLabels.map((q, i) => (
                        <ReferenceArea
                            key={i}
                            x1={q.x1} x2={q.x2} y1={q.y1} y2={q.y2}
                            fill={q.fill} fillOpacity={0.6}
                            label={{ value: q.label, position: q.labelPos, fontSize: 11, fill: q.color, fontWeight: 600 }}
                        />
                    ))}

                    <ReferenceLine
                        x={xThreshold}
                        stroke="#d4d4d8" strokeDasharray="4 4"
                        label={{ value: xThresholdLabel ?? 'Median', position: 'insideTopRight', fontSize: 10, fill: '#a1a1aa' }}
                    />
                    <ReferenceLine
                        y={yThreshold}
                        stroke="#4f46e5" strokeDasharray="4 4" strokeWidth={1.5}
                        label={{ value: yThresholdLabel ?? String(yThreshold), position: 'insideTopLeft', fontSize: 10, fill: '#4f46e5' }}
                    />

                    <XAxis
                        type="number"
                        dataKey="_x"
                        name={xLabel}
                        scale={xLogScale ? 'log' : 'auto'}
                        domain={[chartXMin, chartXMax]}
                        tickFormatter={xFormatter}
                        tick={{ fontSize: 11, fill: '#a1a1aa' }}
                        label={{ value: xLabel, position: 'insideBottom', offset: -16, fontSize: 11, fill: '#71717a' }}
                    />
                    <YAxis
                        type="number"
                        dataKey="_y"
                        name={yLabel}
                        scale={yLogScale ? 'log' : 'auto'}
                        domain={[chartYMin, chartYMax]}
                        tickFormatter={(v) => yFormatter(v)}
                        tick={{ fontSize: 11, fill: '#a1a1aa' }}
                        label={{ value: yLabel, angle: -90, position: 'insideLeft', offset: 12, fontSize: 11, fill: '#71717a' }}
                        width={64}
                    />
                    <Tooltip content={React.createElement(makeGenericTooltip(config))} />

                    <Scatter data={points} shape="circle">
                        {points.map((p, idx) => (
                            <Cell
                                key={`cell-${idx}`}
                                fill={p._color}
                                fillOpacity={0.75}
                                stroke={p._color}
                                strokeWidth={1.5}
                                r={Math.sqrt(p._r / Math.PI)}
                            />
                        ))}
                    </Scatter>
                </ScatterChart>}

            <p className="mt-2 text-center text-[11px] text-zinc-400">
                Bubble size = {config.sizeLabel ?? 'value'} · X axis = {xLabel} · Y axis = {yLabel}
            </p>
        </div>
    );
}

// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * QuadrantChart — scatter/bubble chart for positioning items in a 2×2 matrix.
 *
 * **Legacy (campaigns) mode** — pass `campaigns` + `currency`. Unchanged from
 * Phase 1.4. This is what `/campaigns` uses.
 *
 * **Generic mode** — pass `data` + `config`. Used by Phase 1.6 pages:
 *   `/analytics/products` (x=revenue, y=margin %, size=units, color=stock_status)
 *   `/acquisition`        (x=clicks,  y=CVR,      size=revenue)
 *
 * The two modes are distinguished by which props are provided. Passing `data`
 * overrides `campaigns`; passing `campaigns` without `data` uses legacy mode.
 *
 * @see PLANNING.md section 12.5 (Products scatter, Acquisition QuadrantChart)
 */
const QuadrantChart = React.memo(function QuadrantChart(
    props: (LegacyProps & { data?: undefined; config?: undefined }) |
           (GenericProps & { campaigns?: undefined; currency?: undefined }),
) {
    if ('data' in props && props.data !== undefined) {
        return <GenericQuadrantChart data={props.data} config={props.config} xLogScale={props.xLogScale} yLogScale={props.yLogScale} />;
    }

    const legacy = props as LegacyProps;
    return <LegacyQuadrantChart {...legacy} />;
});

export { QuadrantChart };
export type { LegacyProps as QuadrantChartCampaignProps, GenericProps as QuadrantChartGenericProps };
