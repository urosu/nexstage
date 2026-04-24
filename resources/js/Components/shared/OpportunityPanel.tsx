import React from 'react';
import { TrendingUp, AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface OpportunityItem {
    type: string;
    count: number;
    label: string;
}

interface Props {
    trendingUp: OpportunityItem[];
    needsAttention: OpportunityItem[];
    className?: string;
}

/**
 * Two-column opportunities panel shown at the top of the Organic page.
 * Left: trending-up items (rising, striking distance). Right: needs-attention items (leaking, worsening).
 * Renders nothing when both lists are empty.
 *
 * Each item is one line: bold count + descriptive label (Search Console Insights style).
 *
 * @see PROGRESS.md Phase 3.3 §F14, §F15
 */
export function OpportunityPanel({ trendingUp, needsAttention, className }: Props) {
    if (trendingUp.length === 0 && needsAttention.length === 0) return null;

    return (
        <div className={cn('mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2', className)}>
            {/* Trending up */}
            <div className="rounded-xl border border-zinc-200 bg-white p-4">
                <div className="mb-3 flex items-center gap-1.5">
                    <TrendingUp className="h-4 w-4 text-green-600" />
                    <span className="text-sm font-semibold text-zinc-800">Trending up</span>
                </div>
                {trendingUp.length === 0 ? (
                    <p className="text-xs text-zinc-400">No positive signals in the selected period.</p>
                ) : (
                    <ul className="space-y-2">
                        {trendingUp.map((item) => (
                            <li key={item.type} className="flex items-start gap-2 text-sm text-zinc-600">
                                <span className="shrink-0 font-semibold tabular-nums text-zinc-900">
                                    {item.count}
                                </span>
                                <span>{item.label.replace(/^\d+ /, '')}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {/* Needs attention */}
            <div className="rounded-xl border border-zinc-200 bg-white p-4">
                <div className="mb-3 flex items-center gap-1.5">
                    <AlertTriangle className="h-4 w-4 text-amber-500" />
                    <span className="text-sm font-semibold text-zinc-800">Needs attention</span>
                </div>
                {needsAttention.length === 0 ? (
                    <p className="text-xs text-zinc-400">Nothing flagged — looking good.</p>
                ) : (
                    <ul className="space-y-2">
                        {needsAttention.map((item) => (
                            <li key={item.type} className="flex items-start gap-2 text-sm text-zinc-600">
                                <span className="shrink-0 font-semibold tabular-nums text-zinc-900">
                                    {item.count}
                                </span>
                                <span>{item.label.replace(/^\d+ /, '')}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
