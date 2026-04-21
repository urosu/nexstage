import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Clock, Loader2, ShieldAlert } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatDatetime } from '@/lib/formatters';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface RunningJob {
    id: number;
    workspace: { id: number; name: string } | null;
    job_type: string;
    queue: string | null;
    attempt: number;
    records_processed: number | null;
    started_at: string | null;
}

interface PendingJob {
    id: number;
    queue: string;
    display_name: string;
    attempts: number;
    available_at: string;
    created_at: string;
}

interface FailedJob {
    id: number;
    uuid: string;
    queue: string;
    display_name: string;
    exception: string;
    failed_at: string;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function shortJobType(jobType: string): string {
    return jobType.split('\\').pop() ?? jobType;
}

function elapsedSeconds(startedAt: string | null): string {
    if (!startedAt) return '—';
    const secs = Math.floor((Date.now() - new Date(startedAt).getTime()) / 1000);
    if (secs < 60) return `${secs}s`;
    return `${Math.floor(secs / 60)}m ${secs % 60}s`;
}

// ─── Queue badge ──────────────────────────────────────────────────────────────

const QUEUE_COLORS: Record<string, string> = {
    critical: 'bg-red-50 text-red-700 ring-red-200',
    high:     'bg-orange-50 text-orange-700 ring-orange-200',
    default:  'bg-blue-50 text-blue-700 ring-blue-200',
    low:      'bg-zinc-100 text-zinc-600 ring-zinc-200',
};

function QueueBadge({ queue }: { queue: string | null }) {
    if (!queue) return <span className="text-zinc-300">—</span>;
    const cls = QUEUE_COLORS[queue] ?? QUEUE_COLORS.low;
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${cls}`}>
            {queue}
        </span>
    );
}

// ─── Delayed indicator ────────────────────────────────────────────────────────

function AvailableAt({ iso }: { iso: string }) {
    const isDelayed = new Date(iso) > new Date();
    return (
        <span className={isDelayed ? 'font-medium text-amber-600' : 'text-zinc-500'}>
            {isDelayed && <Clock className="mr-1 inline h-3 w-3" />}
            {formatDatetime(iso)}
        </span>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function Queue({
    running,
    pending,
    failed_queue,
}: PageProps<{
    running: RunningJob[];
    pending: PendingJob[];
    failed_queue: FailedJob[];
}>) {
    const [expandedUuid, setExpandedUuid] = useState<string | null>(null);

    return (
        <AppLayout>
            <Head title="Admin Queue" />

            <div className="mb-6 flex items-start justify-between gap-4">
                <PageHeader
                    title="Queue"
                    subtitle="Running jobs, pending jobs, and permanently failed jobs"
                />
                <div className="mt-1 flex gap-2">
                    <Link
                        href="/admin/logs"
                        className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50"
                    >
                        ← Logs
                    </Link>
                    <a
                        href="/horizon"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50"
                    >
                        Open Horizon ↗
                    </a>
                </div>
            </div>

            <div className="mb-6 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
                <ShieldAlert className="h-4 w-4 shrink-0" />
                Super admin panel. Running jobs are sourced from sync_logs. Pending jobs are sourced from the Horizon jobs table. Delayed jobs (<Clock className="inline h-3 w-3" />) have a future available_at.
            </div>

            {/* ── Running jobs ─────────────────────────────────────────────── */}
            <section className="mb-8">
                <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold text-zinc-700">
                    {running.length > 0 && <Loader2 className="h-4 w-4 animate-spin text-blue-500" />}
                    Running <span className="ml-1 text-zinc-400">({running.length})</span>
                </h2>

                {running.length === 0 ? (
                    <div className="rounded-xl border border-zinc-200 bg-white px-4 py-8 text-center text-sm text-zinc-400">
                        No jobs currently running.
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-blue-100 bg-white">
                        <table className="w-full text-sm">
                            <thead className="border-b border-blue-100 bg-blue-50 text-xs font-medium uppercase tracking-wide text-blue-400">
                                <tr>
                                    <th className="px-4 py-2.5 text-left">Job</th>
                                    <th className="px-4 py-2.5 text-left">Workspace</th>
                                    <th className="px-4 py-2.5 text-left">Queue</th>
                                    <th className="px-4 py-2.5 text-left">Attempt</th>
                                    <th className="px-4 py-2.5 text-left">Records so far</th>
                                    <th className="px-4 py-2.5 text-left">Started</th>
                                    <th className="px-4 py-2.5 text-left">Elapsed</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-blue-50">
                                {running.map((job) => (
                                    <tr key={job.id} className="hover:bg-blue-50">
                                        <td className="px-4 py-2.5">
                                            <span className="font-mono text-xs text-zinc-700">{shortJobType(job.job_type)}</span>
                                        </td>
                                        <td className="px-4 py-2.5 text-zinc-600 text-xs">
                                            {job.workspace?.name ?? <span className="text-zinc-300">—</span>}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <QueueBadge queue={job.queue} />
                                        </td>
                                        <td className="px-4 py-2.5 text-zinc-500 text-xs">
                                            {job.attempt > 1
                                                ? <span className="font-medium text-amber-600">#{job.attempt}</span>
                                                : <span className="text-zinc-400">#{job.attempt}</span>
                                            }
                                        </td>
                                        <td className="px-4 py-2.5 text-zinc-600 text-xs">
                                            {job.records_processed ?? '—'}
                                        </td>
                                        <td className="px-4 py-2.5 text-zinc-500 text-xs">
                                            {job.started_at ? formatDatetime(job.started_at) : '—'}
                                        </td>
                                        <td className="px-4 py-2.5 text-blue-600 text-xs font-medium">
                                            {elapsedSeconds(job.started_at)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {/* ── Pending jobs ─────────────────────────────────────────────── */}
            <section className="mb-8">
                <h2 className="mb-3 text-sm font-semibold text-zinc-700">
                    Pending <span className="ml-1 text-zinc-400">({pending.length})</span>
                </h2>

                {pending.length === 0 ? (
                    <div className="rounded-xl border border-zinc-200 bg-white px-4 py-8 text-center text-sm text-zinc-400">
                        Queue is empty — all caught up.
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white">
                        <table className="w-full text-sm">
                            <thead className="border-b border-zinc-100 bg-zinc-50 th-label">
                                <tr>
                                    <th className="px-4 py-2.5 text-left">Job</th>
                                    <th className="px-4 py-2.5 text-left">Queue</th>
                                    <th className="px-4 py-2.5 text-left">Attempts</th>
                                    <th className="px-4 py-2.5 text-left">Available at</th>
                                    <th className="px-4 py-2.5 text-left">Queued at</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100">
                                {pending.map((job) => (
                                    <tr key={job.id} className="hover:bg-zinc-50">
                                        <td className="px-4 py-2.5">
                                            <span className="font-mono text-xs text-zinc-700">{job.display_name}</span>
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <QueueBadge queue={job.queue} />
                                        </td>
                                        <td className="px-4 py-2.5 text-zinc-500 text-xs">
                                            {job.attempts > 0
                                                ? <span className="font-medium text-amber-600">{job.attempts} prior</span>
                                                : <span className="text-zinc-400">first run</span>
                                            }
                                        </td>
                                        <td className="px-4 py-2.5 text-xs">
                                            <AvailableAt iso={job.available_at} />
                                        </td>
                                        <td className="px-4 py-2.5 text-zinc-500 text-xs">
                                            {formatDatetime(job.created_at)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {/* ── Failed jobs ───────────────────────────────────────────────── */}
            <section>
                <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold text-zinc-700">
                    {failed_queue.length > 0 && <AlertTriangle className="h-4 w-4 text-red-500" />}
                    Failed <span className="ml-1 text-zinc-400">({failed_queue.length})</span>
                </h2>

                {failed_queue.length === 0 ? (
                    <div className="rounded-xl border border-zinc-200 bg-white px-4 py-8 text-center text-sm text-zinc-400">
                        No failed jobs.
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-red-100 bg-white">
                        <table className="w-full text-sm">
                            <thead className="border-b border-red-100 bg-red-50 text-xs font-medium uppercase tracking-wide text-red-400">
                                <tr>
                                    <th className="px-4 py-2.5 text-left">Job</th>
                                    <th className="px-4 py-2.5 text-left">Queue</th>
                                    <th className="px-4 py-2.5 text-left">Failed at</th>
                                    <th className="px-4 py-2.5 text-left">UUID</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-red-50">
                                {failed_queue.map((job) => {
                                    const isExpanded = expandedUuid === job.uuid;
                                    return (
                                        <>
                                            <tr
                                                key={job.uuid}
                                                onClick={() => setExpandedUuid(isExpanded ? null : job.uuid)}
                                                className="cursor-pointer hover:bg-red-50"
                                            >
                                                <td className="px-4 py-2.5">
                                                    <span className="font-mono text-xs text-zinc-700">{job.display_name}</span>
                                                    <div className="mt-0.5 text-xs italic text-zinc-400">
                                                        Click to {isExpanded ? 'collapse' : 'expand'} exception
                                                    </div>
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <QueueBadge queue={job.queue} />
                                                </td>
                                                <td className="px-4 py-2.5 text-zinc-500 text-xs">
                                                    {formatDatetime(job.failed_at)}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <span className="font-mono text-xs text-zinc-400">{job.uuid}</span>
                                                </td>
                                            </tr>
                                            {isExpanded && (
                                                <tr key={`${job.uuid}-err`} className="bg-red-50">
                                                    <td colSpan={4} className="px-4 py-3">
                                                        <pre className="whitespace-pre-wrap break-all font-mono text-xs text-red-700">
                                                            {job.exception}
                                                        </pre>
                                                    </td>
                                                </tr>
                                            )}
                                        </>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </AppLayout>
    );
}
