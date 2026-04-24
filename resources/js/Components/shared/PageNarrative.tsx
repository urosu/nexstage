import React from 'react';
import { cn } from '@/lib/utils';

interface PageNarrativeProps {
    /**
     * One-sentence server-generated narrative, e.g.:
     * "Revenue €4,230 (+12% vs last Wednesday); ROAS 2.4x — your best driver today is organic."
     * Null/undefined → renders nothing (don't show an empty bar).
     */
    text: string | null | undefined;
    className?: string;
}

/**
 * One-sentence page narrative rendered just below the page title.
 * Text is generated server-side by NarrativeTemplateService and passed via Inertia props.
 * Tone: terse, direct, action-oriented — senior operator pointing at screen, not chatbot.
 *
 * @see PROGRESS.md Phase 3.1 — Page narrative header primitive
 * @see PROGRESS.md PageNarrative template examples
 */
export function PageNarrative({ text, className }: PageNarrativeProps) {
    if (!text) return null;

    return (
        <p
            className={cn(
                'text-sm text-zinc-600 leading-snug border-l-2 border-zinc-300 pl-3',
                className,
            )}
        >
            {text}
        </p>
    );
}
