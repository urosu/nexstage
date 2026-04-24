import { useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { FRESHNESS_THRESHOLDS, formatAge } from '@/lib/syncStatus';
import type { PageProps, IntegrationFreshness } from '@/types';

type FreshnessLevel = 'green' | 'amber' | 'red';

function computeLevel(integration: IntegrationFreshness): FreshnessLevel {
    if (integration.status === 'error' || integration.status === 'token_expired') return 'red';
    if (!integration.last_synced_at)    return 'amber';

    const { green, amber } = FRESHNESS_THRESHOLDS[integration.type] ?? FRESHNESS_THRESHOLDS.store;
    const ageMs = Date.now() - new Date(integration.last_synced_at).getTime();
    if (ageMs <= green) {
        // Active sync failures or a stuck historical import both degrade to amber.
        if ((integration.consecutive_sync_failures ?? 0) > 0) return 'amber';
        if (integration.historical_import_status === 'failed')  return 'amber';
        return 'green';
    }
    if (ageMs <= amber) return 'amber';
    return 'red';
}

const DOT_CLASS: Record<FreshnessLevel, string> = {
    green: 'bg-green-500',
    amber: 'bg-amber-400',
    red:   'bg-red-500',
};

const CHECK_ICON: Record<FreshnessLevel, string> = {
    green: '✓',
    amber: '⚠',
    red:   '✗',
};

const TEXT_COLOR: Record<FreshnessLevel, string> = {
    green: 'text-green-700',
    amber: 'text-amber-600',
    red:   'text-red-600',
};

/**
 * Per-page data freshness indicator.
 *
 * variant="dot" (default) — compact dot + text used in PageHeader.
 * variant="strip" — full-width row of per-integration pills, used at the top
 *   of the Home page (§ Home spec, Status strip section).
 *
 * Reads integrations_freshness from shared Inertia props (HandleInertiaRequests::share()).
 * Safe to always mount — renders null when no integrations connected.
 *
 * @see PLANNING.md section 14.2
 */
export function DataFreshness({ variant = 'dot' }: { variant?: 'dot' | 'strip' }) {
    const integrations = (usePage<PageProps>().props.integrations_freshness ?? []) as IntegrationFreshness[];

    const { overallLevel, summary } = useMemo(() => {
        if (integrations.length === 0) {
            return { overallLevel: 'green' as FreshnessLevel, summary: '' };
        }

        const levels = integrations.map(computeLevel);
        const overall: FreshnessLevel = levels.includes('red')
            ? 'red'
            : levels.includes('amber')
            ? 'amber'
            : 'green';

        const parts = integrations.map((int) => {
            const level = computeLevel(int);
            return `${int.label} ${CHECK_ICON[level]} ${formatAge(int.last_synced_at)}`;
        });

        return { overallLevel: overall, summary: parts.join('  |  ') };
    }, [integrations]);

    if (integrations.length === 0) return null;

    if (variant === 'strip') {
        return (
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-zinc-100 bg-zinc-50 px-3 py-2 text-xs">
                {integrations.map((int, i) => {
                    const level = computeLevel(int);
                    const ageLabel = level === 'green'
                        ? formatAge(int.last_synced_at)
                        : int.status === 'token_expired'
                        ? 'Token expired'
                        : (int.consecutive_sync_failures ?? 0) > 0
                        ? `Failing (last ok ${formatAge(int.last_synced_at)})`
                        : int.historical_import_status === 'failed'
                        ? 'Import failed'
                        : formatAge(int.last_synced_at);

                    return (
                        <span key={i} className="inline-flex items-center gap-1.5">
                            <span className={cn('h-2 w-2 rounded-full flex-shrink-0', DOT_CLASS[level])} />
                            <span className="text-zinc-600 font-medium">{int.label}</span>
                            <span className={cn('tabular-nums', TEXT_COLOR[level])}>{ageLabel}</span>
                        </span>
                    );
                })}
            </div>
        );
    }

    // Use the most-recently-synced integration for the inline age label.
    const inlineText = overallLevel === 'green'
        ? 'Up to date'
        : overallLevel === 'amber'
        ? 'Sync issue'
        : 'Sync error';

    return (
        <div
            className="group/freshness relative inline-flex items-center gap-1.5 text-xs cursor-default select-none"
            title={summary}
            aria-label={`Data freshness: ${inlineText}`}
        >
            {/* Pulsing dot for green, static for amber/red */}
            <span className="relative flex h-2 w-2">
                {overallLevel === 'green' && (
                    <span
                        className={cn(
                            'absolute inline-flex h-full w-full animate-ping rounded-full opacity-60',
                            DOT_CLASS.green,
                        )}
                    />
                )}
                <span
                    className={cn(
                        'relative inline-flex h-2 w-2 rounded-full',
                        DOT_CLASS[overallLevel],
                    )}
                />
            </span>

            <span className={cn('font-medium', TEXT_COLOR[overallLevel])}>
                {inlineText}
            </span>

            {/* Tooltip: per-integration breakdown */}
            <div
                className="pointer-events-none invisible absolute top-full right-0 z-50 mt-2 w-max max-w-xs rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs leading-relaxed text-zinc-600 opacity-0 shadow-lg transition-opacity duration-150 group-hover/freshness:visible group-hover/freshness:opacity-100"
                role="tooltip"
            >
                <div className="space-y-1">
                    {integrations.map((int, i) => {
                        const level = computeLevel(int);
                        return (
                            <div key={i} className="flex items-center justify-between gap-4">
                                <span className="text-zinc-500">{int.label}</span>
                                <span className={cn('font-medium tabular-nums', TEXT_COLOR[level])}>
                                    {level === 'green'
                                        ? '✓ Up to date'
                                        : int.status === 'token_expired'
                                        ? '✗ Token expired'
                                        : (int.consecutive_sync_failures ?? 0) > 0
                                        ? `⚠ Sync failing (last ok ${formatAge(int.last_synced_at)})`
                                        : int.historical_import_status === 'failed'
                                        ? '⚠ Import failed'
                                        : `${CHECK_ICON[level]} ${formatAge(int.last_synced_at)}`}
                                </span>
                            </div>
                        );
                    })}
                </div>
                {/* Caret */}
                <span className="absolute bottom-full right-3 border-4 border-transparent border-b-zinc-200" />
                <span className="absolute bottom-full right-3 border-[3px] border-transparent border-b-white" style={{ marginBottom: '-1px' }} />
            </div>
        </div>
    );
}
