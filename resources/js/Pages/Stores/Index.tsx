import { router } from '@inertiajs/react';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { Loader2 } from 'lucide-react';
import { wurl } from '@/lib/workspace-url';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusBadge } from '@/Components/shared/StatusBadge';
import { formatCurrency } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface StoreListItem {
    id: number;
    slug: string;
    name: string;
    domain: string;
    type: string;
    status: 'connecting' | 'active' | 'error' | 'disconnected';
    currency: string;
    timezone: string;
    last_synced_at: string | null;
    historical_import_status: string | null;
    historical_import_progress: number | null;
    revenue_30d: number | null;
    marketing_pct: number | null;
    prev_marketing_pct: number | null;
    wl_tag: 'winner' | 'loser' | null;
}

type WlClassifier = 'target' | 'peer' | 'period';

interface Props extends PageProps {
    stores: StoreListItem[];
    stores_total_count: number;
    workspace_target_marketing_pct: number | null;
    wl_has_target: boolean;
    active_classifier: WlClassifier;
    filter: 'all' | 'winners' | 'losers';
    classifier: WlClassifier | null;
}

function formatRelativeTime(iso: string | null): string {
    if (!iso) return '—';
    const diff = Date.now() - new Date(iso).getTime();
    const mins  = Math.floor(diff / 60_000);
    const hours = Math.floor(mins / 60);
    const days  = Math.floor(hours / 24);
    if (mins < 1)   return 'just now';
    if (mins < 60)  return `${mins}m ago`;
    if (hours < 24) return `${hours}h ago`;
    return `${days}d ago`;
}

function classifierLabel(c: WlClassifier): string {
    return c === 'target' ? 'vs Target' : c === 'peer' ? 'vs Peer Avg' : 'vs Prev Period';
}

function WlClassifierDropdown({
    active,
    hasTarget,
    onChange,
}: {
    active: WlClassifier;
    hasTarget: boolean;
    onChange: (c: WlClassifier) => void;
}) {
    const options: { value: WlClassifier; disabled?: boolean }[] = [
        { value: 'target', disabled: !hasTarget },
        { value: 'peer' },
        { value: 'period' },
    ];

    return (
        <select
            value={active}
            onChange={e => onChange(e.target.value as WlClassifier)}
            className="rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs text-zinc-600 focus:outline-none focus:ring-1 focus:ring-primary"
            title="Classification method for Winners / Losers"
        >
            {options.map(o => (
                <option key={o.value} value={o.value} disabled={o.disabled}>
                    {classifierLabel(o.value)}{o.disabled ? ' (no target set)' : ''}
                </option>
            ))}
        </select>
    );
}

export default function StoresIndex({
    stores,
    stores_total_count,
    workspace_target_marketing_pct,
    wl_has_target,
    active_classifier,
    filter,
    classifier,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const w = (path: string) => wurl(workspace?.slug, path);

    const storesUrl = wurl(workspace?.slug, '/stores');

    function navigate(params: Record<string, string | undefined>) {
        router.get(storesUrl, params as Record<string, string>, { preserveState: true, replace: true });
    }

    function setFilter(f: 'all' | 'winners' | 'losers') {
        navigate({
            ...(f !== 'all'         ? { filter: f }          : {}),
            ...(classifier !== null ? { classifier: classifier } : {}),
        });
    }

    function setClassifier(c: WlClassifier) {
        navigate({
            ...(filter !== 'all' ? { filter } : {}),
            classifier: c,
        });
    }

    // Chips are always shown when there are stores; classifier dropdown always available.
    const showFilterChips = stores_total_count > 0;

    // Poll every 5 s while any store has an import in progress.
    useEffect(() => {
        const hasActive = stores.some(
            (s) => s.historical_import_status === 'pending' || s.historical_import_status === 'running',
        );
        if (!hasActive) return;
        const id = setInterval(() => router.reload({ only: ['stores'] }), 5000);
        return () => clearInterval(id);
    }, [stores]);

    return (
        <AppLayout>
            <Head title="Stores" />
            <PageHeader
                title="Stores"
                subtitle="All connected stores in this workspace"
                action={
                    <Link
                        href={w('/stores/connect')}
                        className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90"
                    >
                        Connect store
                    </Link>
                }
            />

            {stores_total_count === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <p className="text-sm text-zinc-500">No stores connected yet.</p>
                    <Link
                        href={w('/stores/connect')}
                        className="mt-4 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90"
                    >
                        Connect a store →
                    </Link>
                </div>
            ) : (
                <>
                    {/* Winners / Losers chips — server-side filtered.
                        Metric: marketing_pct (lower = more efficient = winner).
                        See: PLANNING.md section 15 (Winners/Losers classifier) */}
                    {showFilterChips && (
                        <div className="mb-3 flex items-center gap-2">
                            <div className="flex items-center gap-1">
                                {(['all', 'winners', 'losers'] as const).map(f => (
                                    <button
                                        key={f}
                                        onClick={() => setFilter(f)}
                                        className={cn(
                                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                            filter === f
                                                ? f === 'winners'
                                                    ? 'border-green-300 bg-green-50 text-green-700'
                                                    : f === 'losers'
                                                    ? 'border-red-300 bg-red-50 text-red-700'
                                                    : 'border-primary bg-primary/10 text-primary'
                                                : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 hover:text-zinc-700',
                                        )}
                                        title={
                                            f === 'all'     ? 'Show all stores' :
                                            f === 'winners' ? 'Marketing % below benchmark (efficient spend)' :
                                                              'Marketing % at or above benchmark'
                                        }
                                    >
                                        {f === 'all' ? 'All' : f === 'winners' ? 'Winners' : 'Losers'}
                                    </button>
                                ))}
                                {filter !== 'all' && (
                                    <span className="text-xs text-zinc-400">
                                        {stores.length} / {stores_total_count}
                                    </span>
                                )}
                            </div>
                            <WlClassifierDropdown
                                active={active_classifier}
                                hasTarget={wl_has_target}
                                onChange={setClassifier}
                            />
                        </div>
                    )}

                    <div className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-zinc-100 text-left bg-zinc-50">
                                    <th className="px-4 py-3 font-medium text-zinc-400">Store</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400">Status</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400 hidden md:table-cell text-right">30d Revenue</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400 hidden sm:table-cell">Currency</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400 hidden lg:table-cell">Last Synced</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100">
                                {stores.map((store) => (
                                    <tr key={store.id} className="hover:bg-zinc-50 transition-colors">
                                        <td className="px-4 py-3">
                                            <Link
                                                href={w(`/stores/${store.slug}/overview`)}
                                                className="font-medium text-zinc-900 hover:text-primary"
                                            >
                                                {store.name}
                                            </Link>
                                            <div className="text-xs text-zinc-400">{store.domain}</div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                <StatusBadge status={store.status} />
                                                {(store.historical_import_status === 'pending' || store.historical_import_status === 'running') && (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
                                                        <Loader2 className="h-3 w-3 animate-spin" />
                                                        {store.historical_import_status === 'pending'
                                                            ? 'Import queued'
                                                            : `Importing… ${store.historical_import_progress ?? 0}%`}
                                                    </span>
                                                )}
                                                {store.historical_import_status === 'failed' && (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                                        Import failed
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-right hidden md:table-cell">
                                            {store.revenue_30d !== null ? (
                                                <>
                                                    <div className="tabular-nums text-zinc-700">
                                                        {formatCurrency(store.revenue_30d, currency)}
                                                    </div>
                                                    {store.marketing_pct !== null && (
                                                        <div className="text-xs text-zinc-400 mt-0.5 tabular-nums">
                                                            {store.marketing_pct.toFixed(1)}% mktg
                                                        </div>
                                                    )}
                                                </>
                                            ) : (
                                                <span className="text-zinc-400">—</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-zinc-600 hidden sm:table-cell tabular-nums">
                                            {store.currency}
                                        </td>
                                        <td className="px-4 py-3 text-zinc-400 hidden lg:table-cell">
                                            {formatRelativeTime(store.last_synced_at)}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={w(`/stores/${store.slug}/overview`)}
                                                className="text-sm font-medium text-primary hover:text-primary/80"
                                            >
                                                View →
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </AppLayout>
    );
}
