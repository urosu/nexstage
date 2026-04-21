import React, { useLayoutEffect, useRef, useMemo, useState } from 'react';
import {
    LineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ReferenceArea,
} from 'recharts';
import { formatDate, formatNumber, type Granularity } from '@/lib/formatters';

// Related: resources/js/Pages/Seo/Index.tsx (consumer)

export interface GscDataPoint {
    date: string;
    clicks: number;
    impressions: number;
    ctr: number | null;       // raw fraction, e.g. 0.05 = 5%
    position: number | null;  // avg ranking position — lower is better
}

type SeriesKey = 'clicks' | 'impressions' | 'ctr' | 'position';

interface SeriesConfig {
    key: SeriesKey;
    label: string;
    color: string;
    yAxisId: 'left' | 'ctr' | 'position';
    valueType: 'number' | 'percent' | 'position';
}

// Each series has its own axis so scale/domain is independent per metric type.
const SERIES: SeriesConfig[] = [
    { key: 'clicks',      label: 'Clicks',      color: 'var(--chart-1)', yAxisId: 'left',     valueType: 'number'   },
    { key: 'impressions', label: 'Impressions',  color: 'var(--chart-5)', yAxisId: 'left',     valueType: 'number'   },
    { key: 'ctr',         label: 'CTR',          color: 'var(--chart-2)', yAxisId: 'ctr',      valueType: 'percent'  },
    { key: 'position',    label: 'Avg Position', color: 'var(--chart-4)', yAxisId: 'position', valueType: 'position' },
];

function formatValue(value: number, valueType: SeriesConfig['valueType']): string {
    if (valueType === 'percent')  return `${(value * 100).toFixed(2)}%`;
    if (valueType === 'position') return value.toFixed(1);
    return formatNumber(value);
}

function formatAxisTick(value: number, valueType: SeriesConfig['valueType']): string {
    if (valueType === 'percent')  return `${(value * 100).toFixed(0)}%`;
    if (valueType === 'position') return value.toFixed(0);
    return formatNumber(value, true);
}

interface Props {
    data: GscDataPoint[];
    granularity: Granularity;
    timezone?: string;
    className?: string;
}

const GscMultiSeriesChartInner = function GscMultiSeriesChart({ data, granularity, timezone, className }: Props) {
    // Why: ResponsiveContainer starts with width=-1 and warns before ResizeObserver fires.
    // We measure the container ourselves so the chart only renders with real dimensions.
    const containerRef = useRef<HTMLDivElement>(null);
    const [chartSize, setChartSize] = useState<{ w: number; h: number } | null>(null);

    useLayoutEffect(() => {
        const el = containerRef.current;
        if (!el) return;
        const { width, height } = el.getBoundingClientRect();
        if (width > 0) setChartSize({ w: width, h: height });
        const ro = new ResizeObserver(([entry]) => {
            const { inlineSize: w, blockSize: h } = entry.contentBoxSize[0];
            setChartSize({ w, h });
        });
        ro.observe(el);
        return () => ro.disconnect();
    }, []);

    const [visible, setVisible] = useState<Set<SeriesKey>>(new Set(['clicks']));

    // Zoom state — drag to zoom like MultiSeriesLineChart
    const [refAreaLeft,  setRefAreaLeft]  = useState<string | null>(null);
    const [refAreaRight, setRefAreaRight] = useState<string | null>(null);
    const [isSelecting,  setIsSelecting]  = useState(false);
    const [zoomedIndices, setZoomedIndices] = useState<{ start: number; end: number } | null>(null);

    function toggle(key: SeriesKey): void {
        setVisible((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                if (next.size === 1) return prev; // keep at least one series
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    }

    function handleMouseDown(e: { activeLabel?: string | number }) {
        if (e?.activeLabel != null) {
            setRefAreaLeft(String(e.activeLabel));
            setIsSelecting(true);
        }
    }

    function handleMouseMove(e: { activeLabel?: string | number }) {
        if (isSelecting && e?.activeLabel != null) {
            setRefAreaRight(String(e.activeLabel));
        }
    }

    function handleMouseUp() {
        if (!isSelecting || !refAreaLeft) {
            setIsSelecting(false);
            setRefAreaLeft(null);
            setRefAreaRight(null);
            return;
        }

        let left  = refAreaLeft;
        let right = refAreaRight ?? refAreaLeft;
        if (left > right) [left, right] = [right, left];

        const startIdx = data.findIndex((p) => p.date >= left);
        let endIdx = -1;
        for (let i = data.length - 1; i >= 0; i--) {
            if (data[i].date <= right) { endIdx = i; break; }
        }

        if (startIdx !== -1 && endIdx !== -1 && endIdx > startIdx) {
            setZoomedIndices({ start: startIdx, end: endIdx });
        }

        setRefAreaLeft(null);
        setRefAreaRight(null);
        setIsSelecting(false);
    }

    function resetZoom() {
        setZoomedIndices(null);
        setRefAreaLeft(null);
        setRefAreaRight(null);
        setIsSelecting(false);
    }

    const displayData = useMemo(() => {
        if (!zoomedIndices) return data;
        return data.slice(zoomedIndices.start, zoomedIndices.end + 1);
    }, [data, zoomedIndices]);

    const showCtr   = visible.has('ctr');
    const showPos   = visible.has('position');
    const bothCounts = visible.has('clicks') && visible.has('impressions');
    const hasLeft   = visible.has('clicks') || visible.has('impressions');
    const hasRight  = showCtr || showPos;
    // When both CTR and position are visible, show CTR labels on the right axis;
    // position axis is still present for correct scaling but its labels are hidden.
    const showCtrLabels = showCtr;
    const showPosLabels = showPos && !showCtr;

    return (
        <div className={className ?? 'w-full'}>
            {/* Series toggle pills */}
            <div className="mb-3 flex flex-wrap items-center gap-1.5">
                {SERIES.map((s) => {
                    const on = visible.has(s.key);
                    return (
                        <button
                            key={s.key}
                            onClick={() => toggle(s.key)}
                            className={`flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors ${
                                on
                                    ? 'border-transparent text-white'
                                    : 'border-zinc-200 bg-white text-zinc-400 hover:text-zinc-600'
                            }`}
                            style={on ? { backgroundColor: s.color, borderColor: s.color } : undefined}
                        >
                            <span
                                className="h-1.5 w-1.5 rounded-full"
                                style={{ backgroundColor: on ? 'white' : s.color }}
                            />
                            {s.label}
                        </button>
                    );
                })}
                {zoomedIndices && (
                    <button
                        onClick={resetZoom}
                        className="ml-auto rounded-full border border-primary/20 bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary hover:bg-primary/15 transition-colors"
                    >
                        Reset zoom
                    </button>
                )}
            </div>

            <div
                ref={containerRef}
                className="relative h-56"
                style={{ userSelect: isSelecting ? 'none' : undefined }}
            >
                {chartSize && <LineChart
                        width={chartSize.w}
                        height={chartSize.h}
                        data={displayData}
                        margin={{ top: 4, right: hasRight ? 56 : 8, left: 0, bottom: 0 }}
                        onMouseDown={handleMouseDown}
                        onMouseMove={handleMouseMove}
                        onMouseUp={handleMouseUp}
                        onMouseLeave={() => {
                            if (isSelecting) {
                                setIsSelecting(false);
                                setRefAreaLeft(null);
                                setRefAreaRight(null);
                            }
                        }}
                        style={{ cursor: isSelecting ? 'col-resize' : 'crosshair' }}
                    >
                        <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" vertical={false} />
                        <XAxis
                            dataKey="date"
                            tickLine={false}
                            axisLine={false}
                            tick={{ fontSize: 11, fill: '#a1a1aa' }}
                            tickFormatter={(d) => formatDate(d, granularity, timezone)}
                            minTickGap={40}
                        />

                        {/* Left Y-axis — clicks + impressions.
                            Log scale only when both are active: impressions are typically
                            10-100× larger than clicks, so linear scale flattens clicks to zero. */}
                        <YAxis
                            yAxisId="left"
                            orientation="left"
                            scale={bothCounts ? 'log' : 'linear'}
                            domain={bothCounts ? [1, 'auto'] : [0, 'auto']}
                            allowDataOverflow={bothCounts}
                            tickLine={false}
                            axisLine={false}
                            tick={{ fontSize: 11, fill: '#a1a1aa' }}
                            tickFormatter={(v) => formatAxisTick(v, 'number')}
                            width={52}
                            hide={!hasLeft}
                        />

                        {/* CTR axis — separate from position so their scales don't conflict.
                            CTR is a small fraction (0.01–0.15); sharing an axis with position
                            (1–50) would make it appear flat near zero. */}
                        <YAxis
                            yAxisId="ctr"
                            orientation="right"
                            domain={[0, 'auto']}
                            tickLine={false}
                            axisLine={false}
                            tick={{ fontSize: 11, fill: showCtrLabels ? '#a1a1aa' : 'transparent' }}
                            tickFormatter={(v) => formatAxisTick(v, 'percent')}
                            width={showCtrLabels ? 44 : 0}
                            hide={!showCtr}
                        />

                        {/* Position axis — reversed so rank 1 (best) is at the top.
                            Labels shown only when CTR is not also active (to avoid overlap). */}
                        <YAxis
                            yAxisId="position"
                            orientation="right"
                            reversed
                            domain={['auto', 'auto']}
                            tickLine={false}
                            axisLine={false}
                            tick={{ fontSize: 11, fill: showPosLabels ? '#a1a1aa' : 'transparent' }}
                            tickFormatter={(v) => formatAxisTick(v, 'position')}
                            width={showPosLabels ? 44 : 0}
                            hide={!showPos}
                        />

                        <Tooltip
                            contentStyle={{
                                fontSize: 12,
                                borderRadius: 8,
                                border: '1px solid #e4e4e7',
                                boxShadow: '0 1px 8px rgba(0,0,0,0.08)',
                            }}
                            formatter={(value: unknown, name: unknown) => {
                                const num = typeof value === 'number' ? value : Number(value);
                                const cfg = SERIES.find((s) => s.key === String(name));
                                if (!cfg) return [String(value), String(name)];
                                const label = cfg.key === 'position'
                                    ? `${cfg.label} (lower = better)`
                                    : cfg.label;
                                return [formatValue(num, cfg.valueType), label] as [string, string];
                            }}
                            labelFormatter={(label) => formatDate(String(label), granularity, timezone)}
                        />

                        {refAreaLeft && refAreaRight && (
                            <ReferenceArea
                                yAxisId="left"
                                x1={refAreaLeft}
                                x2={refAreaRight}
                                strokeOpacity={0.3}
                                fill="var(--chart-1)"
                                fillOpacity={0.1}
                            />
                        )}

                        {SERIES.map((s) =>
                            visible.has(s.key) ? (
                                <Line
                                    key={s.key}
                                    yAxisId={s.yAxisId}
                                    type="monotone"
                                    dataKey={s.key}
                                    name={s.key}
                                    stroke={s.color}
                                    strokeWidth={2}
                                    dot={false}
                                    connectNulls
                                />
                            ) : null,
                        )}
                    </LineChart>}
            </div>
        </div>
    );
}

// Why: Inertia fires a second flushSync update ~190ms after navigation with new object references
// but identical data. Without this, the animation restarts mid-draw creating a visible gap.
// We compare data content (not reference) so identical data from Inertia's second update is blocked.
function dataEqual(prev: Props, next: Props): boolean {
    if (prev.granularity !== next.granularity) return false;
    if (prev.timezone   !== next.timezone)     return false;
    if (prev.className  !== next.className)    return false;
    if (prev.data       === next.data)         return true;
    if (prev.data.length !== next.data.length) return false;
    return prev.data.every((p, i) => {
        const n = next.data[i];
        return p.date === n.date && p.clicks === n.clicks &&
               p.impressions === n.impressions && p.ctr === n.ctr && p.position === n.position;
    });
}

export const GscMultiSeriesChart = React.memo(GscMultiSeriesChartInner, dataEqual);
