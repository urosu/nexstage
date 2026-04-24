import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    Bot,
    CheckCircle,
    Clock,
    ExternalLink,
    FileDown,
    Info,
    Lightbulb,
    NotebookPen,
    Sparkles,
    XCircle,
} from 'lucide-react';
import { formatGscProperty } from '@/lib/gsc';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatDatetime } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────

interface RecommendationRow {
    id: number;
    type: string;
    priority: number;
    title: string;
    body: string;
    impact_estimate: number | null;
    impact_currency: string | null;
    target_url: string | null;
    data: Record<string, unknown> | null;
    created_at: string | null;
}

interface AlertRow {
    inbox_item_id: number;
    snoozed_until: string | null;
    id: number;
    type: string;
    severity: 'info' | 'warning' | 'critical';
    store_name: string | null;
    ad_account_name: string | null;
    data: Record<string, unknown> | null;
    created_at: string;
}

interface AiSummaryRow {
    inbox_item_id: number;
    snoozed_until: string | null;
    id: number;
    date: string;
    summary_text: string;
    model_used: string | null;
    generated_at: string | null;
}

interface DailyNoteRow {
    inbox_item_id: number;
    snoozed_until: string | null;
    id: number;
    date: string;
    note: string;
}

interface ReportMonthOption {
    year: number;
    month: number;
    label: string;
}

interface Props {
    narrative: string;
    todays_attention: RecommendationRow[];
    recommendations: RecommendationRow[];
    alerts: AlertRow[];
    ai_summaries: AiSummaryRow[];
    daily_notes: DailyNoteRow[];
    agent_reports: unknown[];
    report_months: ReportMonthOption[];
}

// ── Static lookup: alert metadata ─────────────────────────────────────────

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

interface AlertMeta {
    description: string;
    actionLabel?: string;
    actionPath?: string;
}

const ALERT_META: Record<string, AlertMeta> = {
    reconciliation_discrepancy: { description: 'Local order records don\'t match WooCommerce. Usually caused by missed webhooks. Go to Integrations and trigger a manual sync.', actionLabel: 'View Integrations', actionPath: '/settings/integrations' },
    webhook_stale:              { description: 'No webhook events received for over 48 hours. Orders fall back to hourly polling.', actionLabel: 'View Integrations', actionPath: '/settings/integrations' },
    sync_failure:               { description: 'Order sync failed. Check that store API credentials are valid.', actionLabel: 'View Integrations', actionPath: '/settings/integrations' },
    gsc_token_expired:          { description: 'Google Search Console access token has expired. Search traffic data won\'t update until you reconnect.', actionLabel: 'Reconnect Google', actionPath: '/settings/integrations' },
    google_token_expired:       { description: 'Google Ads access token has expired. Ad spend and performance data won\'t update until you reconnect.', actionLabel: 'Reconnect Google', actionPath: '/settings/integrations' },
    google_account_disabled:    { description: 'Google Ads account disabled/suspended. Ad sync is paused.', actionLabel: 'View Integrations', actionPath: '/settings/integrations' },
    import_failed:              { description: 'Historical order import failed. Recent orders still sync via webhooks.', actionLabel: 'View Integrations', actionPath: '/settings/integrations' },
    gsc_import_failed:          { description: 'GSC historical import failed. Organic search data may be incomplete.', actionLabel: 'View Integrations', actionPath: '/settings/integrations' },
    gsc_sync_failure:           { description: 'GSC daily sync failed. Try reconnecting your Google account.', actionLabel: 'Reconnect Google', actionPath: '/settings/integrations' },
    google_import_failed:       { description: 'Google Ads historical import failed. Ad performance history may be incomplete.', actionLabel: 'View Integrations', actionPath: '/settings/integrations' },
    facebook_import_failed:     { description: 'Facebook Ads historical import failed. Ad performance history may be incomplete.', actionLabel: 'View Integrations', actionPath: '/settings/integrations' },
    facebook_sync_failure:      { description: 'Facebook Ads sync failed. Check ad account connection.', actionLabel: 'Reconnect Facebook', actionPath: '/settings/integrations' },
    facebook_token_expired:     { description: 'Facebook Ads access token has expired. Reconnect to resume data updates.', actionLabel: 'Reconnect Facebook', actionPath: '/settings/integrations' },
    token_expiring_soon:        { description: 'An ad account access token is expiring soon. Reconnect now to avoid gaps.', actionLabel: 'View Integrations', actionPath: '/settings/integrations' },
    product_out_of_stock:       { description: 'One or more products went out of stock. Revenue from these will stop until restocked.' },
    product_back_in_stock:      { description: 'A product is back in stock and available for sale.' },
    store_sync_recovered:       { description: 'Store sync has recovered. Orders syncing normally again.' },
    revenue_spike:              { description: 'Unusual revenue spike detected. Worth a quick review.' },
};

function formatAlertType(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function renderAlertDetail(alert: AlertRow): string | null {
    const d = alert.data;
    if (!d) return null;
    switch (alert.type) {
        case 'reconciliation_discrepancy': {
            const count      = d.discrepancy_count as number;
            const total      = d.wc_count as number;
            const rate       = d.rate_pct as number;
            if (count == null || total == null) return null;
            return `${count} of ${total} orders out of sync (${(+rate).toFixed(1)}%)`;
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
            const parts = [];
            if (url)      parts.push(formatGscProperty(url));
            if (failures) parts.push(`${failures} consecutive failure${failures !== 1 ? 's' : ''}`);
            return parts.length > 0 ? parts.join(' — ') : null;
        }
        case 'import_failed':
        case 'google_import_failed':
        case 'facebook_import_failed':
            return typeof d.error === 'string' ? d.error : null;
        default:
            return null;
    }
}

// ── Snooze dropdown — shared by recommendation + item rows ────────────────

const SNOOZE_OPTIONS: Array<{ value: '1h' | '3h' | '1d' | '3d' | '1w'; label: string }> = [
    { value: '1h', label: '1 hour' },
    { value: '3h', label: '3 hours' },
    { value: '1d', label: '1 day' },
    { value: '3d', label: '3 days' },
    { value: '1w', label: '1 week' },
];

function SnoozeMenu({ onSnooze }: { onSnooze: (duration: string) => void }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const onClick = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', onClick);
        return () => document.removeEventListener('mousedown', onClick);
    }, [open]);

    return (
        <div className="relative" ref={ref}>
            <button
                onClick={() => setOpen((o) => !o)}
                className="flex items-center gap-1 rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 transition-colors"
                title="Snooze"
            >
                <Clock className="h-3.5 w-3.5" />
                Snooze
            </button>
            {open && (
                <div className="absolute right-0 top-full z-10 mt-1 w-32 rounded-lg border border-zinc-200 bg-white py-1 shadow-md">
                    {SNOOZE_OPTIONS.map((opt) => (
                        <button
                            key={opt.value}
                            onClick={() => { setOpen(false); onSnooze(opt.value); }}
                            className="block w-full px-3 py-1.5 text-left text-xs text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900"
                        >
                            {opt.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

// ── Inline daily-note editor (unchanged from Insights.tsx) ────────────────

function InboxNoteCell({ id, date, note }: DailyNoteRow) {
    const [value, setValue] = useState(note);
    const [saving, setSaving] = useState(false);
    const [savedFlash, setSavedFlash] = useState(false);
    const lastSavedRef  = useRef(note);
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
                    onBlur={(e) => save(e.currentTarget.value)}
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

// ── Recommendation card ────────────────────────────────────────────────────

function RecommendationCard({
    rec,
    workspaceSlug,
    priority,
}: {
    rec: RecommendationRow;
    workspaceSlug: string | undefined;
    priority: boolean;
}) {
    const snooze = (duration: string) =>
        router.post(wurl(workspaceSlug, `/inbox/recommendations/${rec.id}/snooze`), { duration }, { preserveScroll: true });
    const markDone = () =>
        router.post(wurl(workspaceSlug, `/inbox/recommendations/${rec.id}/done`), {}, { preserveScroll: true });
    const dismiss = () =>
        router.post(wurl(workspaceSlug, `/inbox/recommendations/${rec.id}/dismiss`), {}, { preserveScroll: true });

    return (
        <div className={cn(
            'rounded-xl border bg-white p-4',
            priority ? 'border-primary/30 ring-1 ring-primary/10' : 'border-zinc-200',
        )}>
            <div className="flex items-start gap-3">
                <div className={cn(
                    'mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full',
                    priority ? 'bg-primary/15 text-primary' : 'bg-zinc-100 text-zinc-500',
                )}>
                    <Lightbulb className="h-3.5 w-3.5" />
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-sm font-semibold text-zinc-900">{rec.title}</span>
                        {rec.impact_estimate !== null && (
                            <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-emerald-700">
                                ~{rec.impact_currency ?? '€'}{Math.round(rec.impact_estimate).toLocaleString()}/mo
                            </span>
                        )}
                    </div>
                    <p className="mt-1 text-xs leading-relaxed text-zinc-600">{rec.body}</p>
                    {rec.created_at && (
                        <p className="mt-1 text-xs text-zinc-400">{formatDatetime(rec.created_at)}</p>
                    )}
                </div>

                <div className="flex shrink-0 flex-col items-end gap-1">
                    {rec.target_url && (
                        <Link
                            href={wurl(workspaceSlug, rec.target_url)}
                            className="flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-primary hover:bg-primary/5 transition-colors"
                        >
                            <ExternalLink className="h-3 w-3" />
                            View
                        </Link>
                    )}
                    <SnoozeMenu onSnooze={snooze} />
                    <button
                        onClick={markDone}
                        className="flex items-center gap-1 rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 transition-colors"
                        title="Mark done"
                    >
                        <CheckCircle className="h-3.5 w-3.5" />
                        Done
                    </button>
                    <button
                        onClick={dismiss}
                        className="flex items-center gap-1 rounded px-2 py-1 text-xs text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 transition-colors"
                        title="Dismiss"
                    >
                        <XCircle className="h-3.5 w-3.5" />
                        Dismiss
                    </button>
                </div>
            </div>
        </div>
    );
}

// ── Alert row (reuses InboxItem id for actions) ───────────────────────────

function AlertRowCard({ alert, workspaceSlug }: { alert: AlertRow; workspaceSlug: string | undefined }) {
    const SeverityIcon = SEVERITY_ICONS[alert.severity];
    const meta = ALERT_META[alert.type];

    const snooze = (duration: string) =>
        router.post(wurl(workspaceSlug, `/inbox/items/${alert.inbox_item_id}/snooze`), { duration }, { preserveScroll: true });
    const markDone = () =>
        router.post(wurl(workspaceSlug, `/inbox/items/${alert.inbox_item_id}/done`), {}, { preserveScroll: true });

    return (
        <li className="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-zinc-50">
            <div className={cn('mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full', SEVERITY_COLORS[alert.severity])}>
                <SeverityIcon className="h-3.5 w-3.5" />
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-sm font-medium text-zinc-900">{formatAlertType(alert.type)}</span>
                    <span className={cn('rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase', SEVERITY_COLORS[alert.severity])}>
                        {alert.severity}
                    </span>
                </div>
                {(alert.store_name ?? alert.ad_account_name) && (
                    <p className="mt-0.5 text-xs font-medium text-zinc-500">{alert.store_name ?? alert.ad_account_name}</p>
                )}
                {meta && <p className="mt-1 text-xs leading-relaxed text-zinc-500">{meta.description}</p>}
                {renderAlertDetail(alert) && (
                    <p className="mt-1 rounded border border-zinc-100 bg-zinc-50 px-2 py-1 font-mono text-xs text-zinc-600">
                        {renderAlertDetail(alert)}
                    </p>
                )}
                <p className="mt-1 text-xs text-zinc-400">{formatDatetime(alert.created_at)}</p>
            </div>

            <div className="flex shrink-0 flex-col items-end gap-1">
                {meta?.actionLabel && (
                    <a
                        href={wurl(workspaceSlug, meta.actionPath!)}
                        className="flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-primary hover:bg-primary/5 transition-colors"
                    >
                        <ExternalLink className="h-3 w-3" />
                        {meta.actionLabel}
                    </a>
                )}
                <SnoozeMenu onSnooze={snooze} />
                <button
                    onClick={markDone}
                    className="flex items-center gap-1 rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 transition-colors"
                    title="Mark done"
                >
                    <CheckCircle className="h-3.5 w-3.5" />
                    Dismiss
                </button>
            </div>
        </li>
    );
}

// ── Page ─────────────────────────────────────────────────────────────────

export default function Inbox({
    narrative,
    todays_attention,
    recommendations,
    alerts,
    ai_summaries,
    daily_notes,
    report_months,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const slug = workspace?.slug;
    const timezone = workspace?.reporting_timezone;

    return (
        <AppLayout>
            <Head title="Inbox" />
            <PageHeader title="Inbox" subtitle="Priorities, alerts, and reports" narrative={narrative} />

            {/* ── Today's Attention ─────────────────────────────────────── */}
            <section className="mb-8">
                <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold uppercase tracking-wide text-zinc-400">
                    <Sparkles className="h-3.5 w-3.5" />
                    Today's Attention
                </h2>
                {todays_attention.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-10 text-center">
                        <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-zinc-100">
                            <Sparkles className="h-5 w-5 text-zinc-400" />
                        </div>
                        <p className="text-sm font-medium text-zinc-900">Nothing urgent right now</p>
                        <p className="mt-1 text-xs text-zinc-400">Priority items from the last 24h will appear here.</p>
                    </div>
                ) : (
                    <div className="space-y-2">
                        {todays_attention.map((rec) => (
                            <RecommendationCard key={rec.id} rec={rec} workspaceSlug={slug} priority />
                        ))}
                    </div>
                )}
            </section>

            {/* ── Recommendations ──────────────────────────────────────── */}
            {recommendations.length > todays_attention.length && (
                <section className="mb-8">
                    <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-400">
                        Recommendations
                    </h2>
                    <div className="space-y-2">
                        {recommendations.slice(todays_attention.length).map((rec) => (
                            <RecommendationCard key={rec.id} rec={rec} workspaceSlug={slug} priority={false} />
                        ))}
                    </div>
                </section>
            )}

            {/* ── Alert Feed ───────────────────────────────────────────── */}
            <section className="mb-8">
                <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold uppercase tracking-wide text-zinc-400">
                    <Bell className="h-3.5 w-3.5" />
                    Alerts
                    {alerts.length > 0 && (
                        <span className="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-normal text-zinc-500">
                            {alerts.length}
                        </span>
                    )}
                </h2>
                {alerts.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-10 text-center">
                        <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-zinc-100">
                            <Bell className="h-5 w-5 text-zinc-400" />
                        </div>
                        <p className="text-sm font-medium text-zinc-900">No alerts</p>
                        <p className="mt-1 text-xs text-zinc-400">Alerts appear when sync failures or token issues are detected.</p>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white">
                        <ul className="divide-y divide-zinc-100">
                            {alerts.map((alert) => (
                                <AlertRowCard key={alert.inbox_item_id} alert={alert} workspaceSlug={slug} />
                            ))}
                        </ul>
                    </div>
                )}
            </section>

            {/* ── Agent Reports (placeholder) ─────────────────────────── */}
            <section className="mb-8">
                <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold uppercase tracking-wide text-zinc-400">
                    <Bot className="h-3.5 w-3.5" />
                    Agent Reports
                </h2>
                <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-200 bg-white px-6 py-8 text-center">
                    <p className="text-sm font-medium text-zinc-900">Coming soon</p>
                    <p className="mt-1 text-xs text-zinc-400">Scheduled agents will post fatigue, pacing, and anomaly reports here.</p>
                </div>
            </section>

            {/* ── AI Summaries ─────────────────────────────────────────── */}
            <section className="mb-8">
                <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-400">
                    AI Daily Summaries
                </h2>
                {ai_summaries.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-10 text-center">
                        <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-zinc-100">
                            <Bot className="h-5 w-5 text-zinc-400" />
                        </div>
                        <p className="text-sm font-medium text-zinc-900">No summaries yet</p>
                        <p className="mt-1 text-xs text-zinc-400">AI summaries are generated nightly once store data is available.</p>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {ai_summaries.map((s) => (
                            <div key={s.inbox_item_id} className="rounded-xl border border-zinc-200 bg-white p-5">
                                <div className="mb-2 flex items-center justify-between gap-3">
                                    <div className="section-label flex items-center gap-1.5">
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

            {/* ── Daily Notes ──────────────────────────────────────────── */}
            <section className="mb-8">
                <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-400">
                    Daily Notes
                </h2>
                {daily_notes.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-10 text-center">
                        <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-zinc-100">
                            <NotebookPen className="h-5 w-5 text-zinc-400" />
                        </div>
                        <p className="text-sm font-medium text-zinc-900">No notes yet</p>
                        <p className="mt-1 text-xs text-zinc-400">Add daily notes from the Overview page or the Daily Breakdown table.</p>
                    </div>
                ) : (
                    <div className="space-y-1 rounded-xl border border-zinc-200 bg-white p-4">
                        {daily_notes.map((n) => (
                            <InboxNoteCell key={n.inbox_item_id} {...n} />
                        ))}
                    </div>
                )}
            </section>

            {/* ── Monthly Reports ─────────────────────────────────────── */}
            <section>
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
                                href={wurl(slug, `/inbox/monthly-report/${m.year}/${m.month}`)}
                                className="flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50 hover:text-zinc-900"
                            >
                                <FileDown className="h-3.5 w-3.5" />
                                {m.label}
                            </a>
                        ))}
                    </div>
                </div>
            </section>
        </AppLayout>
    );
}
