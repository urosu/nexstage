import { ArrowRight, Lightbulb } from 'lucide-react';
import { formatCurrency } from '@/lib/formatters';
import { cn } from '@/lib/utils';

// ── Types ─────────────────────────────────────────────────────────────────────

export interface OpportunityItem {
    id?: number | null;
    type: string;
    priority: number;
    title: string;
    body: string;
    impact_estimate: number | null;
    impact_currency: string | null;
    target_url: string | null;
}

interface Props {
    items: OpportunityItem[];
    currency: string;
    className?: string;
}

// ── Impact chip ──────────────────────────────────────────────────────────────

function ImpactChip({ estimate, currency }: { estimate: number | null; currency: string }) {
    if (estimate === null || estimate <= 0) return null;
    // ±30% range per §F16
    const low  = Math.round(estimate * 0.7);
    const high = Math.round(estimate * 1.3);
    return (
        <span className="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700">
            ~{formatCurrency(low, currency, true)}–{formatCurrency(high, currency, true)}
        </span>
    );
}

// ── Type icon map ────────────────────────────────────────────────────────────

const TYPE_ACCENT: Record<string, string> = {
    channel_reallocation:   'border-l-violet-400',
    organic_to_paid:        'border-l-blue-400',
    paid_to_organic:        'border-l-green-400',
    striking_distance:      'border-l-blue-400',
    site_health_revenue_risk: 'border-l-red-400',
    unprofitable_product:   'border-l-red-400',
    cohort_channel_quality: 'border-l-amber-400',
};

// ── Main component ────────────────────────────────────────────────────────────

/**
 * Opportunities sidebar — server-generated action items from the recommendations
 * table + inline channel-reallocation and striking-distance items.
 *
 * All copy is templated server-side. AI narrative deferred to Phase 4.
 * Items are sorted by priority ASC (done by controller).
 */
export function OpportunitiesSidebar({ items, currency, className }: Props) {
    return (
        <div className={cn('w-full lg:w-80 shrink-0', className)}>
            <div className="rounded-xl border border-zinc-200 bg-white">
                <div className="flex items-center gap-2 border-b border-zinc-100 px-4 py-3">
                    <Lightbulb className="h-4 w-4 text-amber-500" />
                    <span className="text-sm font-medium text-zinc-700">Opportunities</span>
                    {items.length > 0 && (
                        <span className="ml-auto rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500">
                            {items.length}
                        </span>
                    )}
                </div>

                {items.length === 0 ? (
                    <div className="px-4 py-6 text-center">
                        <p className="text-xs text-zinc-400">
                            No opportunities detected yet — connect more integrations to surface recommendations.
                        </p>
                    </div>
                ) : (
                    <div className="divide-y divide-zinc-100">
                        {items.map((item, i) => {
                            const accentCls = TYPE_ACCENT[item.type] ?? 'border-l-zinc-300';
                            return (
                                <div
                                    key={item.id ?? `inline-${i}`}
                                    className={cn(
                                        'border-l-2 px-4 py-3',
                                        accentCls,
                                    )}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="text-xs font-medium text-zinc-800 leading-snug">
                                            {item.title}
                                        </p>
                                        {item.target_url && (
                                            <a
                                                href={item.target_url}
                                                className="mt-0.5 shrink-0 text-zinc-400 hover:text-zinc-700"
                                                aria-label="View details"
                                            >
                                                <ArrowRight className="h-3.5 w-3.5" />
                                            </a>
                                        )}
                                    </div>
                                    <p className="mt-1 text-[11px] leading-relaxed text-zinc-500">
                                        {item.body}
                                    </p>
                                    {item.impact_estimate != null && (
                                        <div className="mt-2">
                                            <ImpactChip
                                                estimate={item.impact_estimate}
                                                currency={item.impact_currency ?? currency}
                                            />
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}
