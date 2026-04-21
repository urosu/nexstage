import { cn } from '@/lib/utils';

export type WlClassifier = 'target' | 'peer' | 'period';
export type WlFilter = 'all' | 'winners' | 'losers';

export function classifierTooltip(
    classifier: WlClassifier,
    side: 'winner' | 'loser',
    targetRoas: number | null,
    peerAvgRoas: number | null,
): string {
    if (classifier === 'target') {
        const t = targetRoas?.toFixed(2) ?? '—';
        return side === 'winner'
            ? `Real ROAS ≥ target (${t}×)`
            : `Real ROAS < target (${t}×)`;
    }
    if (classifier === 'peer') {
        const avg = peerAvgRoas?.toFixed(2) ?? '—';
        return side === 'winner'
            ? `Real ROAS ≥ peer avg (${avg}×)`
            : `Real ROAS < peer avg (${avg}×)`;
    }
    return side === 'winner' ? 'Real ROAS improved vs previous period' : 'Real ROAS declined vs previous period';
}

interface WlFilterBarProps {
    filter: WlFilter;
    totalCount: number;
    filteredCount: number;
    activeClassifier: WlClassifier;
    hasTarget: boolean;
    targetRoas: number | null;
    peerAvgRoas: number | null;
    allLabel?: string;
    onFilterChange: (f: WlFilter) => void;
    onClassifierChange: (c: WlClassifier) => void;
}

export function WlFilterBar({
    filter,
    totalCount,
    filteredCount,
    activeClassifier,
    hasTarget,
    targetRoas,
    peerAvgRoas,
    allLabel = 'Show all',
    onFilterChange,
    onClassifierChange,
}: WlFilterBarProps) {
    const classifierOptions: { value: WlClassifier; label: string; disabled?: boolean }[] = [
        { value: 'target', label: 'vs Target',      disabled: !hasTarget },
        { value: 'peer',   label: 'vs Peer Avg' },
        { value: 'period', label: 'vs Prev Period' },
    ];

    return (
        <div className="flex items-center gap-2">
            <div className="flex items-center gap-1">
                {(['all', 'winners', 'losers'] as const).map(f => (
                    <button
                        key={f}
                        onClick={() => onFilterChange(f)}
                        className={cn(
                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            filter === f
                                ? f === 'winners'
                                    ? 'border-green-300 bg-green-50 text-green-700'
                                    : f === 'losers'
                                    ? 'border-red-300 bg-red-50 text-red-700'
                                    : 'border-primary bg-primary/10 text-primary'
                                : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 hover:text-zinc-700',
                        )}
                        title={
                            f === 'all'     ? allLabel :
                            f === 'winners' ? classifierTooltip(activeClassifier, 'winner', targetRoas, peerAvgRoas) :
                                              classifierTooltip(activeClassifier, 'loser',  targetRoas, peerAvgRoas)
                        }
                    >
                        {f === 'all' ? 'All' : f === 'winners' ? '🏆 Winners' : '📉 Losers'}
                    </button>
                ))}
                {filter !== 'all' && (
                    <span className="text-xs text-zinc-400">
                        {filteredCount} / {totalCount}
                    </span>
                )}
            </div>
            <select
                value={activeClassifier}
                onChange={e => onClassifierChange(e.target.value as WlClassifier)}
                className="rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs text-zinc-600 focus:outline-none focus:ring-1 focus:ring-primary"
                title="Classification method for Winners / Losers"
            >
                {classifierOptions.map(o => (
                    <option key={o.value} value={o.value} disabled={o.disabled}>
                        {o.label}{o.disabled ? ' (no target set)' : ''}
                    </option>
                ))}
            </select>
        </div>
    );
}
