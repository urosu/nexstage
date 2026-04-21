import { router, usePage } from '@inertiajs/react';
import type { Store, PageProps } from '@/types';
import { cn } from '@/lib/utils';
import { syncDotClass, syncDotTitle } from '@/lib/syncStatus';

interface Props {
    /** IDs of currently selected stores; empty array means all stores. */
    selectedStoreIds: number[];
}

export function StoreFilter({ selectedStoreIds }: Props) {
    const stores = (usePage<PageProps>().props.stores ?? []) as Store[];

    if (stores.length === 0) return null;

    const allSelected = selectedStoreIds.length === 0;

    function applySelection(ids: number[]): void {
        const params = new URLSearchParams(window.location.search);
        params.delete('page');
        if (ids.length === 0) {
            params.delete('store_ids');
        } else {
            params.set('store_ids', ids.join(','));
        }
        router.get(window.location.pathname, Object.fromEntries(params), {
            replace: true,
        });
    }

    function toggleStore(storeId: number): void {
        const next = selectedStoreIds.includes(storeId)
            ? selectedStoreIds.filter((id) => id !== storeId)
            : [...selectedStoreIds, storeId];
        // If all stores end up selected, treat as "all" (no filter)
        applySelection(next.length === stores.length ? [] : next);
    }

    return (
        <div className="mb-6 flex flex-wrap items-center gap-2">
            {stores.length > 1 && (
                <button
                    onClick={() => applySelection([])}
                    className={cn(
                        'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                        allSelected
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                    )}
                >
                    All
                </button>
            )}
            {stores.map((store: Store) => {
                const active = stores.length === 1 || allSelected || selectedStoreIds.includes(store.id);
                return (
                    <button
                        key={store.id}
                        onClick={() => stores.length > 1 ? toggleStore(store.id) : undefined}
                        className={cn(
                            'flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            active
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300',
                            stores.length === 1 && 'cursor-default',
                        )}
                        title={`${store.name} — ${syncDotTitle(store.status, store.last_synced_at)}`}
                    >
                        <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', syncDotClass(store.status, store.last_synced_at, 'store'))} />
                        {store.name}
                    </button>
                );
            })}
        </div>
    );
}
