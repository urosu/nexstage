import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
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
}

interface Props extends PageProps {
    stores: StoreListItem[];
}

const STATUS_STYLES: Record<string, string> = {
    active:       'bg-green-50 text-green-700',
    error:        'bg-red-50 text-red-700',
    connecting:   'bg-amber-50 text-amber-700',
    disconnected: 'bg-zinc-100 text-zinc-400',
};

function StatusBadge({ status }: { status: string }) {
    const cls = STATUS_STYLES[status] ?? 'bg-zinc-100 text-zinc-400';
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ${cls}`}>
            {status}
        </span>
    );
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

export default function StoresIndex({ stores }: Props) {
    return (
        <AppLayout>
            <Head title="Stores" />
            <PageHeader
                title="Stores"
                subtitle="All connected stores in this workspace"
                action={
                    <Link
                        href="/onboarding"
                        className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        Connect store
                    </Link>
                }
            />

            {stores.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                    <p className="text-sm text-zinc-500">No stores connected yet.</p>
                    <Link
                        href="/onboarding"
                        className="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        Connect a store →
                    </Link>
                </div>
            ) : (
                <div className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-zinc-100 text-left bg-zinc-50">
                                <th className="px-4 py-3 font-medium text-zinc-400">Store</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Status</th>
                                <th className="px-4 py-3 font-medium text-zinc-400 hidden sm:table-cell">Currency</th>
                                <th className="px-4 py-3 font-medium text-zinc-400 hidden lg:table-cell">Last Synced</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {stores.map((store) => (
                                <tr key={store.id} className="hover:bg-zinc-50 transition-colors">
                                    <td className="px-4 py-3">
                                        <div className="font-medium text-zinc-900">{store.name}</div>
                                        <div className="text-xs text-zinc-400">{store.domain}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusBadge status={store.status} />
                                    </td>
                                    <td className="px-4 py-3 text-zinc-600 hidden sm:table-cell tabular-nums">
                                        {store.currency}
                                    </td>
                                    <td className="px-4 py-3 text-zinc-400 hidden lg:table-cell">
                                        {formatRelativeTime(store.last_synced_at)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Link
                                            href={`/stores/${store.slug}/overview`}
                                            className="text-sm font-medium text-indigo-600 hover:text-indigo-700"
                                        >
                                            View →
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
