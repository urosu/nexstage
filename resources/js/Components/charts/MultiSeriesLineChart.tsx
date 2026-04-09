import React, { useMemo, useState } from 'react';
import {
    LineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    ReferenceLine,
    ReferenceArea,
} from 'recharts';
import { formatCurrency, formatDate, formatNumber, type Granularity } from '@/lib/formatters';

export interface MultiSeriesPoint {
    date: string;
    revenue: number;
    orders: number;
    aov: number | null;
    roas: number | null;
    ad_spend: number | null;
}

type SeriesKey = 'revenue' | 'orders' | 'aov' | 'roas' | 'ad_spend';

interface SeriesConfig {
    key: SeriesKey;
    label: string;
    color: string;
    /** left = currency axis, right = count/ratio axis */
    yAxis: 'left' | 'right';
    valueType: 'currency' | 'number' | 'ratio';
}

const SERIES: SeriesConfig[] = [
    { key: 'revenue',  label: 'Revenue',   color: '#4f46e5', yAxis: 'left',  valueType: 'currency' },
    { key: 'orders',   label: 'Orders',    color: '#0891b2', yAxis: 'right', valueType: 'number'   },
    { key: 'aov',      label: 'AOV',       color: '#059669', yAxis: 'left',  valueType: 'currency' },
    { key: 'ad_spend', label: 'Ad Spend',  color: '#dc2626', yAxis: 'left',  valueType: 'currency' },
    { key: 'roas',     label: 'ROAS',      color: '#d97706', yAxis: 'right', valueType: 'ratio'    },
];

interface NoteMarkerProps {
    viewBox?: { x: number; y: number; width: number; height: number };
    note: string;
    onHoverChange: (state: { note: string; x: number; y: number } | null) => void;
}

function NoteMarker({ viewBox, note, onHoverChange }: NoteMarkerProps) {
    if (!viewBox) return null;
    const cx = viewBox.x + viewBox.width / 2;
    const cy = viewBox.y;
    return (
        <circle
            cx={cx}
            cy={cy + 6}
            r={5}
            fill="#f59e0b"
            style={{ cursor: 'default' }}
            onMouseEnter={() => onHoverChange({ note, x: cx, y: cy + 6 })}
            onMouseLeave={() => onHoverChange(null)}
        />
    );
}

interface Props {
    data: MultiSeriesPoint[];
    /** Comparison data — revenue series only */
    comparisonData?: MultiSeriesPoint[];
    /** Annotate specific dates with a subtle reference line + amber marker */
    notes?: Array<{ date: string; note: string }>;
    granularity: Granularity;
    currency?: string;
    timezone?: string;
    className?: string;
}

function formatValue(value: number, valueType: SeriesConfig['valueType'], currency: string): string {
    if (valueType === 'currency') return formatCurrency(value, currency);
    if (valueType === 'ratio')    return `${value.toFixed(2)}×`;
    return formatNumber(value);
}

function formatAxis(value: number, valueType: SeriesConfig['valueType'], currency: string): string {
    if (valueType === 'currency') return formatCurrency(value, currency, true);
    if (valueType === 'ratio')    return `${value.toFixed(1)}×`;
    return formatNumber(value, true);
}

const MultiSeriesLineChartInner = React.memo(function MultiSeriesLineChartInner({
    data,
    comparisonData,
    notes,
    granularity,
    currency = 'EUR',
    timezone,
    className,
}: Props) {
    const [visible, setVisible] = useState<Set<SeriesKey>>(new Set(['revenue']));
    const [hoveredNote, setHoveredNote] = useState<{ note: string; x: number; y: number } | null>(null);

    // Zoom state
    const [refAreaLeft, setRefAreaLeft]   = useState<string | null>(null);
    const [refAreaRight, setRefAreaRight] = useState<string | null>(null);
    const [isSelecting, setIsSelecting]   = useState(false);
    const [zoomedIndices, setZoomedIndices] = useState<{ start: number; end: number } | null>(null);

    function toggle(key: SeriesKey): void {
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

    const displayComparison = useMemo(() => {
        if (!zoomedIndices || !comparisonData?.length) return comparisonData;
        return comparisonData.slice(zoomedIndices.start, zoomedIndices.end + 1);
    }, [comparisonData, zoomedIndices]);

    const merged = useMemo(() => {
        if (!displayComparison?.length) return displayData;
        return displayData.map((point, i) => {
            const cmp = displayComparison[i];
            return {
                ...point,
                compare_revenue:  cmp?.revenue   ?? null,
                compare_orders:   cmp?.orders    ?? null,
                compare_aov:      cmp?.aov       ?? null,
                compare_ad_spend: cmp?.ad_spend  ?? null,
                compare_roas:     cmp?.roas      ?? null,
            };
        });
    }, [displayData, displayComparison]);

    const hasLeft  = SERIES.some((s) => s.yAxis === 'left'  && visible.has(s.key));
    const hasRight = SERIES.some((s) => s.yAxis === 'right' && visible.has(s.key));

    const leftSeries  = SERIES.filter((s) => s.yAxis === 'left');
    const rightSeries = SERIES.filter((s) => s.yAxis === 'right');

    return (
        <div className={className ?? 'w-full'}>
            {/* Series toggle pills + zoom reset */}
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
                {comparisonData?.length && (
                    <span className="flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2.5 py-0.5 text-xs text-zinc-400">
                        <span className="inline-block h-0 w-3 border-t-2 border-dashed border-zinc-400" />
                        Previous period
                    </span>
                )}
                {zoomedIndices && (
                    <button
                        onClick={resetZoom}
                        className="ml-auto rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-600 hover:bg-indigo-100 transition-colors"
                    >
                        Reset zoom
                    </button>
                )}
            </div>

            <div
                className="relative h-64"
                style={{ userSelect: isSelecting ? 'none' : undefined }}
            >
                {hoveredNote && (
                    <div
                        className="pointer-events-none absolute z-10 max-w-[220px] -translate-x-1/2 -translate-y-full rounded border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 shadow-md"
                        style={{ left: hoveredNote.x, top: hoveredNote.y - 10 }}
                    >
                        {hoveredNote.note}
                    </div>
                )}
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart
                        data={merged}
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

                        {/* Left Y-axis — currency */}
                        <YAxis
                            yAxisId="left"
                            orientation="left"
                            tickLine={false}
                            axisLine={false}
                            tick={{ fontSize: 11, fill: '#a1a1aa' }}
                            tickFormatter={(v) => {
                                const first = leftSeries.find((s) => visible.has(s.key));
                                return first ? formatAxis(v, first.valueType, currency) : String(v);
                            }}
                            width={60}
                            hide={!hasLeft}
                        />

                        {/* Right Y-axis — count / ratio */}
                        <YAxis
                            yAxisId="right"
                            orientation="right"
                            tickLine={false}
                            axisLine={false}
                            tick={{ fontSize: 11, fill: '#a1a1aa' }}
                            tickFormatter={(v) => {
                                const first = rightSeries.find((s) => visible.has(s.key));
                                return first ? formatAxis(v, first.valueType, currency) : String(v);
                            }}
                            width={52}
                            hide={!hasRight}
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
                                const key = String(name);
                                if (key.startsWith('compare_')) {
                                    const baseKey = key.replace('compare_', '') as SeriesKey;
                                    const cfg = SERIES.find((s) => s.key === baseKey);
                                    return [
                                        cfg ? formatValue(num, cfg.valueType, currency) : String(num),
                                        `${cfg?.label ?? baseKey} (prev)`,
                                    ] as [string, string];
                                }
                                const cfg = SERIES.find((s) => s.key === key);
                                return [
                                    cfg ? formatValue(num, cfg.valueType, currency) : String(num),
                                    cfg?.label ?? key,
                                ] as [string, string];
                            }}
                            labelFormatter={(label) => formatDate(String(label), granularity, timezone)}
                        />

                        {/* Drag-to-zoom selection area */}
                        {refAreaLeft && refAreaRight && (
                            <ReferenceArea
                                yAxisId="left"
                                x1={refAreaLeft}
                                x2={refAreaRight}
                                strokeOpacity={0.3}
                                fill="#4f46e5"
                                fillOpacity={0.1}
                            />
                        )}

                        {/* Comparison lines — one per visible series */}
                        {comparisonData?.length && SERIES.map((s) =>
                            visible.has(s.key) ? (
                                <Line
                                    key={`compare_${s.key}`}
                                    yAxisId={s.yAxis}
                                    type="monotone"
                                    dataKey={`compare_${s.key}`}
                                    name={`compare_${s.key}`}
                                    stroke={s.color}
                                    strokeWidth={1.5}
                                    dot={false}
                                    strokeDasharray="4 2"
                                    connectNulls
                                />
                            ) : null,
                        )}

                        {/* Main series */}
                        {SERIES.map((s) =>
                            visible.has(s.key) ? (
                                <Line
                                    key={s.key}
                                    yAxisId={s.yAxis}
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
                        {notes?.map(({ date, note }) => (
                            <ReferenceLine
                                key={date}
                                x={date}
                                yAxisId="left"
                                stroke="#d4d4d8"
                                strokeDasharray="3 3"
                                strokeWidth={1}
                                label={(props: object) => (
                                        <NoteMarker
                                            {...(props as NoteMarkerProps)}
                                            note={note}
                                            onHoverChange={setHoveredNote}
                                        />
                                    )}
                            />
                        ))}
                    </LineChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
});

export { MultiSeriesLineChartInner as MultiSeriesLineChart };
