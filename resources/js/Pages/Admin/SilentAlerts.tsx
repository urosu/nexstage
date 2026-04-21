import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, HelpCircle, ShieldAlert, ThumbsDown, ThumbsUp, TrendingUp } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatDatetime } from '@/lib/formatters';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface SilentAlert {
    id: number;
    workspace: { id: number; name: string } | null;
    store: { id: number; name: string } | null;
    type: string;
    severity: string | null;
    source: string | null;
    data: Record<string, unknown> | null;
    review_status: 'tp' | 'fp' | 'unclear' | null;
    reviewed_at: string | null;
    estimated_impact_low: number | null;
    estimated_impact_high: number | null;
    created_at: string;
}

interface PaginatedAlerts {
    data: SilentAlert[];
    current_page: number;
    last_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface TabCounts {
    unreviewed: number;
    tp: number;
    fp: number;
    unclear: number;
}

interface Graduation {
    total_reviewed: number;
    tp_rate: number | null;
    threshold_met: boolean;
}

// ─── Review status badge ──────────────────────────────────────────────────────

function ReviewBadge({ status }: { status: 'tp' | 'fp' | 'unclear' | null }) {
    if (!status) return <span className="text-zinc-300 text-xs">Unreviewed</span>;
    const map = {
        tp:      { cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200', label: 'True Positive' },
        fp:      { cls: 'bg-red-50 text-red-700 ring-red-200',             label: 'False Positive' },
        unclear: { cls: 'bg-amber-50 text-amber-700 ring-amber-200',       label: 'Unclear' },
    };
    const { cls, label } = map[status];
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${cls}`}>
            {label}
        </span>
    );
}

// ─── Severity badge ───────────────────────────────────────────────────────────

function SeverityBadge({ severity }: { severity: string | null }) {
    if (!severity) return null;
    const map: Record<string, string> = {
        critical: 'bg-red-100 text-red-700',
        high:     'bg-orange-100 text-orange-700',
        medium:   'bg-amber-100 text-amber-700',
        low:      'bg-zinc-100 text-zinc-600',
    };
    const cls = map[severity.toLowerCase()] ?? 'bg-zinc-100 text-zinc-600';
    return <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${cls}`}>{severity}</span>;
}

// ─── Review action buttons ────────────────────────────────────────────────────

function ReviewButtons({ alert, onReview }: {
    alert: SilentAlert;
    onReview: (id: number, status: 'tp' | 'fp' | 'unclear') => void;
}) {
    const [pending, setPending] = useState<string | null>(null);

    const handle = (status: 'tp' | 'fp' | 'unclear') => {
        setPending(status);
        onReview(alert.id, status);
    };

    const btn = (status: 'tp' | 'fp' | 'unclear', label: string, icon: React.ReactNode, colors: string) => (
        <button
            disabled={pending !== null}
            onClick={() => handle(status)}
            className={`inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-50 ${
                alert.review_status === status
                    ? colors.replace('border-zinc-200', 'border-current').replace('text-zinc-600', '').replace('hover:bg-zinc-50', '')
                    : `border-zinc-200 text-zinc-600 hover:bg-zinc-50 ${colors}`
            }`}
        >
            {pending === status ? <span className="h-3 w-3 animate-spin rounded-full border border-current border-t-transparent" /> : icon}
            {label}
        </button>
    );

    return (
        <div className="flex items-center gap-1.5">
            {btn('tp',      'TP',      <ThumbsUp  className="h-3 w-3" />, 'hover:border-emerald-400 hover:text-emerald-700')}
            {btn('fp',      'FP',      <ThumbsDown className="h-3 w-3" />, 'hover:border-red-400 hover:text-red-700')}
            {btn('unclear', 'Unclear', <HelpCircle className="h-3 w-3" />, 'hover:border-amber-400 hover:text-amber-700')}
        </div>
    );
}

// ─── Tab bar ──────────────────────────────────────────────────────────────────

const TABS = ['unreviewed', 'tp', 'fp', 'unclear'] as const;
type Tab = typeof TABS[number];
const TAB_LABELS: Record<Tab, string> = {
    unreviewed: 'Unreviewed',
    tp:         'True Positive',
    fp:         'False Positive',
    unclear:    'Unclear',
};

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function SilentAlerts({
    alerts,
    tab,
    counts,
    graduation,
}: PageProps<{
    alerts: PaginatedAlerts;
    tab: Tab;
    counts: TabCounts;
    graduation: Graduation;
}>) {
    const handleReview = (alertId: number, status: 'tp' | 'fp' | 'unclear') => {
        router.patch(`/admin/alerts/${alertId}/review`, { review_status: status }, {
            preserveScroll: true,
            preserveState: false,
        });
    };

    return (
        <AppLayout>
            <Head title="Silent Alerts" />

            <div className="mb-6 flex items-start justify-between gap-4">
                <PageHeader
                    title="Silent Alerts"
                    subtitle="Review stored alerts before enabling delivery. ≥70% TP on ≥20 reviews over ≥4 weeks graduates silent mode."
                />
                <Link
                    href="/admin/system-health"
                    className="mt-1 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-50"
                >
                    System health
                </Link>
            </div>

            {/* Graduation status */}
            <div className={`mb-6 rounded-xl border px-4 py-3 ${
                graduation.threshold_met
                    ? 'border-emerald-200 bg-emerald-50'
                    : 'border-zinc-200 bg-zinc-50'
            }`}>
                <div className="flex items-center gap-2">
                    {graduation.threshold_met ? (
                        <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                    ) : (
                        <TrendingUp className="h-4 w-4 text-zinc-400" />
                    )}
                    <span className="text-sm font-medium text-zinc-700">
                        Graduation progress —{' '}
                        {graduation.total_reviewed} reviewed
                        {graduation.tp_rate !== null && `, ${graduation.tp_rate}% TP rate`}
                    </span>
                    {graduation.threshold_met && (
                        <span className="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">
                            Threshold met — ready to graduate
                        </span>
                    )}
                    {!graduation.threshold_met && graduation.total_reviewed < 20 && (
                        <span className="text-xs text-zinc-400">
                            ({Math.max(0, 20 - graduation.total_reviewed)} more reviews needed for graduation eligibility)
                        </span>
                    )}
                </div>
            </div>

            {/* Tabs */}
            <div className="mb-4 flex items-center gap-1 border-b border-zinc-200">
                {TABS.map((t) => (
                    <Link
                        key={t}
                        href={`/admin/silent-alerts?tab=${t}`}
                        className={`relative -mb-px flex items-center gap-1.5 px-3 py-2 text-sm transition-colors ${
                            tab === t
                                ? 'border-b-2 border-primary font-semibold text-primary'
                                : 'text-zinc-500 hover:text-zinc-700'
                        }`}
                    >
                        {TAB_LABELS[t]}
                        <span className={`rounded-full px-1.5 py-0.5 text-xs font-medium ${
                            tab === t ? 'bg-primary/10 text-primary' : 'bg-zinc-100 text-zinc-500'
                        }`}>
                            {counts[t]}
                        </span>
                    </Link>
                ))}
            </div>

            {/* Alert list */}
            {alerts.data.length === 0 ? (
                <div className="rounded-xl border border-zinc-200 bg-white py-16 text-center">
                    <ShieldAlert className="mx-auto mb-3 h-8 w-8 text-zinc-300" />
                    <p className="text-sm text-zinc-400">No alerts in this category.</p>
                </div>
            ) : (
                <div className="space-y-3">
                    {alerts.data.map((alert) => (
                        <div key={alert.id} className="rounded-xl border border-zinc-200 bg-white p-4">
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0 flex-1">
                                    <div className="mb-1.5 flex flex-wrap items-center gap-2">
                                        <span className="font-mono text-sm font-semibold text-zinc-800">{alert.type}</span>
                                        <SeverityBadge severity={alert.severity} />
                                        <ReviewBadge status={alert.review_status} />
                                    </div>
                                    <div className="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500">
                                        {alert.workspace && <span>Workspace: {alert.workspace.name}</span>}
                                        {alert.store    && <span>Store: {alert.store.name}</span>}
                                        {alert.source   && <span>Source: {alert.source}</span>}
                                        <span>{formatDatetime(alert.created_at)}</span>
                                    </div>
                                    {(alert.estimated_impact_low !== null || alert.estimated_impact_high !== null) && (
                                        <div className="mb-2 text-xs text-zinc-500">
                                            Estimated impact: {alert.estimated_impact_low ?? '?'} – {alert.estimated_impact_high ?? '?'}
                                        </div>
                                    )}
                                    {alert.data && Object.keys(alert.data).length > 0 && (
                                        <details className="mt-2">
                                            <summary className="cursor-pointer text-xs text-zinc-400 hover:text-zinc-600">
                                                Raw data
                                            </summary>
                                            <pre className="mt-1 overflow-x-auto rounded bg-zinc-50 p-2 text-xs text-zinc-600">
                                                {JSON.stringify(alert.data, null, 2)}
                                            </pre>
                                        </details>
                                    )}
                                </div>
                                <ReviewButtons alert={alert} onReview={handleReview} />
                            </div>
                            {alert.reviewed_at && (
                                <p className="mt-2 text-xs text-zinc-400">
                                    Reviewed {formatDatetime(alert.reviewed_at)}
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {/* Pagination */}
            {alerts.last_page > 1 && (
                <div className="mt-6 flex justify-center gap-1">
                    {alerts.links.map((link, i) => (
                        <Link
                            key={i}
                            href={link.url ?? '#'}
                            className={`rounded px-3 py-1 text-sm ${
                                link.active
                                    ? 'bg-primary text-white'
                                    : link.url
                                      ? 'border border-zinc-200 text-zinc-600 hover:bg-zinc-50'
                                      : 'cursor-default border border-zinc-100 text-zinc-300'
                            }`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
