import { useState, useEffect, useCallback } from 'react';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle, Clock, RefreshCw, ShieldAlert, XCircle } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatDatetime } from '@/lib/formatters';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface SyncLog {
    id: number;
    workspace: { id: number; name: string } | null;
    job_type: string;
    status: 'running' | 'completed' | 'failed';
    records_processed: number | null;
    error_message: string | null;
    duration_seconds: number | null;
    started_at: string | null;
    created_at: string;
}

interface WebhookLog {
    id: number;
    workspace: { id: number; name: string } | null;
    store: { id: number; name: string } | null;
    event: string;
    status: 'pending' | 'processed' | 'failed';
    signature_valid: boolean;
    error_message: string | null;
    processed_at: string | null;
    created_at: string;
}

interface PaginatedResult<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: string }) {
    const map: Record<string, { label: string; cls: string; Icon: React.ComponentType<{ className?: string }> }> = {
        completed: { label: 'Completed', cls: 'bg-green-100 text-green-700',  Icon: CheckCircle },
        processed: { label: 'Processed', cls: 'bg-green-100 text-green-700',  Icon: CheckCircle },
        failed:    { label: 'Failed',    cls: 'bg-red-100 text-red-700',      Icon: XCircle },
        running:   { label: 'Running',   cls: 'bg-blue-100 text-blue-700',    Icon: RefreshCw },
        pending:   { label: 'Pending',   cls: 'bg-zinc-100 text-zinc-600',    Icon: Clock },
    };
    const { label, cls, Icon } = map[status] ?? { label: status, cls: 'bg-zinc-100 text-zinc-600', Icon: Clock };
    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>
            <Icon className="h-3 w-3" />
            {label}
        </span>
    );
}

function formatDuration(secs: number | null): string {
    if (secs === null) return '—';
    if (secs < 60) return `${secs}s`;
    return `${Math.floor(secs / 60)}m ${secs % 60}s`;
}

const formatTs = formatDatetime;

function shortJobType(jobType: string): string {
    // Strip namespace: "App\Jobs\SyncStoreOrdersJob" → "SyncStoreOrdersJob"
    return jobType.split('\\').pop() ?? jobType;
}

// ─── Pagination ───────────────────────────────────────────────────────────────

function Pagination({
    current,
    last,
    total,
    onPage,
}: {
    current: number;
    last: number;
    total: number;
    onPage: (p: number) => void;
}) {
    if (last <= 1) return null;
    return (
        <div className="flex items-center justify-between border-t border-zinc-100 px-4 py-3 text-sm text-zinc-500">
            <span>{total} total</span>
            <div className="flex gap-1">
                <button
                    onClick={() => onPage(current - 1)}
                    disabled={current === 1}
                    className="rounded px-2 py-1 hover:bg-zinc-100 disabled:opacity-40"
                >
                    ‹
                </button>
                <span className="px-2 py-1">{current} / {last}</span>
                <button
                    onClick={() => onPage(current + 1)}
                    disabled={current === last}
                    className="rounded px-2 py-1 hover:bg-zinc-100 disabled:opacity-40"
                >
                    ›
                </button>
            </div>
        </div>
    );
}

// ─── Sync logs tab ────────────────────────────────────────────────────────────

function SyncLogsTab({
    data,
    filters,
    onFilter,
}: {
    data: PaginatedResult<SyncLog>;
    filters: { status: string; search: string };
    onFilter: (f: Partial<{ status: string; search: string; tab: string }>) => void;
}) {
    const [search, setSearch] = useState(filters.search);

    return (
        <div className="space-y-4">
            {/* Filters */}
            <div className="flex flex-wrap gap-2">
                <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && onFilter({ search, tab: 'sync' })}
                    placeholder="Search job type or error…"
                    className="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                />
                {['', 'running', 'completed', 'failed'].map((s) => (
                    <button
                        key={s}
                        onClick={() => onFilter({ status: s, tab: 'sync' })}
                        className={`rounded-lg border px-3 py-1.5 text-sm transition-colors ${filters.status === s ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-zinc-200 text-zinc-600 hover:bg-zinc-50'}`}
                    >
                        {s === '' ? 'All' : s.charAt(0).toUpperCase() + s.slice(1)}
                    </button>
                ))}
            </div>

            {/* Table */}
            <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white">
                <table className="w-full text-sm">
                    <thead className="border-b border-zinc-100 bg-zinc-50 text-xs font-medium uppercase tracking-wide text-zinc-400">
                        <tr>
                            <th className="px-4 py-2.5 text-left">Job</th>
                            <th className="px-4 py-2.5 text-left">Workspace</th>
                            <th className="px-4 py-2.5 text-left">Status</th>
                            <th className="px-4 py-2.5 text-left">Records</th>
                            <th className="px-4 py-2.5 text-left">Duration</th>
                            <th className="px-4 py-2.5 text-left">Started</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-zinc-100">
                        {data.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-zinc-400">No logs found.</td>
                            </tr>
                        )}
                        {data.data.map((log) => (
                            <tr key={log.id} className="hover:bg-zinc-50">
                                <td className="px-4 py-2.5">
                                    <span className="font-mono text-xs text-zinc-700">{shortJobType(log.job_type)}</span>
                                </td>
                                <td className="px-4 py-2.5 text-zinc-600">
                                    {log.workspace?.name ?? <span className="text-zinc-300">—</span>}
                                </td>
                                <td className="px-4 py-2.5">
                                    <StatusBadge status={log.status} />
                                </td>
                                <td className="px-4 py-2.5 text-zinc-600">
                                    {log.records_processed ?? '—'}
                                </td>
                                <td className="px-4 py-2.5 text-zinc-600">
                                    {formatDuration(log.duration_seconds)}
                                </td>
                                <td className="px-4 py-2.5 text-zinc-500 text-xs">
                                    {formatTs(log.started_at ?? log.created_at)}
                                    {log.status === 'failed' && log.error_message && (
                                        <div className="mt-0.5 max-w-xs truncate text-red-600" title={log.error_message}>
                                            {log.error_message}
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <Pagination
                    current={data.current_page}
                    last={data.last_page}
                    total={data.total}
                    onPage={(p) => onFilter({ tab: 'sync', status: filters.status, search: filters.search, sync_page: p } as Parameters<typeof onFilter>[0])}
                />
            </div>
        </div>
    );
}

// ─── Webhook logs tab ─────────────────────────────────────────────────────────

function WebhookLogsTab({
    data,
    filters,
    onFilter,
}: {
    data: PaginatedResult<WebhookLog>;
    filters: { status: string; search: string };
    onFilter: (f: Partial<{ status: string; search: string; tab: string }>) => void;
}) {
    const [search, setSearch] = useState(filters.search);

    return (
        <div className="space-y-4">
            {/* Filters */}
            <div className="flex flex-wrap gap-2">
                <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && onFilter({ search, tab: 'webhook' })}
                    placeholder="Search event or error…"
                    className="rounded-lg border border-zinc-200 px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500"
                />
                {['', 'pending', 'processed', 'failed'].map((s) => (
                    <button
                        key={s}
                        onClick={() => onFilter({ status: s, tab: 'webhook' })}
                        className={`rounded-lg border px-3 py-1.5 text-sm transition-colors ${filters.status === s ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-zinc-200 text-zinc-600 hover:bg-zinc-50'}`}
                    >
                        {s === '' ? 'All' : s.charAt(0).toUpperCase() + s.slice(1)}
                    </button>
                ))}
            </div>

            {/* Table */}
            <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white">
                <table className="w-full text-sm">
                    <thead className="border-b border-zinc-100 bg-zinc-50 text-xs font-medium uppercase tracking-wide text-zinc-400">
                        <tr>
                            <th className="px-4 py-2.5 text-left">Event</th>
                            <th className="px-4 py-2.5 text-left">Store</th>
                            <th className="px-4 py-2.5 text-left">Workspace</th>
                            <th className="px-4 py-2.5 text-left">Status</th>
                            <th className="px-4 py-2.5 text-left">Signature</th>
                            <th className="px-4 py-2.5 text-left">Received</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-zinc-100">
                        {data.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-zinc-400">No logs found.</td>
                            </tr>
                        )}
                        {data.data.map((log) => (
                            <tr key={log.id} className="hover:bg-zinc-50">
                                <td className="px-4 py-2.5">
                                    <span className="font-mono text-xs text-zinc-700">{log.event}</span>
                                </td>
                                <td className="px-4 py-2.5 text-zinc-600">
                                    {log.store?.name ?? <span className="text-zinc-300">—</span>}
                                </td>
                                <td className="px-4 py-2.5 text-zinc-600">
                                    {log.workspace?.name ?? <span className="text-zinc-300">—</span>}
                                </td>
                                <td className="px-4 py-2.5">
                                    <StatusBadge status={log.status} />
                                </td>
                                <td className="px-4 py-2.5">
                                    {log.signature_valid ? (
                                        <span className="inline-flex items-center gap-1 text-xs text-green-600"><CheckCircle className="h-3 w-3" /> Valid</span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-xs text-red-600"><XCircle className="h-3 w-3" /> Invalid</span>
                                    )}
                                </td>
                                <td className="px-4 py-2.5 text-zinc-500 text-xs">
                                    {formatTs(log.created_at)}
                                    {log.status === 'failed' && log.error_message && (
                                        <div className="mt-0.5 max-w-xs truncate text-red-600" title={log.error_message}>
                                            {log.error_message}
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <Pagination
                    current={data.current_page}
                    last={data.last_page}
                    total={data.total}
                    onPage={(p) => onFilter({ tab: 'webhook', status: filters.status, search: filters.search, webhook_page: p } as Parameters<typeof onFilter>[0])}
                />
            </div>
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function Logs({
    sync_logs,
    webhook_logs,
    filters,
}: PageProps<{
    sync_logs: PaginatedResult<SyncLog>;
    webhook_logs: PaginatedResult<WebhookLog>;
    filters: { tab: string; status: string; search: string };
}>) {
    const activeTab = filters.tab === 'webhook' ? 'webhook' : 'sync';
    const [refreshing, setRefreshing] = useState(false);

    const applyFilter = (updates: Record<string, unknown>) => {
        router.get('/admin/logs', { ...filters, ...updates }, { preserveState: true, preserveScroll: true });
    };

    const refresh = useCallback(() => {
        setRefreshing(true);
        router.reload({ onFinish: () => setRefreshing(false) });
    }, []);

    // Auto-refresh every 30 seconds
    useEffect(() => {
        const id = setInterval(refresh, 30_000);
        return () => clearInterval(id);
    }, [refresh]);

    return (
        <AppLayout>
            <Head title="Admin Logs" />

            <div className="mb-4 flex items-start justify-between gap-4">
                <PageHeader title="Logs" subtitle="Sync job logs and webhook delivery logs across all workspaces" />
                <button
                    onClick={refresh}
                    disabled={refreshing}
                    className="mt-1 flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50 disabled:opacity-50"
                >
                    <RefreshCw className={`h-3.5 w-3.5 ${refreshing ? 'animate-spin' : ''}`} />
                    Refresh
                </button>
            </div>

            <div className="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                <ShieldAlert className="h-4 w-4 shrink-0" />
                Super admin panel — viewing logs across all workspaces. Webhook logs may contain customer PII.
            </div>

            {/* Tab switcher */}
            <div className="mb-4 flex gap-1 rounded-lg border border-zinc-200 bg-white p-1 w-fit">
                {([
                    { key: 'sync',    label: `Sync logs (${sync_logs.total})` },
                    { key: 'webhook', label: `Webhook logs (${webhook_logs.total})` },
                ] as const).map(({ key, label }) => (
                    <button
                        key={key}
                        onClick={() => applyFilter({ tab: key, status: '', search: '' })}
                        className={`rounded-md px-4 py-1.5 text-sm font-medium transition-colors ${activeTab === key ? 'bg-indigo-600 text-white' : 'text-zinc-600 hover:bg-zinc-50'}`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {activeTab === 'sync' ? (
                <SyncLogsTab
                    data={sync_logs}
                    filters={{ status: filters.status, search: filters.search }}
                    onFilter={applyFilter}
                />
            ) : (
                <WebhookLogsTab
                    data={webhook_logs}
                    filters={{ status: filters.status, search: filters.search }}
                    onFilter={applyFilter}
                />
            )}
        </AppLayout>
    );
}
