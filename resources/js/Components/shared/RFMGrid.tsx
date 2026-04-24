import { cn } from '@/lib/utils';

export interface RFMCell {
    fm: number;      // 1–5 (Frequency+Monetary, 5 = highest value)
    r: number;       // 1–5 (Recency, 5 = most recent)
    count: number;
    segment: string;
}

interface Props {
    cells: RFMCell[];
    onCellClick?: (cell: RFMCell) => void;
}

// Segment → color mapping (fixed per §M4)
const SEGMENT_COLORS: Record<string, string> = {
    'Champions':          'bg-green-600 text-white',
    'Loyal':              'bg-green-400 text-white',
    'Potential Loyalists':'bg-emerald-300 text-emerald-900',
    'New':                'bg-blue-400 text-white',
    'Promising':          'bg-blue-300 text-blue-900',
    'Needs Attention':    'bg-amber-300 text-amber-900',
    'About to Sleep':     'bg-orange-300 text-orange-900',
    "Can't Lose Them":    'bg-red-500 text-white',
    'At Risk':            'bg-red-400 text-white',
    'Hibernating':        'bg-zinc-300 text-zinc-700',
};

const R_LABELS: Record<number, string> = {
    5: '0–30d',
    4: '31–60d',
    3: '61–120d',
    2: '121–240d',
    1: '241d+',
};

const FM_LABELS: Record<number, string> = {
    5: 'Top 20%',
    4: '60–80%',
    3: '40–60%',
    2: '20–40%',
    1: 'Bottom 20%',
};

/**
 * 5×5 RFM grid per §M4. Axes: Recency (x, 1–5) × Frequency+Monetary (y, 1–5).
 * Fixed-size cells; 10 named segments via lookup table.
 * Click → cell detail (future: filtered customer list per Phase 4).
 */
export function RFMGrid({ cells, onCellClick }: Props) {
    // Build lookup map
    const cellMap = new Map<string, RFMCell>();
    for (const c of cells) {
        cellMap.set(`${c.fm}-${c.r}`, c);
    }

    const maxCount = Math.max(...cells.map((c) => c.count), 1);

    return (
        <div className="overflow-x-auto">
            <div className="min-w-[520px]">
                {/* X-axis label */}
                <div className="flex items-center mb-1 pl-20">
                    <span className="text-xs text-zinc-400 font-medium tracking-wide uppercase">
                        Recency (last purchase)
                    </span>
                </div>

                {/* Grid rows — FM 5→1 (top = highest value) */}
                {([5, 4, 3, 2, 1] as const).map((fm) => (
                    <div key={fm} className="flex items-center mb-0.5">
                        {/* Y-axis label */}
                        <div className="w-20 flex-shrink-0 text-right pr-2">
                            <span className="text-xs text-zinc-400">{FM_LABELS[fm]}</span>
                        </div>

                        {/* Cells — R 1→5 (left=oldest, right=most recent) */}
                        {([1, 2, 3, 4, 5] as const).map((r) => {
                            const cell = cellMap.get(`${fm}-${r}`);
                            const count = cell?.count ?? 0;
                            const segment = cell?.segment ?? '';
                            const colorClass = SEGMENT_COLORS[segment] ?? 'bg-zinc-100 text-zinc-400';
                            const opacity = count === 0 ? 'opacity-30' : '';

                            return (
                                <button
                                    key={r}
                                    type="button"
                                    onClick={() => cell && count > 0 && onCellClick?.(cell)}
                                    className={cn(
                                        'flex-1 h-14 mx-0.5 rounded flex flex-col items-center justify-center',
                                        'transition-opacity focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-primary',
                                        colorClass,
                                        opacity,
                                        count > 0 ? 'cursor-pointer hover:brightness-110' : 'cursor-default',
                                    )}
                                    title={`${segment}: ${count} customer${count !== 1 ? 's' : ''}`}
                                    disabled={count === 0}
                                >
                                    <span className="text-lg font-bold leading-none">{count}</span>
                                    <span className="text-[10px] leading-tight mt-0.5 text-center px-1 opacity-90 line-clamp-1">
                                        {segment}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                ))}

                {/* X-axis ticks */}
                <div className="flex mt-1 pl-20">
                    {([1, 2, 3, 4, 5] as const).map((r) => (
                        <div key={r} className="flex-1 text-center">
                            <span className="text-xs text-zinc-400">{R_LABELS[r]}</span>
                        </div>
                    ))}
                </div>

                {/* Y-axis title */}
                <div className="flex items-center mt-2 pl-20">
                    <span className="text-xs text-zinc-400 font-medium tracking-wide uppercase">
                        Frequency × Monetary (value tier)
                    </span>
                </div>
            </div>
        </div>
    );
}
