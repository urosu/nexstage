import React from 'react';
import { cn } from '@/lib/utils';

interface Props {
    position: number | null;
    className?: string;
}

interface BucketConfig {
    label: string;
    className: string;
}

function bucketConfig(position: number): BucketConfig {
    if (position < 2)  return { label: '#1',     className: 'bg-green-100 text-green-700' };
    if (position < 4)  return { label: '#2–3',   className: 'bg-blue-100 text-blue-700' };
    if (position < 6)  return { label: '#4–5',   className: 'bg-blue-100 text-blue-700' };
    if (position < 11) return { label: '#6–10',  className: 'bg-blue-100 text-blue-700' };
    if (position < 21) return { label: '#11–20', className: 'bg-amber-100 text-amber-700' };
    if (position < 51) return { label: '#21–50', className: 'bg-zinc-100 text-zinc-500' };
    return              { label: '#51+',   className: 'bg-zinc-50 text-zinc-400' };
}

/**
 * Coloured chip showing the position bucket for a GSC query/page.
 *
 * Colour scheme per §M2: #1 green, #2–10 blue, #11–20 amber (striking distance), 21+ gray.
 *
 * @see PROGRESS.md §M2 Position bucket chip colors
 */
export function PositionBucketChip({ position, className }: Props) {
    if (position === null) return null;

    const config = bucketConfig(position);

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
