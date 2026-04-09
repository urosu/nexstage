import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, RefreshCw, ShieldAlert } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { cn } from '@/lib/utils';
import { formatDateOnly } from '@/lib/formatters';
import type { AdminWorkspace, PageProps } from '@/types';

const PLAN_LABELS: Record<string, string> = {
    starter:    'Starter',
    growth:     'Growth',
    scale:      'Scale',
    percentage: 'Percentage',
    enterprise: 'Enterprise',
};

const PLAN_COLORS: Record<string, string> = {
    starter:    'bg-blue-100 text-blue-700',
    growth:     'bg-violet-100 text-violet-700',
    scale:      'bg-indigo-100 text-indigo-700',
    percentage: 'bg-amber-100 text-amber-700',
    enterprise: 'bg-green-100 text-green-700',
};

interface PaginatedWorkspaces {
    data: AdminWorkspace[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Props {
    workspaces: PaginatedWorkspaces;
    filters: { search: string };
}

const PLAN_OPTIONS = ['starter', 'growth', 'scale', 'percentage', 'enterprise'] as const;

export default function AdminWorkspaces({ workspaces, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [navigating, setNavigating] = useState(false);
    const [planEditing, setPlanEditing] = useState<number | null>(null);
    const searchTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        const removeStart  = router.on('start',  () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));
        return () => { removeStart(); removeFinish(); };
    }, []);

    function handleSearch(value: string): void {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get('/admin/workspaces', { search: value }, { preserveState: true, replace: true });
        }, 350);
    }

    function triggerSync(workspaceId: number): void {
        router.post(`/admin/workspaces/${workspaceId}/sync`, {}, { preserveScroll: true });
    }

    function setPlan(workspaceId: number, plan: string): void {
        router.patch(`/admin/workspaces/${workspaceId}/plan`, { billing_plan: plan }, {
            preserveScroll: true,
            onSuccess: () => setPlanEditing(null),
        });
    }

    function impersonate(ownerId: number): void {
        router.post(`/admin/users/${ownerId}/impersonate`);
    }

    return (
        <AppLayout>
            <Head title="Admin — Workspaces" />
            <PageHeader
                title="Workspaces"
                subtitle={`${workspaces.total} total`}
                action={
                    <Link
                        href="/admin/users"
                        className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 transition-colors"
                    >
                        Users →
                    </Link>
                }
            />

            {/* Admin badge */}
            <div className="mb-4 flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700">
                <ShieldAlert className="h-3.5 w-3.5" />
                Super admin panel — changes affect all workspaces
            </div>

            {/* Search */}
            <div className="mb-4">
                <input
                    type="text"
                    value={search}
                    onChange={(e) => handleSearch(e.target.value)}
                    placeholder="Search by name or slug…"
                    className="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm placeholder-zinc-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 sm:max-w-xs"
                />
            </div>

            {/* Table */}
            {navigating ? (
                <div className="space-y-2">
                    {[...Array(6)].map((_, i) => (
                        <div key={i} className="h-14 animate-pulse rounded-xl bg-zinc-100" />
                    ))}
                </div>
            ) : workspaces.data.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-16 text-center">
                    <Building2 className="mb-3 h-8 w-8 text-zinc-300" />
                    <p className="text-sm text-zinc-500">No workspaces found.</p>
                </div>
            ) : (
                <div className="rounded-xl border border-zinc-200 bg-white overflow-x-auto">
                    <table className="w-full text-sm min-w-[800px]">
                        <thead>
                            <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                <th className="px-4 py-3 font-medium text-zinc-400">Workspace</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Owner</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Plan</th>
                                <th className="px-4 py-3 font-medium text-zinc-400 text-right">Stores</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Created</th>
                                <th className="px-4 py-3 font-medium text-zinc-400 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {workspaces.data.map((w) => (
                                <tr
                                    key={w.id}
                                    className={cn('hover:bg-zinc-50 transition-colors', w.deleted_at && 'opacity-50')}
                                >
                                    <td className="px-4 py-3">
                                        <div className="font-medium text-zinc-900">{w.name}</div>
                                        <div className="text-xs text-zinc-400">{w.slug}</div>
                                        {w.deleted_at && (
                                            <div className="text-[10px] font-medium text-red-500 uppercase">
                                                Deleted
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {w.owner ? (
                                            <>
                                                <div className="text-zinc-900">{w.owner.name}</div>
                                                <div className="text-xs text-zinc-400">{w.owner.email}</div>
                                            </>
                                        ) : (
                                            <span className="text-xs text-zinc-400">No owner</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {planEditing === w.id ? (
                                            <select
                                                autoFocus
                                                defaultValue={w.billing_plan ?? ''}
                                                onBlur={() => setPlanEditing(null)}
                                                onChange={(e) => setPlan(w.id, e.target.value)}
                                                className="rounded border border-zinc-300 px-2 py-1 text-xs focus:border-indigo-500 focus:outline-none"
                                            >
                                                <option value="" disabled>Select plan</option>
                                                {PLAN_OPTIONS.map((p) => (
                                                    <option key={p} value={p}>{PLAN_LABELS[p]}</option>
                                                ))}
                                            </select>
                                        ) : (
                                            <button
                                                onClick={() => setPlanEditing(w.id)}
                                                className={cn(
                                                    'rounded px-2 py-0.5 text-xs font-medium',
                                                    w.billing_plan
                                                        ? PLAN_COLORS[w.billing_plan] ?? 'bg-zinc-100 text-zinc-500'
                                                        : 'bg-zinc-100 text-zinc-400',
                                                )}
                                                title="Click to change plan"
                                            >
                                                {w.billing_plan ? (PLAN_LABELS[w.billing_plan] ?? w.billing_plan) : 'Trial'}
                                            </button>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-zinc-500">
                                        {w.stores_count}
                                    </td>
                                    <td className="px-4 py-3 text-xs text-zinc-400">
                                        {formatDateOnly(w.created_at)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <div className="flex items-center justify-end gap-1">
                                            {!w.deleted_at && (
                                                <button
                                                    onClick={() => triggerSync(w.id)}
                                                    className="flex items-center gap-1 rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 transition-colors"
                                                    title="Trigger sync for all active stores"
                                                >
                                                    <RefreshCw className="h-3.5 w-3.5" />
                                                    Sync
                                                </button>
                                            )}
                                            {w.owner && !w.deleted_at && (
                                                <button
                                                    onClick={() => impersonate(w.owner!.id)}
                                                    className="rounded px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 transition-colors"
                                                    title="Impersonate workspace owner"
                                                >
                                                    View as owner
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Pagination */}
            {workspaces.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-zinc-500">
                    <span>Page {workspaces.current_page} of {workspaces.last_page}</span>
                    <div className="flex gap-2">
                        {workspaces.current_page > 1 && (
                            <button
                                onClick={() => router.get('/admin/workspaces', { search, page: workspaces.current_page - 1 })}
                                className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium hover:bg-zinc-50 transition-colors"
                            >
                                Previous
                            </button>
                        )}
                        {workspaces.current_page < workspaces.last_page && (
                            <button
                                onClick={() => router.get('/admin/workspaces', { search, page: workspaces.current_page + 1 })}
                                className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium hover:bg-zinc-50 transition-colors"
                            >
                                Next
                            </button>
                        )}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
