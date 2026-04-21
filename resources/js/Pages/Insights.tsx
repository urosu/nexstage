import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level lets us start with
// navigating=true so the skeleton stays visible until the real data is ready.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import { Bot, Bell, CheckCircle, AlertTriangle, Info, NotebookPen, FileDown, ExternalLink } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatDate, formatDatetime } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
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

interface DailyNoteRow {
    id: number;
    date: string;
    note: string;
}

interface PaginatedAlerts {
    data: AlertRow[];
    current_page: number;
    last_page: number;
    total: number;
}

interface ReportMonthOption {
    year: number;
    month: number;
    label: string;
}

interface Props {
    ai_summaries: AiSummaryData[];
    daily_notes: DailyNoteRow[];
    alerts: PaginatedAlerts;
    report_months: ReportMonthOption[];
    filters: { severity: string; status: string };
}

// ─── Inline note editor ───────────────────────────────────────────────────────

function InsightNoteCell({ id, date, note }: DailyNoteRow) {
    const [value, setValue] = useState(note);
    const [saving, setSaving] = useState(false);
    const [savedFlash, setSavedFlash] = useState(false);
    const lastSavedRef  = useRef(note);
    const focusedRef    = useRef(false);
    const flashTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => () => { if (flashTimerRef.current) clearTimeout(flashTimerRef.current); }, []);

    function save(current: string): void {
        if (current === lastSavedRef.current) return;
        setSaving(true);
        axios
            .post(`/analytics/notes/${date}`, { note: current })
            .then(() => {
                lastSavedRef.current = current;
                setSavedFlash(true);
                if (flashTimerRef.current) clearTimeout(flashTimerRef.current);
                flashTimerRef.current = setTimeout(() => setSavedFlash(false), 2000);
            })
            .catch(() => setValue(lastSavedRef.current))
            .finally(() => setSaving(false));
    }

    return (
        <div key={id} className="flex gap-4 rounded-lg border border-zinc-100 px-4 py-3 transition-colors hover:bg-zinc-50">
            <div className="w-20 shrink-0 pt-0.5">
                <span className="text-xs font-medium text-zinc-500">
                    {(() => {
                        const d = new Date(date);
                        return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'numeric' });
                    })()}
                </span>
            </div>
            <div className="relative min-w-0 flex-1">
                <textarea
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    onFocus={() => { focusedRef.current = true; }}
                    onBlur={(e) => {
                        focusedRef.current = false;
                        save(e.currentTarget.value);
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Escape') {
                            setValue(lastSavedRef.current);
                            e.currentTarget.blur();
                        }
                    }}
                    maxLength={1000}
                    rows={1}
                    className="w-full resize-none rounded border border-transparent bg-transparent px-0 py-0 text-sm text-zinc-700 outline-none transition-colors placeholder:text-zinc-300 hover:border-zinc-200 hover:bg-white hover:px-2 focus:border-primary/40 focus:bg-white focus:px-2 focus:shadow-sm"
                />
                {saving     && <span className="absolute right-0 top-0 text-[10px] text-zinc-400">saving…</span>}
                {!saving && savedFlash && <span className="absolute right-0 top-0 text-[10px] text-green-500">saved</span>}
            </div>
        </div>
    );
}

const SEVERITY_COLORS = {
    info:     'bg-primary/15 text-primary',
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

interface AlertMeta {
    description: string;
    actionLabel?: string;
    actionPath?: string;
}

const ALERT_META: Record<string, AlertMeta> = {
    reconciliation_discrepancy: {
        description: 'Local order records don\'t match WooCommerce. Usually caused by missed webhooks. Go to Integrations and trigger a manual sync to force a full re-check now.',
        actionLabel: 'View Integrations',
        actionPath: '/settings/integrations',
    },
    webhook_stale: {
        description: 'No webhook events received for over 48 hours. Orders are falling back to hourly polling, which may delay data by up to an hour. Go to Integrations and verify webhook configuration.',
        actionLabel: 'View Integrations',
        actionPath: '/settings/integrations',
    },
    sync_failure: {
        description: 'Order sync failed. Check that your store API credentials are valid and the store is reachable.',
        actionLabel: 'View Integrations',
        actionPath: '/settings/integrations',
    },
    gsc_token_expired: {
        description: 'Your Google Search Console access token has expired. Search traffic data won\'t update until you reconnect. Go to Integrations and reconnect your Google account.',
        actionLabel: 'Reconnect Google',
        actionPath: '/settings/integrations',
    },
    google_token_expired: {
        description: 'Your Google Ads access token has expired. Ad spend and performance data won\'t update until you reconnect. Go to Integrations and reconnect your Google account.',
        actionLabel: 'Reconnect Google',
        actionPath: '/settings/integrations',
    },
    google_account_disabled: {
        description: 'Your Google Ads account has been disabled or suspended by Google. Ad sync is paused until the account is re-enabled. Check your Google Ads account status.',
        actionLabel: 'View Integrations',
        actionPath: '/settings/integrations',
    },
    import_failed: {
        description: 'Historical order import failed. Your recent orders are still synced via webhooks, but historical data may be incomplete. Try re-importing from Integrations.',
        actionLabel: 'View Integrations',
        actionPath: '/settings/integrations',
    },
    gsc_import_failed: {
        description: 'Google Search Console historical import failed. Organic search data may be incomplete. You can retry the import from Integrations.',
        actionLabel: 'View Integrations',
        actionPath: '/settings/integrations',
    },
    gsc_sync_failure: {
        description: 'Google Search Console daily sync failed. Search traffic data may be out of date. If this persists, try reconnecting your Google account.',
        actionLabel: 'Reconnect Google',
        actionPath: '/settings/integrations',
    },
    google_import_failed: {
        description: 'Google Ads historical import failed. Ad performance history may be incomplete. Check your account connection and retry from Integrations.',
        actionLabel: 'View Integrations',
        actionPath: '/settings/integrations',
    },
    facebook_import_failed: {
        description: 'Facebook Ads historical import failed. Ad performance history may be incomplete. Check your account connection and retry from Integrations.',
        actionLabel: 'View Integrations',
        actionPath: '/settings/integrations',
    },
    facebook_sync_failure: {
        description: 'Facebook Ads sync failed. Ad spend data may be out of date. Check that your Facebook ad account connection is still valid.',
        actionLabel: 'Reconnect Facebook',
        actionPath: '/settings/integrations',
    },
    facebook_token_expired: {
        description: "Your Facebook Ads access token has expired. Ad spend and performance data won't update until you reconnect. Go to Integrations and reconnect your Facebook account.",
        actionLabel: 'Reconnect Facebook',
        actionPath: '/settings/integrations',
    },
    token_expiring_soon: {
        description: "Your ad account access token is expiring soon. Reconnect now to avoid a gap in ad data.",
        actionLabel: 'View Integrations',
        actionPath: '/settings/integrations',
    },
    product_out_of_stock: {
        description: 'One or more products went out of stock. Revenue from these products will stop until inventory is restocked.',
    },
    product_back_in_stock: {
        description: 'A product is back in stock and available for sale again.',
    },
    store_sync_recovered: {
        description: 'Store sync has recovered. Orders are syncing normally again.',
    },
    revenue_spike: {
        description: 'Unusual revenue spike detected. This may indicate a successful promotion or a data anomaly — worth a quick review.',
    },
};

function renderAlertDetail(alert: AlertRow): string | null {
    const d = alert.data;
    if (!d) return null;
    switch (alert.type) {
        case 'reconciliation_discrepancy': {
            const count      = d.discrepancy_count as number;
            const total      = d.wc_count as number;
            const rate       = d.rate_pct as number;
            const backfilled = d.backfilled as number | undefined;
            const updated    = d.updated as number | undefined;
            if (count == null || total == null) return null;
            const base = `${count} of ${total} orders out of sync (${(+rate).toFixed(1)}%)`;
            if (backfilled != null && updated != null) {
                return `${base} — ${backfilled} missing, ${updated} stale`;
            }
            return base;
        }
        case 'webhook_stale':
            return typeof d.message === 'string' ? d.message : null;
        case 'sync_failure': {
            const failures = d.failures as number;
            const error    = d.error as string | undefined;
            const base = `${failures} consecutive failure${failures !== 1 ? 's' : ''}`;
            return error ? `${base} — ${error}` : base;
        }
        case 'google_account_disabled':
            return typeof d.reason === 'string' ? d.reason : null;
        case 'gsc_sync_failure': {
            const failures = d.consecutive_failures as number | undefined;
            const url      = d.property_url as string | undefined;
            if (!url && !failures) return null;
            const parts = [];
            if (url) parts.push(url);
            if (failures != null) parts.push(`${failures} consecutive failure${failures !== 1 ? 's' : ''}`);
            return parts.join(' — ');
        }
        case 'gsc_import_failed': {
            const url   = d.property_url as string | undefined;
            const error = d.error as string | undefined;
            if (!url && !error) return null;
            return [url, error].filter(Boolean).join(' — ');
        }
        case 'import_failed':
        case 'google_import_failed':
        case 'facebook_import_failed':
            return typeof d.error === 'string' ? d.error : null;
        default:
            return null;
    }
}

export default function Insights({ ai_summaries, daily_notes, alerts, report_months, filters }: Props) {
    const { workspace } = usePage<PageProps>().props;
    const timezone = workspace?.reporting_timezone;
    const [navigating, setNavigating] = useState(() => _inertiaNavigating);

    useEffect(() => {
        const removeStart  = router.on('start',  () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));
        return () => { removeStart(); removeFinish(); };
    }, []);

    function applyFilter(key: string, value: string): void {
        router.get(wurl(workspace?.slug, '/insights'), { ...filters, [key]: value }, { preserveState: true, replace: true });
    }

    function dismiss(alertId: number): void {
        router.post(wurl(workspace?.slug, `/insights/alerts/${alertId}/dismiss`), {}, { preserveScroll: true });
    }

    function dismissAll(): void {
        router.post(wurl(workspace?.slug, '/insights/alerts/dismiss-all'), {}, { preserveScroll: true });
    }

    const severityTabs = ['all', 'info', 'warning', 'critical'] as const;
    const statusTabs   = ['all', 'unread', 'unresolved'] as const;

    return (
        <AppLayout>
            <Head title="Insights" />
            <PageHeader title="Insights" subtitle="AI summaries and system alerts" />

            {/* ── Monthly Reports ────────────────────────────────────────── */}
            <section className="mb-8">
                <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-400">
                    Monthly Reports
                </h2>
                <div className="rounded-xl border border-zinc-200 bg-white p-4">
                    <p className="mb-3 text-xs text-zinc-500">
                        Download a one-page PDF summary of revenue, ad performance, contribution margin, and top products.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {report_months.map((m) => (
                            <a
                                key={`${m.year}-${m.month}`}
                                href={wurl(workspace?.slug, `/insights/monthly-report/${m.year}/${m.month}`)}
                                className="flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 transition-colors"
                            >
                                <FileDown className="h-3.5 w-3.5" />
                                {m.label}
                            </a>
                        ))}
                    </div>
                </div>
            </section>


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
                                    <div className="flex items-center gap-1.5 section-label">
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

            {/* ── Daily Notes ──────────────────────────────────────────────── */}
            <section className="mb-8">
                <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-400">
                    Daily Notes
                </h2>

                {daily_notes.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-12 text-center">
                        <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-zinc-100">
                            <NotebookPen className="h-5 w-5 text-zinc-400" />
                        </div>
                        <p className="text-sm font-medium text-zinc-900">No notes yet</p>
                        <p className="mt-1 text-xs text-zinc-400">
                            Add daily notes from the Overview page or the Daily Breakdown table.
                        </p>
                    </div>
                ) : (
                    <div className="rounded-xl border border-zinc-200 bg-white p-4 space-y-1">
                        {daily_notes.map((n) => (
                            <InsightNoteCell key={n.id} {...n} />
                        ))}
                    </div>
                )}
            </section>

            {/* ── Alert Feed ────────────────────────────────────────────────── */}
            <section>
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-400">
                        Alert Feed
                        {alerts.total > 0 && (
                            <span className="ml-2 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-normal text-zinc-500">
                                {alerts.total}
                            </span>
                        )}
                    </h2>
                    {alerts.data.some((a) => !a.resolved_at) && (
                        <button
                            onClick={dismissAll}
                            className="flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-500 hover:bg-zinc-50 hover:text-zinc-700 transition-colors"
                        >
                            <CheckCircle className="h-3.5 w-3.5" />
                            Dismiss all
                        </button>
                    )}
                </div>

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
                                                    <span className="h-1.5 w-1.5 rounded-full bg-primary" title="Unread" />
                                                )}
                                            </div>
                                            {(alert.store_name ?? alert.ad_account_name) && (
                                                <p className="mt-0.5 text-xs text-zinc-500 font-medium">
                                                    {alert.store_name ?? alert.ad_account_name}
                                                </p>
                                            )}
                                            {ALERT_META[alert.type] && (
                                                <p className="mt-1 text-xs text-zinc-500 leading-relaxed">
                                                    {ALERT_META[alert.type].description}
                                                </p>
                                            )}
                                            {renderAlertDetail(alert) && (
                                                <p className="mt-1 rounded bg-zinc-50 px-2 py-1 text-xs font-mono text-zinc-600 border border-zinc-100">
                                                    {renderAlertDetail(alert)}
                                                </p>
                                            )}
                                            <p className="mt-1 text-xs text-zinc-400">
                                                {formatDatetime(alert.created_at)}
                                            </p>
                                        </div>

                                        {/* Actions */}
                                        <div className="flex shrink-0 flex-col items-end gap-1">
                                            {ALERT_META[alert.type]?.actionLabel && (
                                                <a
                                                    href={wurl(workspace?.slug, ALERT_META[alert.type].actionPath!)}
                                                    className="flex items-center gap-1 rounded px-2 py-1 text-xs text-primary hover:bg-primary/5 transition-colors font-medium"
                                                >
                                                    <ExternalLink className="h-3 w-3" />
                                                    {ALERT_META[alert.type].actionLabel}
                                                </a>
                                            )}
                                            {!isResolved && (
                                                <button
                                                    onClick={() => dismiss(alert.id)}
                                                    className="flex items-center gap-1 rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 transition-colors"
                                                    title="Dismiss"
                                                >
                                                    <CheckCircle className="h-3.5 w-3.5" />
                                                    Dismiss
                                                </button>
                                            )}
                                        </div>
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
                                    onClick={() => router.get(wurl(workspace?.slug, '/insights'), { ...filters, page: alerts.current_page - 1 })}
                                    className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium hover:bg-zinc-50 transition-colors"
                                >
                                    Previous
                                </button>
                            )}
                            {alerts.current_page < alerts.last_page && (
                                <button
                                    onClick={() => router.get(wurl(workspace?.slug, '/insights'), { ...filters, page: alerts.current_page + 1 })}
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
