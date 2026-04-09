import { router, usePage } from '@inertiajs/react';
import type { Store, PageProps } from '@/types';
import { cn } from '@/lib/utils';
import { ChevronDown, Store as StoreIcon, Check } from 'lucide-react';
import { useState } from 'react';

interface Props {
    /** IDs of currently selected stores; empty array means all stores. */
    selectedStoreIds: number[];
}

export function StoreFilter({ selectedStoreIds }: Props) {
    const stores = (usePage<PageProps>().props.stores ?? []) as Store[];
    const [open, setOpen] = useState(false);

    if (stores.length <= 1) return null;

    const allSelected = selectedStoreIds.length === 0;
    const label = allSelected
        ? 'All stores'
        : selectedStoreIds.length === 1
            ? (stores.find((s) => s.id === selectedStoreIds[0])?.name ?? '1 store')
            : `${selectedStoreIds.length} stores`;

    function applySelection(ids: number[]): void {
        const params = new URLSearchParams(window.location.search);
        params.delete('page');
        if (ids.length === 0) {
            params.delete('store_ids');
        } else {
            params.set('store_ids', ids.join(','));
        }
        router.get(window.location.pathname, Object.fromEntries(params), {
            preserveState: true,
            replace: true,
        });
    }

    function toggleStore(storeId: number): void {
        const next = selectedStoreIds.includes(storeId)
            ? selectedStoreIds.filter((id) => id !== storeId)
            : [...selectedStoreIds, storeId];
        applySelection(next);
    }

    function selectAll(): void {
        applySelection([]);
        setOpen(false);
    }

    return (
        <div className="relative">
            <button
                onClick={() => setOpen((v) => !v)}
                className={cn(
                    'flex h-8 items-center gap-1.5 rounded-lg border px-3 text-sm transition-colors',
                    !allSelected
                        ? 'border-indigo-300 bg-indigo-50 text-indigo-700 hover:bg-indigo-100'
                        : 'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900',
                )}
            >
                <StoreIcon className="h-3.5 w-3.5 shrink-0" />
                <span className="max-w-[120px] truncate">{label}</span>
                <ChevronDown className={cn('h-3.5 w-3.5 shrink-0 transition-transform', open && 'rotate-180')} />
            </button>

            {open && (
                <>
                    <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
                    <div className="absolute left-0 top-full z-20 mt-1 w-56 rounded-lg border border-zinc-200 bg-white py-1 shadow-lg">
                        {/* All stores row */}
                        <button
                            onClick={selectAll}
                            className="flex w-full items-center gap-2.5 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors"
                        >
                            <span
                                className={cn(
                                    'flex h-4 w-4 shrink-0 items-center justify-center rounded border transition-colors',
                                    allSelected ? 'border-indigo-600 bg-indigo-600' : 'border-zinc-300',
                                )}
                            >
                                {allSelected && <Check className="h-3 w-3 text-white" />}
                            </span>
                            <span className="font-medium">All stores</span>
                        </button>
                        <div className="my-1 border-t border-zinc-100" />
                        {stores.map((store: Store) => {
                            const checked = selectedStoreIds.includes(store.id);
                            return (
                                <button
                                    key={store.id}
                                    onClick={() => toggleStore(store.id)}
                                    className="flex w-full items-center gap-2.5 px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-50 transition-colors"
                                >
                                    <span
                                        className={cn(
                                            'flex h-4 w-4 shrink-0 items-center justify-center rounded border transition-colors',
                                            checked ? 'border-indigo-600 bg-indigo-600' : 'border-zinc-300',
                                        )}
                                    >
                                        {checked && <Check className="h-3 w-3 text-white" />}
                                    </span>
                                    <span className="truncate">{store.name}</span>
                                </button>
                            );
                        })}
                    </div>
                </>
            )}
        </div>
    );
}
