import { router, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { syncDotClass, syncDotTitle } from '@/lib/syncStatus';
import { DateRangePicker } from './DateRangePicker';
import type { PageProps, Store } from '@/types';

/**
 * A connected integration (ad account or GSC property) available for scope
 * filtering. Pages that surface per-integration data pass these via props.
 */
export interface ScopeIntegration {
    id: number;
    /** Display name, e.g. "Main Store — Facebook" */
    label: string;
    /** 'facebook' | 'google' | 'gsc' */
    platform: string;
    status: string;
    last_synced_at: string | null;
}

interface Props {
    /** IDs of currently selected stores; empty array = all stores. */
    selectedStoreIds: number[];
    /** IDs of currently selected integrations (ad account IDs); empty = all. */
    selectedIntegrationIds?: number[];
    /**
     * Available integrations to show as pills. When omitted or empty, the
     * integration row is hidden entirely. Pages that show cross-channel data
     * pass their ad_accounts here; pure store pages omit it.
     */
    integrations?: ScopeIntegration[];
    /** Show the DateRangePicker. Default: true. */
    showDatePicker?: boolean;
    /**
     * Active attribution model for paid destinations. When provided, a
     * First Touch / Last Touch toggle is rendered in a third row.
     */
    attributionModel?: 'first_touch' | 'last_touch';
    onAttributionModelChange?: (model: 'first_touch' | 'last_touch') => void;
    /**
     * When true, renders a disabled "Attribution Window: All Time" control with
     * a tooltip. Phase 3.5 placeholder — full implementation deferred to Phase 4.
     */
    showAttributionWindow?: boolean;
    /**
     * When true, renders a disabled "Cash / Accrual" toggle.
     * Accrual mode is deferred until attribution_last_touch.timestamp coverage is verified.
     * See: PROGRESS.md Phase 4.1 decision — placeholder only.
     */
    showAccrualCash?: boolean;
}

/**
 * Unified scope filter — store pills + optional integration pills +
 * DateRangePicker — in a single sticky component.
 *
 * Replaces the separate <StoreFilter /> + <DateRangePicker /> pair used on
 * legacy pages. Phase 1.6 pages should use this component instead.
 *
 * All changes are pushed into URL params immediately so links remain shareable.
 * The integration_ids param mirrors the store_ids convention (comma-separated
 * list, absent when all are selected).
 *
 * @see PLANNING.md section 8 (Scope Filtering)
 */
export function ScopeFilter({
    selectedStoreIds,
    selectedIntegrationIds = [],
    integrations = [],
    showDatePicker = true,
    attributionModel,
    onAttributionModelChange,
    showAttributionWindow = false,
    showAccrualCash = false,
}: Props) {
    const stores = (usePage<PageProps>().props.stores ?? []) as Store[];

    const hasMultipleStores     = stores.length > 1;
    const hasIntegrations       = integrations.length > 0;
    const allStoresSelected     = selectedStoreIds.length === 0;
    const allIntegrationsSelected = selectedIntegrationIds.length === 0;

    // ── URL helpers ────────────────────────────────────────────────────────

    function navigate(patch: Record<string, string | null>): void {
        const params = new URLSearchParams(window.location.search);
        params.delete('page');

        for (const [key, value] of Object.entries(patch)) {
            if (value === null || value === '') {
                params.delete(key);
            } else {
                params.set(key, value);
            }
        }

        router.get(window.location.pathname, Object.fromEntries(params), {
            replace: true,
            preserveScroll: true,
        });
    }

    // ── Store selection ────────────────────────────────────────────────────

    function applyStoreSelection(ids: number[]): void {
        const allSelected = ids.length === 0 || ids.length === stores.length;
        navigate({ store_ids: allSelected ? null : ids.join(',') });
    }

    function toggleStore(storeId: number): void {
        const next = selectedStoreIds.includes(storeId)
            ? selectedStoreIds.filter((id) => id !== storeId)
            : [...selectedStoreIds, storeId];
        applyStoreSelection(next.length === stores.length ? [] : next);
    }

    // ── Integration selection ───────────────────────────────────────────────

    function applyIntegrationSelection(ids: number[]): void {
        const allSelected = ids.length === 0 || ids.length === integrations.length;
        navigate({ integration_ids: allSelected ? null : ids.join(',') });
    }

    function toggleIntegration(integrationId: number): void {
        const next = selectedIntegrationIds.includes(integrationId)
            ? selectedIntegrationIds.filter((id) => id !== integrationId)
            : [...selectedIntegrationIds, integrationId];
        applyIntegrationSelection(next.length === integrations.length ? [] : next);
    }

    const hasAttributionRow = attributionModel !== undefined || showAttributionWindow || showAccrualCash;

    if (stores.length === 0 && !hasIntegrations && !showDatePicker && !hasAttributionRow) return null;

    return (
        <div className="mb-6 space-y-2">
            {/* Row 1: store pills + date range picker */}
            <div className="flex flex-wrap items-center gap-2">
                {/* Store pills — hidden when only one store (no useful toggle) */}
                {hasMultipleStores && (
                    <>
                        <button
                            onClick={() => applyStoreSelection([])}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                allStoresSelected
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                            )}
                        >
                            All stores
                        </button>
                        {stores.map((store) => {
                            const active = selectedStoreIds.includes(store.id);
                            return (
                                <button
                                    key={store.id}
                                    onClick={() => toggleStore(store.id)}
                                    className={cn(
                                        'flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                        active
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                                    )}
                                    title={syncDotTitle(store.status, store.last_synced_at)}
                                >
                                    <span
                                        className={cn(
                                            'h-1.5 w-1.5 shrink-0 rounded-full',
                                            syncDotClass(store.status, store.last_synced_at, 'store'),
                                        )}
                                    />
                                    {store.name}
                                </button>
                            );
                        })}

                        {/* Separator before integrations / date picker */}
                        {(hasIntegrations || showDatePicker) && (
                            <span className="h-4 w-px shrink-0 bg-zinc-200" />
                        )}
                    </>
                )}

                {/* Date range picker — right side */}
                {showDatePicker && (
                    <div className="ml-auto">
                        <DateRangePicker />
                    </div>
                )}
            </div>

            {/* Row 2: integration pills (ad accounts / GSC) — only when provided */}
            {hasIntegrations && (
                <div className="flex flex-wrap items-center gap-2">
                    <button
                        onClick={() => applyIntegrationSelection([])}
                        className={cn(
                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            allIntegrationsSelected
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                        )}
                    >
                        All platforms
                    </button>
                    {integrations.map((integration) => {
                        const active = allIntegrationsSelected || selectedIntegrationIds.includes(integration.id);
                        return (
                            <button
                                key={integration.id}
                                onClick={() => toggleIntegration(integration.id)}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium capitalize transition-colors',
                                    active
                                        ? 'border-primary bg-primary/10 text-primary'
                                        : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                                )}
                                title={syncDotTitle(integration.status, integration.last_synced_at)}
                            >
                                <span
                                    className={cn(
                                        'h-1.5 w-1.5 shrink-0 rounded-full',
                                        syncDotClass(integration.status, integration.last_synced_at, integration.platform === 'gsc' ? 'gsc' : 'ad_account'),
                                    )}
                                />
                                {integration.label}
                            </button>
                        );
                    })}
                </div>
            )}

            {/* Row 3: attribution model toggle + window placeholder (paid destinations only) */}
            {hasAttributionRow && (
                <div className="flex flex-wrap items-center gap-3">
                    {/* Attribution model toggle */}
                    {attributionModel !== undefined && onAttributionModelChange && (
                        <div className="flex items-center gap-1">
                            <span className="text-[10px] uppercase tracking-wide text-zinc-400 mr-1">Attribution</span>
                            {(['last_touch', 'first_touch'] as const).map((model) => (
                                <button
                                    key={model}
                                    onClick={() => onAttributionModelChange(model)}
                                    className={cn(
                                        'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                        attributionModel === model
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                                    )}
                                >
                                    {model === 'last_touch' ? 'Last Touch' : 'First Touch'}
                                </button>
                            ))}
                        </div>
                    )}

                    {/* Attribution window — placeholder, full implementation deferred */}
                    {showAttributionWindow && (
                        <div
                            className="flex items-center gap-1.5 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs text-zinc-400 cursor-not-allowed select-none"
                            title="Attribution window filtering coming in a future update"
                        >
                            Window: All Time
                        </div>
                    )}

                    {/* Accrual / Cash toggle — placeholder per Phase 4.1 decision */}
                    {showAccrualCash && (
                        <div
                            className="flex items-center gap-1 cursor-not-allowed select-none"
                            title="Accrual mode coming once attribution coverage is verified."
                        >
                            <span className="text-[10px] uppercase tracking-wide text-zinc-400 mr-1">Mode</span>
                            {(['Cash', 'Accrual'] as const).map((mode) => (
                                <div
                                    key={mode}
                                    className={cn(
                                        'rounded-full border px-3 py-1 text-xs font-medium',
                                        mode === 'Cash'
                                            ? 'border-zinc-300 bg-zinc-100 text-zinc-500'
                                            : 'border-zinc-200 bg-white text-zinc-300',
                                    )}
                                >
                                    {mode}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Parse scope filter params from URL search string.
 * Returns normalised arrays safe to pass to ScopeFilter and backend requests.
 */
export function parseScopeFilterParams(search: string): {
    selectedStoreIds: number[];
    selectedIntegrationIds: number[];
} {
    const params = new URLSearchParams(search);

    const storeParam = params.get('store_ids');
    const selectedStoreIds = storeParam
        ? storeParam.split(',').map(Number).filter(Boolean)
        : [];

    const integrationParam = params.get('integration_ids');
    const selectedIntegrationIds = integrationParam
        ? integrationParam.split(',').map(Number).filter(Boolean)
        : [];

    return { selectedStoreIds, selectedIntegrationIds };
}
