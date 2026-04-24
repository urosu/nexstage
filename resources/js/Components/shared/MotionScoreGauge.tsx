import { cn } from '@/lib/utils';
import { InfoTooltip } from './Tooltip';

export type MotionGrade = 'A' | 'B' | 'C' | 'D' | 'F' | null;
export type MotionVerdict = 'Scale' | 'Iterate' | 'Watch' | 'Kill' | null;

export interface MotionScore {
    hook:    MotionGrade;
    hold:    MotionGrade;
    click:   MotionGrade;
    convert: MotionGrade;
    profit:  MotionGrade;
    verdict: MotionVerdict;
}

interface Props {
    score: MotionScore | null;
    /** Show component labels (Hook / Hold / …) under the dots. Default: false. */
    showLabels?: boolean;
    /** Size variant. 'sm' = compact dots for table rows, 'md' = card view. */
    size?: 'sm' | 'md';
    className?: string;
}

const COMPONENTS: { key: keyof Omit<MotionScore, 'verdict'>; label: string; tooltip: string }[] = [
    {
        key:     'hook',
        label:   'Hook',
        tooltip: 'Thumbstop Ratio = 2-second video views ÷ impressions. How well the ad stops the scroll.',
    },
    {
        key:     'hold',
        label:   'Hold',
        tooltip: 'Hold Rate = 15-second views ÷ 2-second views. How well the ad keeps viewers engaged after the hook.',
    },
    {
        key:     'click',
        label:   'Click',
        tooltip: 'Thumbstop CTR = outbound clicks ÷ 2-second views (primary, video ads). Outbound CTR = outbound clicks ÷ impressions (fallback, static ads).',
    },
    {
        key:     'convert',
        label:   'Convert',
        tooltip: 'CVR = store orders ÷ clicks. How efficiently clicks become purchases (campaign-level proxy when only UTM data is available).',
    },
    {
        key:     'profit',
        label:   'Profit',
        tooltip: 'Real ROAS vs campaign target. A = ≥120% of target, F = <50% of target.',
    },
];

function gradeColor(grade: MotionGrade): string {
    if (grade === null) return 'bg-zinc-200';
    return {
        A: 'bg-emerald-500',
        B: 'bg-blue-400',
        C: 'bg-yellow-400',
        D: 'bg-orange-400',
        F: 'bg-red-500',
    }[grade] ?? 'bg-zinc-200';
}

function gradeTextColor(grade: MotionGrade): string {
    if (grade === null) return 'text-zinc-400';
    return {
        A: 'text-emerald-700',
        B: 'text-blue-700',
        C: 'text-yellow-700',
        D: 'text-orange-700',
        F: 'text-red-700',
    }[grade] ?? 'text-zinc-500';
}

export function MotionScoreGauge({ score, showLabels = false, size = 'md', className }: Props) {
    if (score === null) {
        return (
            <span className={cn('text-xs text-zinc-400', className)}>—</span>
        );
    }

    const dotSize = size === 'sm' ? 'h-2 w-2' : 'h-3 w-3';

    return (
        <div className={cn('flex items-end gap-1', className)}>
            {COMPONENTS.map(({ key, label, tooltip }) => {
                const grade = score[key];
                return (
                    <div key={key} className="flex flex-col items-center gap-0.5">
                        <InfoTooltip
                            content={
                                <div className="max-w-xs">
                                    <p className="font-semibold mb-0.5">{label}: {grade ?? '—'}</p>
                                    <p className="text-zinc-300">{tooltip}</p>
                                </div>
                            }
                        >
                            <div
                                className={cn(
                                    'rounded-full transition-colors',
                                    dotSize,
                                    gradeColor(grade),
                                    grade === null && 'opacity-50',
                                )}
                                aria-label={`${label}: ${grade ?? 'no data'}`}
                            />
                        </InfoTooltip>
                        {showLabels && (
                            <span className={cn('text-[9px] font-medium', gradeTextColor(grade))}>
                                {grade ?? '—'}
                            </span>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

/** Verdict pill: Scale / Iterate / Watch / Kill */
export function VerdictPill({ verdict, className }: { verdict: MotionVerdict; className?: string }) {
    if (verdict === null) return null;

    const styles: Record<NonNullable<MotionVerdict>, string> = {
        Scale:   'bg-emerald-100 text-emerald-800 border-emerald-200',
        Iterate: 'bg-blue-100   text-blue-800   border-blue-200',
        Watch:   'bg-yellow-100 text-yellow-800 border-yellow-200',
        Kill:    'bg-red-100    text-red-800    border-red-200',
    };

    return (
        <span className={cn(
            'inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
            styles[verdict],
            className,
        )}>
            {verdict}
        </span>
    );
}
