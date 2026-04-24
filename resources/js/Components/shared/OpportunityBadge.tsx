import React from 'react';
import { cn } from '@/lib/utils';

export type OpportunityType =
    | 'striking_distance'
    | 'rising'
    | 'leaking'
    | 'paid_organic_overlap';

interface Props {
    type: OpportunityType | string | null | undefined;
    className?: string;
}

interface BadgeConfig {
    label: string;
    className: string;
}

const BADGE: Record<string, BadgeConfig> = {
    striking_distance:    { label: 'Striking distance', className: 'bg-amber-100 text-amber-700' },
    rising:               { label: 'Rising',            className: 'bg-green-100 text-green-700' },
    leaking:              { label: 'Leaking CTR',       className: 'bg-red-100 text-red-700' },
    paid_organic_overlap: { label: 'Paid overlap',      className: 'bg-purple-100 text-purple-700' },
};

/**
 * Opportunity badge for a GSC query row.
 * Four types per §F17: striking_distance, rising, leaking, paid_organic_overlap.
 * Precedence (applied server-side): paid_organic_overlap > leaking > striking_distance > rising.
 *
 * @see PROGRESS.md §F17 Opportunity badge rules
 */
export function OpportunityBadge({ type, className }: Props) {
    if (!type) return null;

    const config = BADGE[type];
    if (!config) return null;

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-1.5 py-px text-[10px] font-medium',
                config.className,
                className,
            )}
        >
            {config.label}
        </span>
    );
}
