import { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Bot, Bell, CheckCircle, Eye, AlertTriangle, Info } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatDate, formatDatetime } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface AiSummaryData {
    id: number;
    date: string;
    summary_text: string;
    model_used: string | null;
    generated_at: string;
}

interface AlertRow {
    id: number;
    type: string;
    severity: 'info' | 'warning' | 'critical';
    store_name: string | null;
    ad_account_name: string | null;
    data: Record<string, unknown> | null;
    read_at: string | null;
    resolved_at: string | null;
    created_at: string;
}

interface PaginatedAlerts {
    data: AlertRow[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Props {
    ai_summaries: AiSummaryData[];
    alerts: PaginatedAlerts;
    filters: { severity: string; status: string };
}

const SEVERITY_COLORS = {
    info:     'bg-indigo-100 text-indigo-700',
    warning:  'bg-amber-100 text-amber-700',
    critical: 'bg-red-100 text-red-700',
} as const;

const SEVERITY_ICONS = {
    info:     Info,
    warning:  AlertTriangle,
    critical: AlertTriangle,
} as const;

function formatAlertType(type: string): string {
    return type
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function Insights({ ai_summaries, alerts, filters }: Props) {
    const { workspace } = usePage<PageProps>().props;
    const timezone = workspace?.reporting_timezone;
    const [navigating, setNavigating] = useState(false);

    useEffect(() => {
        const removeStart  = router.on('start',  () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));
        return () => { removeStart(); removeFinish(); };
    }, []);

    function applyFilter(key: string, value: string): void {
        router.get('/insights', { ...filters, [key]: value }, { preserveState: true, replace: true });
    }

    function markRead(alertId: number): void {
        router.post(`/insights/alerts/${alertId}/read`, {}, { preserveScroll: true });
    }

    function resolve(alertId: number): void {
        router.post(`/insights/alerts/${alertId}/resolve`, {}, { preserveScroll: true });
    }

    const severityTabs = ['all', 'info', 'warning', 'critical'] as const;
    const statusTabs   = ['all', 'unread', 'unresolved'] as const;

    return (
        <AppLayout>
            <Head title="Insights" />
            <PageHeader title="Insights" subtitle="AI summaries and system alerts" />

            {/* ── AI Summaries ──────────────────────────────────────────────── */}
            <section className="mb-8">
                <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-400">
                    AI Daily Summaries
                </h2>

                {ai_summaries.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-12 text-center">
                        <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-zinc-100">
                            <Bot className="h-5 w-5 text-zinc-400" />
                        </div>
                        <p className="text-sm font-medium text-zinc-900">No summaries yet</p>
                        <p className="mt-1 text-xs text-zinc-400">
                            AI summaries are generated nightly once store data is available.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {ai_summaries.map((s) => (
                            <div key={s.id} className="rounded-xl border border-zinc-200 bg-white p-5">
                                <div className="mb-2 flex items-center justify-between gap-3">
                                    <div className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                                        <Bot className="h-3.5 w-3.5" />
                                        {(() => {
                                            const d = new Date(s.date);
                                            const opts = timezone ? { timeZone: timezone } : {};
                                            const weekday = new Intl.DateTimeFormat('en', { ...opts, weekday: 'short' }).format(d);
                                            const day     = new Intl.DateTimeFormat('en', { ...opts, day: 'numeric' }).format(d);
                                            const month   = new Intl.DateTimeFormat('en', { ...opts, month: 'numeric' }).format(d);
                                            const year    = new Intl.DateTimeFormat('en', { ...opts, year: 'numeric' }).format(d);
                                            return `${weekday} ${day}.${month}.${year}`;
                                        })()}
                                    </div>
                                    {s.model_used && (
                                        <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-400">
                                            {s.model_used}
                                        </span>
                                    )}
                                </div>
                                <p className="text-sm leading-relaxed text-zinc-700">{s.summary_text}</p>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            {/* ── Alert Feed ────────────────────────────────────────────────── */}
            <section>
                <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-400">
                    Alert Feed
                    {alerts.total > 0 && (
                        <span className="ml-2 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-normal text-zinc-500">
                            {alerts.total}
                        </span>
                    )}
                </h2>

                {/* Filter bar */}
                <div className="mb-4 flex flex-wrap gap-3">
                    {/* Severity tabs */}
                    <div className="flex rounded-lg border border-zinc-200 bg-white p-0.5">
                        {severityTabs.map((s) => (
                            <button
                                key={s}
                                onClick={() => applyFilter('severity', s)}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-xs font-medium capitalize transition-colors',
                                    filters.severity === s
                                        ? 'bg-zinc-900 text-white'
                                        : 'text-zinc-500 hover:text-zinc-900',
                                )}
                            >
                                {s}
                            </button>
                        ))}
                    </div>

                    {/* Status tabs */}
                    <div className="flex rounded-lg border border-zinc-200 bg-white p-0.5">
                        {statusTabs.map((s) => (
                            <button
                                key={s}
                                onClick={() => applyFilter('status', s)}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-xs font-medium capitalize transition-colors',
                                    filters.status === s
                                        ? 'bg-zinc-900 text-white'
                                        : 'text-zinc-500 hover:text-zinc-900',
                                )}
                            >
                                {s}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Alert list */}
                {navigating ? (
                    <div className="space-y-2">
                        {[...Array(5)].map((_, i) => (
                            <div key={i} className="h-14 animate-pulse rounded-xl bg-zinc-100" />
                        ))}
                    </div>
                ) : alerts.data.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-12 text-center">
                        <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-zinc-100">
                            <Bell className="h-5 w-5 text-zinc-400" />
                        </div>
                        <p className="text-sm font-medium text-zinc-900">No alerts</p>
                        <p className="mt-1 text-xs text-zinc-400">
                            Alerts appear here when sync failures or token issues are detected.
                        </p>
                    </div>
                ) : (
                    <div className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
                        <ul className="divide-y divide-zinc-100">
                            {alerts.data.map((alert) => {
                                const SeverityIcon = SEVERITY_ICONS[alert.severity];
                                const isResolved = Boolean(alert.resolved_at);
                                const isRead     = Boolean(alert.read_at);

                                return (
                                    <li
                                        key={alert.id}
                                        className={cn(
                                            'flex items-start gap-3 px-4 py-3 transition-colors',
                                            isResolved ? 'opacity-50' : 'hover:bg-zinc-50',
                                        )}
                                    >
                                        {/* Severity icon */}
                                        <div className={cn('mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full', SEVERITY_COLORS[alert.severity])}>
                                            <SeverityIcon className="h-3.5 w-3.5" />
                                        </div>

                                        {/* Content */}
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                <span className={cn(
                                                    'text-sm font-medium',
                                                    isResolved ? 'text-zinc-400 line-through' : 'text-zinc-900',
                                                )}>
                                                    {formatAlertType(alert.type)}
                                                </span>
                                                <span className={cn(
                                                    'rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase',
                                                    SEVERITY_COLORS[alert.severity],
                                                )}>
                                                    {alert.severity}
                                                </span>
                                                {!isRead && !isResolved && (
                                                    <span className="h-1.5 w-1.5 rounded-full bg-indigo-500" title="Unread" />
                                                )}
                                            </div>
                                            {(alert.store_name ?? alert.ad_account_name) && (
                                                <p className="mt-0.5 text-xs text-zinc-400">
                                                    {alert.store_name ?? alert.ad_account_name}
                                                </p>
                                            )}
                                            <p className="mt-0.5 text-xs text-zinc-400">
                                                {formatDatetime(alert.created_at)}
                                            </p>
                                        </div>

                                        {/* Actions */}
                                        {!isResolved && (
                                            <div className="flex shrink-0 items-center gap-1">
                                                {!isRead && (
                                                    <button
                                                        onClick={() => markRead(alert.id)}
                                                        className="flex items-center gap-1 rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 transition-colors"
                                                        title="Mark as read"
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                        Read
                                                    </button>
                                                )}
                                                <button
                                                    onClick={() => resolve(alert.id)}
                                                    className="flex items-center gap-1 rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 transition-colors"
                                                    title="Mark as resolved"
                                                >
                                                    <CheckCircle className="h-3.5 w-3.5" />
                                                    Resolve
                                                </button>
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}

                {/* Pagination */}
                {alerts.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-between text-sm text-zinc-500">
                        <span>
                            Page {alerts.current_page} of {alerts.last_page}
                        </span>
                        <div className="flex gap-2">
                            {alerts.current_page > 1 && (
                                <button
                                    onClick={() => router.get('/insights', { ...filters, page: alerts.current_page - 1 })}
                                    className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium hover:bg-zinc-50 transition-colors"
                                >
                                    Previous
                                </button>
                            )}
                            {alerts.current_page < alerts.last_page && (
                                <button
                                    onClick={() => router.get('/insights', { ...filters, page: alerts.current_page + 1 })}
                                    className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium hover:bg-zinc-50 transition-colors"
                                >
                                    Next
                                </button>
                            )}
                        </div>
                    </div>
                )}
            </section>
        </AppLayout>
    );
}
