/**
 * Shared sync-health helpers used by StoreFilter, ScopeFilter, SEO property
 * pills, and Campaigns ad account pills. Also re-exported for use by
 * DataFreshness (page-header freshness badge).
 *
 * Two threshold sets, both keyed by integration type:
 *
 *   HEALTH_THRESHOLDS — drives the colored dots on pills/filters.
 *     Lenient: "is this integration still working?"
 *     store / ad_account: green < 4 h | amber 4–24 h | red > 24 h
 *     gsc:                green < 8 h | amber 8–48 h | red > 48 h
 *
 *   FRESHNESS_THRESHOLDS — drives the page-header badge (DataFreshness).
 *     Tighter: "is the data I'm looking at right now fresh?"
 *     store / ad_account: green < 2 h | amber 2–24 h | red > 24 h
 *     gsc:                green < 8 h | amber 8–48 h | red > 48 h
 *
 * Sync cadences (see routes/console.php):
 *   store      — hourly (PollStoreOrdersJob)
 *   ad_account — hourly with up to 20 min jitter (SyncAdInsightsJob)
 *   gsc        — every 6 h with up to 30 min jitter (SyncSearchConsoleJob)
 */

export type SyncIntegrationType = 'store' | 'ad_account' | 'gsc';

interface ThresholdSet {
    green: number; // ms
    amber: number; // ms
}

export const HEALTH_THRESHOLDS: Record<SyncIntegrationType, ThresholdSet> = {
    store:      { green:  4 * 3_600_000, amber: 24 * 3_600_000 },
    ad_account: { green:  4 * 3_600_000, amber: 24 * 3_600_000 },
    gsc:        { green:  8 * 3_600_000, amber: 48 * 3_600_000 },
};

export const FRESHNESS_THRESHOLDS: Record<SyncIntegrationType, ThresholdSet> = {
    store:      { green:  2 * 3_600_000, amber: 24 * 3_600_000 },
    ad_account: { green:  2 * 3_600_000, amber: 24 * 3_600_000 },
    gsc:        { green:  8 * 3_600_000, amber: 48 * 3_600_000 },
};

export function formatAge(lastSyncedAt: string | null): string {
    if (!lastSyncedAt) return 'never';
    const ms = Date.now() - new Date(lastSyncedAt).getTime();
    const minutes = Math.floor(ms / 60_000);
    if (minutes < 1)  return 'just now';
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24)   return `${hours}h ago`;
    return `${Math.floor(hours / 24)}d ago`;
}

export function syncDotClass(
    status: string,
    lastSyncedAt: string | null,
    type: SyncIntegrationType = 'store',
): string {
    if (status === 'error' || status === 'token_expired') return 'bg-red-400';
    if (status !== 'active') return 'bg-zinc-300';
    if (!lastSyncedAt)       return 'bg-amber-400';

    const { green, amber } = HEALTH_THRESHOLDS[type];
    const ageMs = Date.now() - new Date(lastSyncedAt).getTime();
    if (ageMs <= green) return 'bg-green-500';
    if (ageMs <= amber) return 'bg-amber-400';
    return 'bg-red-400';
}

export function syncDotTitle(status: string, lastSyncedAt: string | null): string {
    if (status === 'token_expired') return 'Token expired — reconnect required';
    if (status === 'error') return 'Sync error';
    if (!lastSyncedAt)      return 'Never synced';
    return `Updated ${formatAge(lastSyncedAt)}`;
}
