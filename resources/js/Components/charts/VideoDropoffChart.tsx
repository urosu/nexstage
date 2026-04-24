import {
    LineChart as RechartsLineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ReferenceLine,
    ResponsiveContainer,
} from 'recharts';
import { Film } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { VideoRetention } from '@/Components/shared/CreativeCard';

interface Props {
    holdRatePct: number | null;
    retention:   VideoRetention | null;
    className?:  string;
}

interface Point {
    label: string;
    value: number | null;
}

interface Cliff {
    label: string;
    drop:  number;
}

function buildCurve(holdRatePct: number | null, retention: VideoRetention | null): Point[] {
    return [
        { label: '3s',   value: 100 },
        { label: '15s',  value: holdRatePct },
        { label: '25%',  value: retention?.p25  ?? null },
        { label: '50%',  value: retention?.p50  ?? null },
        { label: '75%',  value: retention?.p75  ?? null },
        { label: '100%', value: retention?.p100 ?? null },
    ];
}

// Returns milestones where retention dropped >20 pts from the previous known point.
function findCliffs(points: Point[]): Cliff[] {
    const cliffs: Cliff[] = [];
    for (let i = 1; i < points.length; i++) {
        const prev = points[i - 1].value;
        const curr = points[i].value;
        if (prev != null && curr != null && prev - curr > 20) {
            cliffs.push({ label: points[i].label, drop: Math.round(prev - curr) });
        }
    }
    return cliffs;
}

export function VideoDropoffChart({ holdRatePct, retention, className }: Props) {
    if (retention === null && holdRatePct === null) {
        return (
            <div className={cn('flex items-center justify-center gap-2 text-sm text-zinc-400', className)}>
                <Film className="h-4 w-4" />
                <span>No video retention data</span>
            </div>
        );
    }

    const points = buildCurve(holdRatePct, retention);
    const cliffs = findCliffs(points);

    return (
        <div className={cn('h-[220px] w-full', className)}>
            <ResponsiveContainer width="100%" height="100%">
                <RechartsLineChart
                    data={points}
                    margin={{ top: 20, right: 16, left: 0, bottom: 0 }}
                >
                    <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" vertical={false} />
                    <XAxis
                        dataKey="label"
                        tickLine={false}
                        axisLine={false}
                        tick={{ fontSize: 11, fill: '#a1a1aa' }}
                    />
                    <YAxis
                        domain={[0, 100]}
                        tickLine={false}
                        axisLine={false}
                        tick={{ fontSize: 11, fill: '#a1a1aa' }}
                        tickFormatter={(v: number) => `${v}%`}
                        width={36}
                    />
                    <Tooltip
                        contentStyle={{
                            fontSize: 12,
                            borderRadius: 8,
                            border: '1px solid #e4e4e7',
                            boxShadow: '0 1px 8px rgba(0,0,0,0.08)',
                        }}
                        formatter={(value: unknown) => [
                            `${Number(value).toFixed(1)}%`,
                            'Viewers remaining',
                        ]}
                    />
                    {cliffs.map(({ label, drop }) => (
                        <ReferenceLine
                            key={label}
                            x={label}
                            stroke="#ef4444"
                            strokeDasharray="3 3"
                            strokeWidth={1.5}
                            label={{
                                value:    `−${drop}%`,
                                position: 'top',
                                fontSize: 10,
                                fill:     '#ef4444',
                            }}
                        />
                    ))}
                    <Line
                        type="monotone"
                        dataKey="value"
                        name="Retention"
                        stroke="var(--chart-1)"
                        strokeWidth={2}
                        dot={{ r: 4, fill: 'var(--chart-1)', strokeWidth: 0 }}
                        connectNulls
                    />
                </RechartsLineChart>
            </ResponsiveContainer>
        </div>
    );
}
