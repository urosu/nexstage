import { Head, Link } from '@inertiajs/react';
import { Activity, AlertTriangle, CheckCircle2, Clock, Database, RefreshCw, Server, Wifi, XCircle } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatDatetime } from '@/lib/formatters';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface QueueHealth {
    queue: string;
    depth: number;
    wait_seconds: number;
    failed_count: number;
}

interface StoreHealth {
    id: number;
    workspace: { id: number; name: string } | null;
    name: string;
    status: string;
    last_synced_at: string | null;
    consecutive_sync_failures: number;
    historical_import_status: string | null;
    is_stale: boolean;
}

interface BackfillEntry {
    workspace_id: number;
    workspace_name: string;
    progress: {
        status: string;
        processed: number;
        total: number;
        started_at?: string;
        completed_at?: string;
        failed_at?: string;
    } | null;
}

interface NullFxEntry {
    workspace_id: number;
    null_count: number;
}

interface ApiQuota {
    throttled_until: string | null;
    last_throttle_at: string | null;
    hits_today: number;
    calls_today: number;
    last_success_at: string | null;
    usage_pct?: number | null;
    tier?: string | null;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fmtWait(seconds: number): string {
    if (seconds === 0) return '0s';
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

// ─── Status dot ───────────────────────────────────────────────────────────────

function Dot({ ok, warn }: { ok: boolean; warn?: boolean }) {
    const cls = ok
        ? 'bg-emerald-500'
        : warn
          ? 'bg-amber-400'
          : 'bg-red-500';
    return <span className={`inline-block h-2 w-2 shrink-0 rounded-full ${cls}`} />;
}

// ─── Section wrapper ──────────────────────────────────────────────────────────

function Section({ title, icon: Icon, children }: { title: string; icon: React.ElementType; children: React.ReactNode }) {
    return (
        <div className="rounded-xl border border-zinc-200 bg-white">
            <div className="flex items-center gap-2 border-b border-zinc-100 px-4 py-3">
                <Icon className="h-4 w-4 text-zinc-400" />
                <h2 className="text-sm font-semibold text-zinc-700">{title}</h2>
            </div>
            {children}
        </div>
    );
}

// ─── Queue table ──────────────────────────────────────────────────────────────

// Thresholds from PLANNING section 22.5
const WAIT_WARN_SECONDS: Record<string, number> = {
    'critical-webhooks': 30,
    'sync-facebook':     300,
    'sync-google-ads':   300,
    'sync-google-search':300,
    'sync-store':        300,
    'sync-psi':          600,
    'imports':           1800,
    default:             300,
    low:                 3600,
};

function QueueTable({ queues }: { queues: QueueHealth[] }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-zinc-100 bg-zinc-50 text-left text-xs text-zinc-500">
                        <th className="px-4 py-2 font-medium">Queue</th>
                        <th className="px-4 py-2 font-medium text-right">Depth</th>
                        <th className="px-4 py-2 font-medium text-right">Wait</th>
                        <th className="px-4 py-2 font-medium text-right">Failed</th>
                    </tr>
                </thead>
                <tbody>
                    {queues.map((q) => {
                        const warnThresh = WAIT_WARN_SECONDS[q.queue] ?? WAIT_WARN_SECONDS.default;
                        const waitOk   = q.wait_seconds < warnThresh;
                        const waitWarn = q.wait_seconds >= warnThresh && q.wait_seconds < warnThresh * 3;
                        return (
                            <tr key={q.queue} className="border-b border-zinc-50 last:border-0 hover:bg-zinc-50/50">
                                <td className="px-4 py-2">
                                    <span className="inline-flex items-center gap-1.5 font-mono text-xs">
                                        <Dot ok={q.depth === 0 && q.failed_count === 0} warn={q.depth > 0 && waitOk} />
                                        {q.queue}
                                    </span>
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums text-zinc-600">{q.depth}</td>
                                <td className={`px-4 py-2 text-right tabular-nums font-medium ${waitOk ? 'text-zinc-500' : waitWarn ? 'text-amber-600' : 'text-red-600'}`}>
                                    {q.depth > 0 ? fmtWait(q.wait_seconds) : '—'}
                                </td>
                                <td className={`px-4 py-2 text-right tabular-nums ${q.failed_count > 0 ? 'font-semibold text-red-600' : 'text-zinc-400'}`}>
                                    {q.failed_count > 0 ? q.failed_count : '—'}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

// ─── Store freshness table ─────────────────────────────────────────────────────

function StoreTable({ stores }: { stores: StoreHealth[] }) {
    if (stores.length === 0) {
        return <p className="px-4 py-6 text-center text-sm text-zinc-400">No stores connected.</p>;
    }
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-zinc-100 bg-zinc-50 text-left text-xs text-zinc-500">
                        <th className="px-4 py-2 font-medium">Store</th>
                        <th className="px-4 py-2 font-medium">Workspace</th>
                        <th className="px-4 py-2 font-medium">Status</th>
                        <th className="px-4 py-2 font-medium">Last sync</th>
                        <th className="px-4 py-2 font-medium text-right">Failures</th>
                    </tr>
                </thead>
                <tbody>
                    {stores.map((s) => (
                        <tr key={s.id} className="border-b border-zinc-50 last:border-0 hover:bg-zinc-50/50">
                            <td className="px-4 py-2">
                                <span className="flex items-center gap-1.5">
                                    <Dot ok={!s.is_stale && s.consecutive_sync_failures === 0} warn={s.is_stale} />
                                    <span className="text-zinc-800">{s.name}</span>
                                </span>
                            </td>
                            <td className="px-4 py-2 text-zinc-500">{s.workspace?.name ?? '—'}</td>
                            <td className="px-4 py-2">
                                <span className={`rounded-full px-1.5 py-0.5 text-xs font-medium ${
                                    s.status === 'active' ? 'bg-emerald-50 text-emerald-700'
                                    : s.status === 'error' ? 'bg-red-50 text-red-700'
                                    : 'bg-zinc-100 text-zinc-600'
                                }`}>{s.status}</span>
                            </td>
                            <td className={`px-4 py-2 text-xs ${s.is_stale ? 'text-amber-600 font-medium' : 'text-zinc-500'}`}>
                                {s.last_synced_at ? formatDatetime(s.last_synced_at) : 'Never'}
                            </td>
                            <td className={`px-4 py-2 text-right text-xs ${s.consecutive_sync_failures > 0 ? 'font-semibold text-red-600' : 'text-zinc-400'}`}>
                                {s.consecutive_sync_failures > 0 ? s.consecutive_sync_failures : '—'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ─── Backfill progress ────────────────────────────────────────────────────────

function BackfillTable({ entries }: { entries: BackfillEntry[] }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-zinc-100 bg-zinc-50 text-left text-xs text-zinc-500">
                        <th className="px-4 py-2 font-medium">Workspace</th>
                        <th className="px-4 py-2 font-medium">Status</th>
                        <th className="px-4 py-2 font-medium text-right">Progress</th>
                    </tr>
                </thead>
                <tbody>
                    {entries.map((e) => {
                        const p = e.progress;
                        const pct = p && p.total > 0 ? Math.round((p.processed / p.total) * 100) : null;
                        return (
                            <tr key={e.workspace_id} className="border-b border-zinc-50 last:border-0 hover:bg-zinc-50/50">
                                <td className="px-4 py-2 text-zinc-800">{e.workspace_name}</td>
                                <td className="px-4 py-2">
                                    {p === null ? (
                                        <span className="text-zinc-400 text-xs">Never run</span>
                                    ) : (
                                        <span className={`text-xs font-medium ${
                                            p.status === 'completed' ? 'text-emerald-600'
                                            : p.status === 'failed'  ? 'text-red-600'
                                            : 'text-amber-600'
                                        }`}>{p.status}</span>
                                    )}
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums text-zinc-500 text-xs">
                                    {p ? `${p.processed.toLocaleString()} / ${p.total.toLocaleString()}${pct !== null ? ` (${pct}%)` : ''}` : '—'}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function SystemHealth({
    queues,
    stores,
    null_fx_total,
    null_fx_breakdown,
    backfill_progress,
    running_jobs,
    api_quotas,
}: PageProps<{
    queues: QueueHealth[];
    stores: StoreHealth[];
    null_fx_total: number;
    null_fx_breakdown: NullFxEntry[];
    backfill_progress: BackfillEntry[];
    running_jobs: number;
    api_quotas: Record<string, ApiQuota>;
}>) {
    const stalestores  = stores.filter((s) => s.is_stale || s.consecutive_sync_failures > 0);
    const totalDepth   = queues.reduce((s, q) => s + q.depth, 0);
    const totalFailed  = queues.reduce((s, q) => s + q.failed_count, 0);

    return (
        <AppLayout>
            <Head title="System Health" />

            <div className="mb-6 flex items-start justify-between gap-4">
                <PageHeader
                    title="System Health"
                    subtitle="Queue depth, sync freshness, FX gaps, and backfill progress"
                />
                <div className="mt-1 flex gap-2">
                    <Link
                        href="/admin/queue"
                        className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50"
                    >
                        Queue jobs
                    </Link>
                    <Link
                        href="/admin/logs"
                        className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50"
                    >
                        Logs
                    </Link>
                </div>
            </div>

            {/* ─── KPI strip ─────────────────────────────────────────────── */}
            <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {[
                    { label: 'Running jobs', value: running_jobs, bad: running_jobs > 20 },
                    { label: 'Queue depth', value: totalDepth, bad: totalDepth > 100 },
                    { label: 'Failed jobs', value: totalFailed, bad: totalFailed > 0 },
                    { label: 'NULL FX orders', value: null_fx_total, bad: null_fx_total > 0 },
                ].map(({ label, value, bad }) => (
                    <div key={label} className="rounded-xl border border-zinc-200 bg-white px-4 py-3">
                        <p className="text-xs text-zinc-500">{label}</p>
                        <p className={`mt-0.5 text-2xl font-bold tabular-nums ${bad ? 'text-red-600' : 'text-zinc-800'}`}>
                            {value}
                        </p>
                    </div>
                ))}
            </div>

            <div className="space-y-6">

                {/* ─── Queues ──────────────────────────────────────────────── */}
                <Section title="Queue health" icon={Server}>
                    <QueueTable queues={queues} />
                </Section>

                {/* ─── Store freshness ─────────────────────────────────────── */}
                <Section title={`Store sync freshness${stalestores.length > 0 ? ` — ${stalestores.length} need attention` : ''}`} icon={RefreshCw}>
                    <StoreTable stores={stores} />
                </Section>

                {/* ─── API quotas ───────────────────────────────────────────── */}
                <Section title="API quotas" icon={Wifi}>
                    <div className="divide-y divide-zinc-100">
                        {Object.entries(api_quotas).map(([provider, quota]) => (
                            <div key={provider} className="flex items-center justify-between px-4 py-3 text-sm">
                                <div className="flex items-center gap-2">
                                    <Dot ok={!quota.throttled_until} warn={false} />
                                    <span className="font-medium capitalize text-zinc-700">{provider.replace('_', ' ')}</span>
                                    {quota.throttled_until && (
                                        <span className="text-xs text-red-600">
                                            throttled until {formatDatetime(quota.throttled_until)}
                                        </span>
                                    )}
                                    {quota.usage_pct !== null && quota.usage_pct !== undefined && (
                                        <span className="text-xs text-zinc-400">
                                            {quota.usage_pct}% usage ({quota.tier})
                                        </span>
                                    )}
                                </div>
                                <div className="flex items-center gap-4 text-xs text-zinc-500">
                                    <span>{quota.calls_today} calls today</span>
                                    {quota.hits_today > 0 && (
                                        <span className="font-medium text-amber-600">{quota.hits_today} rate-limit hits</span>
                                    )}
                                    {quota.last_success_at && (
                                        <span>last success {formatDatetime(quota.last_success_at)}</span>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </Section>

                {/* ─── NULL FX ─────────────────────────────────────────────── */}
                <Section title="NULL FX orders" icon={Database}>
                    {null_fx_total === 0 ? (
                        <div className="flex items-center gap-2 px-4 py-4 text-sm text-emerald-600">
                            <CheckCircle2 className="h-4 w-4" />
                            All orders have FX-converted totals.
                        </div>
                    ) : (
                        <div className="px-4 py-3">
                            <p className="mb-3 text-sm text-amber-700">
                                <AlertTriangle className="mr-1 inline h-4 w-4" />
                                {null_fx_total} order(s) missing FX conversion. RetryMissingConversionJob runs nightly.
                            </p>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-zinc-100 text-left text-xs text-zinc-500">
                                            <th className="pb-2 font-medium">Workspace ID</th>
                                            <th className="pb-2 text-right font-medium">NULL orders</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {null_fx_breakdown.map((r) => (
                                            <tr key={r.workspace_id} className="border-b border-zinc-50 last:border-0">
                                                <td className="py-1.5 font-mono text-xs text-zinc-600">{r.workspace_id}</td>
                                                <td className="py-1.5 text-right font-semibold text-red-600 tabular-nums">{r.null_count}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </Section>

                {/* ─── Attribution backfill ────────────────────────────────── */}
                <Section title="Attribution backfill progress" icon={Activity}>
                    <BackfillTable entries={backfill_progress} />
                </Section>

            </div>
        </AppLayout>
    );
}
