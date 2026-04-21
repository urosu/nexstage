import React, { useLayoutEffect, useRef, useState, useMemo } from 'react';
import {
    BarChart as RechartsBarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    Cell,
    ReferenceLine,
} from 'recharts';
import { formatCurrency, formatDate, formatNumber, type Granularity } from '@/lib/formatters';

export interface BarChartDataPoint {
    date: string;
    value: number;
    compareValue?: number;
}

export interface BarSeriesConfig {
    dataKey: string;
    name: string;
    color: string;
    stackId?: string;
}

interface BarChartProps {
    data: object[];
    granularity: Granularity;
    currency?: string;
    timezone?: string;
    comparisonData?: BarChartDataPoint[];
    seriesLabel?: string;
    compareLabel?: string;
    valueType?: 'currency' | 'number' | 'percent';
    /** Multi-series stacked config. When provided, overrides the default value/compareValue rendering. */
    series?: BarSeriesConfig[];
    /** Callback when a bar is clicked (e.g. drill into hourly) */
    onBarClick?: (dataPoint: BarChartDataPoint) => void;
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
    primary: '#4f46e5',
    primaryLight: '#6366f1',
    compare: '#c7d2fe',
};

interface NoteMarkerProps {
    viewBox?: { x: number; y: number; width: number; height: number };
    note: string;
}

function NoteMarker({ viewBox, note }: NoteMarkerProps) {
    if (!viewBox) return null;
    const cx = viewBox.x + viewBox.width / 2;
    const cy = viewBox.y;
    return (
        <g>
            <title>{note}</title>
            <circle cx={cx} cy={cy + 6} r={3.5} fill="#f59e0b" />
        </g>
    );
}

const BarChartSkeleton = () => (
    <div className="h-full w-full animate-pulse rounded-lg bg-zinc-100" />
);

const BarChartWrapper = React.memo(function BarChartWrapper({
    data,
    granularity,
    currency = 'EUR',
    timezone,
    comparisonData,
    seriesLabel = 'Current',
    compareLabel = 'Previous',
    valueType = 'currency',
    series,
    onBarClick,
    notes,
    loading = false,
    className,
}: BarChartProps) {
    const merged = useMemo(() => {
        if (series || !comparisonData?.length) return data;
        return data.map((point, i) => ({
            ...point,
            compareValue: comparisonData[i]?.value ?? null,
        }));
    }, [data, comparisonData, series]);

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

    if (loading) return <BarChartSkeleton />;

    const hasComparison = !series && !!comparisonData?.length;

    return (
        <div ref={containerRef} className={className ?? 'h-64 w-full'}>
            {chartSize && <RechartsBarChart
                    width={chartSize.w}
                    height={chartSize.h}
                    data={merged}
                    margin={{ top: 4, right: 8, left: 0, bottom: 0 }}
                    barCategoryGap="30%"
                    barGap={2}
                    onClick={
                        onBarClick
                            ? (payload: Record<string, unknown>) => {
                                  const ap = payload?.activePayload as { payload: BarChartDataPoint }[] | undefined;
                                  if (ap?.[0]) {
                                      onBarClick(ap[0].payload);
                                  }
                              }
                            : undefined
                    }
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
                        cursor={{ fill: '#f4f4f5' }}
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
                    {(hasComparison || series) && <Legend wrapperStyle={{ fontSize: 12 }} />}
                    {series
                        ? series.map((s) => (
                            <Bar
                                key={s.dataKey}
                                dataKey={s.dataKey}
                                name={s.name}
                                fill={s.color}
                                radius={[3, 3, 0, 0]}
                                stackId={s.stackId}
                            />
                          ))
                        : (
                            <>
                                {hasComparison && (
                                    <Bar
                                        dataKey="compareValue"
                                        name={compareLabel}
                                        fill={COLORS.compare}
                                        radius={[3, 3, 0, 0]}
                                    />
                                )}
                                <Bar
                                    dataKey="value"
                                    name={seriesLabel}
                                    fill={COLORS.primary}
                                    radius={[3, 3, 0, 0]}
                                    style={onBarClick ? { cursor: 'pointer' } : undefined}
                                >
                                    {merged.map((_, index) => (
                                        <Cell
                                            key={index}
                                            fill={COLORS.primary}
                                            className="hover:brightness-110 transition-all"
                                        />
                                    ))}
                                </Bar>
                            </>
                          )
                    }
                    {notes?.map(({ date, note }) => (
                        <ReferenceLine
                            key={date}
                            x={date}
                            stroke="#d4d4d8"
                            strokeDasharray="3 3"
                            strokeWidth={1}
                            label={(props: object) => <NoteMarker {...(props as NoteMarkerProps)} note={note} />}
                        />
                    ))}
            </RechartsBarChart>}
        </div>
    );
});

export { BarChartWrapper as BarChart };
