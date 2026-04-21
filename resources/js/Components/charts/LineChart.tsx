import React, { useLayoutEffect, useRef, useMemo, useState } from 'react';
import {
    LineChart as RechartsLineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ReferenceLine,
} from 'recharts';
import { formatCurrency, formatDate, formatNumber, type Granularity } from '@/lib/formatters';

export interface ChartDataPoint {
    date: string;
    value: number;
    compareValue?: number;
}

interface LineChartProps {
    data: ChartDataPoint[];
    granularity: Granularity;
    currency?: string;
    timezone?: string;
    comparisonData?: ChartDataPoint[];
    /** Label for the primary series */
    seriesLabel?: string;
    /** Label for the comparison series */
    compareLabel?: string;
    /** When true, Y-axis uses formatNumber instead of formatCurrency */
    valueType?: 'currency' | 'number' | 'percent';
    /** Annotate specific dates with a subtle reference line + amber marker */
    notes?: Array<{ date: string; note: string }>;
    loading?: boolean;
    className?: string;
}

function formatYAxis(
    value: number,
    valueType: 'currency' | 'number' | 'percent',
    currency: string,
): string {
    if (valueType === 'currency') return formatCurrency(value, currency, true);
    if (valueType === 'percent') return `${value.toFixed(1)}%`;
    return formatNumber(value, true);
}

const COLORS = {
    primary: 'var(--chart-1)',  // indigo — primary series
    compare: 'var(--chart-5)',  // violet — comparison series (dash pattern also differentiates)
};

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

const LineChartSkeleton = () => (
    <div className="h-full w-full animate-pulse rounded-lg bg-zinc-100" />
);

const LineChartWrapper = React.memo(function LineChartWrapper({
    data,
    granularity,
    currency = 'EUR',
    timezone,
    comparisonData,
    seriesLabel = 'Current',
    compareLabel = 'Previous',
    valueType = 'currency',
    notes,
    loading = false,
    className,
}: LineChartProps) {
    const merged = useMemo(() => {
        if (!comparisonData?.length) return data;
        return data.map((point, i) => ({
            ...point,
            compareValue: comparisonData[i]?.value ?? null,
        }));
    }, [data, comparisonData]);

    const [hoveredNote, setHoveredNote] = useState<{ note: string; x: number; y: number } | null>(null);

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

    if (loading) return <LineChartSkeleton />;

    return (
        <div ref={containerRef} className={`relative ${className ?? 'h-64 w-full'}`}>
            {hoveredNote && (
                <div
                    className="pointer-events-none absolute z-10 max-w-[220px] -translate-x-1/2 -translate-y-full rounded border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 shadow-md"
                    style={{ left: hoveredNote.x, top: hoveredNote.y - 10 }}
                >
                    {hoveredNote.note}
                </div>
            )}
            {chartSize && <RechartsLineChart
                    width={chartSize.w}
                    height={chartSize.h}
                    data={merged}
                    margin={{ top: 4, right: 8, left: 0, bottom: 0 }}
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
                    <YAxis
                        tickLine={false}
                        axisLine={false}
                        tick={{ fontSize: 11, fill: '#a1a1aa' }}
                        tickFormatter={(v) => formatYAxis(v, valueType, currency)}
                        width={60}
                    />
                    <Tooltip
                        contentStyle={{
                            fontSize: 12,
                            borderRadius: 8,
                            border: '1px solid #e4e4e7',
                            boxShadow: '0 1px 8px rgba(0,0,0,0.08)',
                        }}
                        formatter={(value: unknown, name: unknown) => {
                            const num = Number(value);
                            const label = String(name ?? '');
                            return [
                                valueType === 'currency'
                                    ? formatCurrency(num, currency)
                                    : valueType === 'percent'
                                        ? `${num.toFixed(2)}%`
                                        : formatNumber(num),
                                label,
                            ] as [string, string];
                        }}
                        labelFormatter={(label) => formatDate(String(label), granularity, timezone)}
                    />
                    {comparisonData?.length ? <Legend wrapperStyle={{ fontSize: 12 }} /> : null}
                    {comparisonData?.length && (
                        <Line
                            type="monotone"
                            dataKey="compareValue"
                            name={compareLabel}
                            stroke={COLORS.compare}
                            strokeWidth={1.5}
                            dot={false}
                            strokeDasharray="4 2"
                            connectNulls
                        />
                    )}
                    <Line
                        type="monotone"
                        dataKey="value"
                        name={seriesLabel}
                        stroke={COLORS.primary}
                        strokeWidth={2}
                        dot={false}
                        connectNulls
                    />
                    {notes?.map(({ date, note }) => (
                        <ReferenceLine
                            key={date}
                            x={date}
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
            </RechartsLineChart>}
        </div>
    );
});

export { LineChartWrapper as LineChart };
