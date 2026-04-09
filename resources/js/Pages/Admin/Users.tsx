import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Users, ShieldAlert, UserCheck } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { cn } from '@/lib/utils';
import { formatDatetime, formatDateOnly } from '@/lib/formatters';
import type { AdminUser } from '@/types';

interface PaginatedUsers {
    data: AdminUser[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Props {
    users: PaginatedUsers;
    filters: { search: string };
}

export default function AdminUsers({ users, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [navigating, setNavigating] = useState(false);
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
            router.get('/admin/users', { search: value }, { preserveState: true, replace: true });
        }, 350);
    }

    function impersonate(userId: number): void {
        router.post(`/admin/users/${userId}/impersonate`);
    }

    return (
        <AppLayout>
            <Head title="Admin — Users" />
            <PageHeader
                title="Users"
                subtitle={`${users.total} total`}
                action={
                    <Link
                        href="/admin/workspaces"
                        className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 transition-colors"
                    >
                        Workspaces →
                    </Link>
                }
            />

            {/* Admin badge */}
            <div className="mb-4 flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700">
                <ShieldAlert className="h-3.5 w-3.5" />
                Super admin panel — impersonation is logged
            </div>

            {/* Search */}
            <div className="mb-4">
                <input
                    type="text"
                    value={search}
                    onChange={(e) => handleSearch(e.target.value)}
                    placeholder="Search by name or email…"
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
            ) : users.data.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-16 text-center">
                    <Users className="mb-3 h-8 w-8 text-zinc-300" />
                    <p className="text-sm text-zinc-500">No users found.</p>
                </div>
            ) : (
                <div className="rounded-xl border border-zinc-200 bg-white overflow-x-auto">
                    <table className="w-full text-sm min-w-[700px]">
                        <thead>
                            <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                <th className="px-4 py-3 font-medium text-zinc-400">User</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Role</th>
                                <th className="px-4 py-3 font-medium text-zinc-400 text-right">Workspaces</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Last login</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Joined</th>
                                <th className="px-4 py-3 font-medium text-zinc-400 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {users.data.map((u) => (
                                <tr key={u.id} className="hover:bg-zinc-50 transition-colors">
                                    <td className="px-4 py-3">
                                        <div className="font-medium text-zinc-900">{u.name}</div>
                                        <div className="text-xs text-zinc-400">{u.email}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {u.is_super_admin ? (
                                            <span className="flex items-center gap-1 rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 w-fit">
                                                <UserCheck className="h-3 w-3" />
                                                Super admin
                                            </span>
                                        ) : (
                                            <span className="text-xs text-zinc-400">User</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-zinc-500">
                                        {u.workspaces_count}
                                    </td>
                                    <td className="px-4 py-3 text-xs text-zinc-400">
                                        {formatDatetime(u.last_login_at)}
                                    </td>
                                    <td className="px-4 py-3 text-xs text-zinc-400">
                                        {formatDateOnly(u.created_at)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <button
                                            onClick={() => impersonate(u.id)}
                                            className={cn(
                                                'rounded px-2 py-1 text-xs transition-colors',
                                                u.is_super_admin
                                                    ? 'text-zinc-300 cursor-not-allowed'
                                                    : 'text-indigo-600 hover:bg-indigo-50',
                                            )}
                                            disabled={u.is_super_admin}
                                            title={u.is_super_admin ? 'Cannot impersonate a super admin' : 'Impersonate this user'}
                                        >
                                            Impersonate
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Pagination */}
            {users.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-zinc-500">
                    <span>Page {users.current_page} of {users.last_page}</span>
                    <div className="flex gap-2">
                        {users.current_page > 1 && (
                            <button
                                onClick={() => router.get('/admin/users', { search, page: users.current_page - 1 })}
                                className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium hover:bg-zinc-50 transition-colors"
                            >
                                Previous
                            </button>
                        )}
                        {users.current_page < users.last_page && (
                            <button
                                onClick={() => router.get('/admin/users', { search, page: users.current_page + 1 })}
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
