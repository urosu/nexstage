import React from 'react';
import { HelpCircle, ExternalLink } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';
import { SourceBadge } from '@/Components/shared/MetricCard';
import type { MetricSource } from '@/Components/shared/MetricCard';

/**
 * Data object describing how a metric is computed.
 *
 * Every MetricCard with a `whyThisNumber` prop renders a trigger icon that
 * opens this dialog. One consistent component across every page — callers
 * supply the data, not custom markup.
 *
 * @see PLANNING.md section 14.1
 */
export interface WhyThisNumberData {
    /** Modal heading, e.g. "How Real ROAS is computed" */
    title: string;
    /** Plain-text formula, e.g. "Real ROAS = Store Revenue ÷ Total Ad Spend" */
    formula: string;
    /** Source badges shown below the formula */
    sources: MetricSource[];
    /** The raw input values that feed the formula */
    rawValues?: Array<{
        label: string;
        value: string | null;
        source?: MetricSource;
    }>;
    /**
     * Values reported by other platforms for the same metric.
     * Shown when platforms disagree with the Real number.
     */
    conflicts?: Array<{
        platform: string;
        value: string;
        /** Short explanation of why the platform number differs, e.g. "Includes modeled iOS14 conversions" */
        note?: string;
    }>;
    /** Link to admin raw-data query tool for power users */
    viewRawLink?: string;
}

interface Props {
    data: WhyThisNumberData;
}

/**
 * Self-contained trigger + modal for explaining how a MetricCard value is
 * computed.
 *
 * Renders a small HelpCircle icon button inline. Clicking opens a Dialog with:
 *   - The formula used to compute the value
 *   - Source badges for the data sources involved
 *   - Raw input values that feed the formula
 *   - Platform conflicts (when platforms over-report vs Real)
 *   - Optional "View raw data" link to the admin query tool
 *
 * Callers pass a WhyThisNumberData object — the component owns the trigger
 * and the modal markup so the presentation stays uniform across every page.
 *
 * @see PLANNING.md section 14.1
 */
export function WhyThisNumber({ data }: Props) {
    return (
        <Dialog>
            <DialogTrigger
                aria-label={`Why this number: ${data.title}`}
                className="inline-flex items-center text-zinc-300 hover:text-zinc-500 transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-zinc-400 rounded"
            >
                <HelpCircle className="h-3.5 w-3.5" />
            </DialogTrigger>

            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{data.title}</DialogTitle>
                </DialogHeader>

                {/* Formula block */}
                <div className="rounded-lg border border-zinc-100 bg-zinc-50 px-4 py-3 font-mono text-sm text-zinc-800">
                    {data.formula}
                </div>

                {/* Source badges */}
                {data.sources.length > 0 && (
                    <div>
                        <p className="mb-1.5 th-label">
                            Data sources
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {data.sources.map((source) => (
                                <SourceBadge key={source} source={source} />
                            ))}
                        </div>
                    </div>
                )}

                {/* Raw input values */}
                {data.rawValues && data.rawValues.length > 0 && (
                    <div>
                        <p className="mb-1.5 th-label">
                            Input values
                        </p>
                        <div className="space-y-1">
                            {data.rawValues.map((rv, i) => (
                                <div
                                    key={i}
                                    className="flex items-center justify-between gap-4 rounded-md px-3 py-1.5 text-sm odd:bg-zinc-50"
                                >
                                    <div className="flex items-center gap-2 text-zinc-600">
                                        {rv.source && <SourceBadge source={rv.source} />}
                                        <span>{rv.label}</span>
                                    </div>
                                    <span className="font-medium tabular-nums text-zinc-900">
                                        {rv.value ?? '—'}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Platform conflicts */}
                {data.conflicts && data.conflicts.length > 0 && (
                    <div>
                        <p className="mb-1.5 th-label">
                            Platform disagreements
                        </p>
                        <div className="space-y-1.5">
                            {data.conflicts.map((c, i) => (
                                <div
                                    key={i}
                                    className="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-sm font-medium text-zinc-700">
                                            {c.platform} reports
                                        </span>
                                        <span className="font-semibold tabular-nums text-amber-700">
                                            {c.value}
                                        </span>
                                    </div>
                                    {c.note && (
                                        <p className="mt-0.5 text-xs text-zinc-500">{c.note}</p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* View raw data link */}
                {data.viewRawLink && (
                    <a
                        href={data.viewRawLink}
                        className="inline-flex items-center gap-1.5 text-xs text-primary hover:underline"
                        target="_blank"
                        rel="noreferrer"
                    >
                        <ExternalLink className="h-3 w-3" />
                        View raw data
                    </a>
                )}
            </DialogContent>
        </Dialog>
    );
}
