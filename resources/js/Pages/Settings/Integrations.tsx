import { useState, useEffect } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Database, Loader2, PlugZap, RefreshCw, RotateCcw, Trash2 } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusBadge } from '@/Components/shared/StatusBadge';
import { IntegrationActionsMenu, ReimportDialog } from '@/Components/shared';
import type { ActionItem } from '@/Components/shared';
import { formatGscProperty, getGscPropertyType } from '@/lib/gsc';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface StoreItem {
    id: number;
    slug: string;
    name: string;
    domain: string;
    type: string;
    status: string;
    currency: string;
    last_synced_at: string | null;
    last_webhook_at: string | null;
    historical_import_status: string | null;
    historical_import_progress: number | null;
    historical_import_from: string | null;
    consecutive_sync_failures: number;
    sync_running: boolean;
    sync_method: 'real_time' | 'polling';
    freshness: 'green' | 'amber' | 'red';
}

interface AdAccountItem {
    id: number;
    platform: string;
    name: string;
    external_id: string;
    currency: string;
    status: string;
    last_synced_at: string | null;
    consecutive_sync_failures: number;
    historical_import_status: string | null;
    historical_import_progress: number | null;
    historical_import_from: string | null;
    sync_running: boolean;
}

interface GscPropertyItem {
    id: number;
    property_url: string;
    status: string;
    last_synced_at: string | null;
    consecutive_sync_failures: number;
    historical_import_status: string | null;
    historical_import_progress: number | null;
    historical_import_from: string | null;
    sync_running: boolean;
}

interface GscPending {
    key: string;
    items: string[];
}

interface AdAccountOption {
    id: string;
    name: string;
    currency: string;
}

interface AdAccountPending {
    key: string;
    items: AdAccountOption[];
}

interface Props {
    stores: StoreItem[];
    ad_accounts: AdAccountItem[];
    gsc_properties: GscPropertyItem[];
    user_role: string;
    gsc_pending: GscPending | null;
    fb_pending: AdAccountPending | null;
    gads_pending: AdAccountPending | null;
    oauth_error: string | null;
    oauth_platform: string | null;
}

interface ReimportTarget {
    type: 'store' | 'ad' | 'gsc';
    id: string | number;
    name: string;
    defaultDate: string;
    notice?: string;
}

// Informational notices shown in "All available data" mode for platforms with a
// hard API retention limit. Store and Google Ads have no meaningful limit.
const PLATFORM_NOTICES: Partial<Record<'store' | 'facebook' | 'google' | 'gsc', string>> = {
    facebook: 'Facebook Ads exposes up to 37 months of data. Requesting dates before that will return no results.',
    gsc:      'Google Search Console retains 16 months of data. Google permanently deletes anything older.',
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatRelativeTime(iso: string | null): string {
    if (!iso) return 'Never';
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60_000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
}

function storeSyncLabel(store: StoreItem, syncing: boolean): string {
    if (syncing) return 'Syncing…';
    const imp = store.historical_import_status;
    if (imp === 'running' || imp === 'pending') return 'Historical import in progress';
    if (imp === 'failed') return store.last_synced_at
        ? `Last synced ${formatRelativeTime(store.last_synced_at)} · Import failed`
        : 'Import failed';
    if (store.last_synced_at) return `Synced ${formatRelativeTime(store.last_synced_at)}`;
    if (store.last_webhook_at) return `Webhook ${formatRelativeTime(store.last_webhook_at)}`;
    return 'Never synced';
}

function adAccountSyncLabel(account: AdAccountItem, syncing: boolean): string {
    if (syncing) return 'Syncing…';
    const imp = account.historical_import_status;
    if (imp === 'running' || imp === 'pending') return 'Historical import in progress';
    if (imp === 'failed') return account.last_synced_at
        ? `Last synced ${formatRelativeTime(account.last_synced_at)} · Import failed`
        : 'Import failed';
    if (account.last_synced_at) return `Synced ${formatRelativeTime(account.last_synced_at)}`;
    return 'Never synced';
}

function gscSyncLabel(prop: GscPropertyItem, syncing: boolean): string {
    if (syncing) return 'Syncing…';
    const imp = prop.historical_import_status;
    if (imp === 'running' || imp === 'pending') return 'Historical import in progress';
    if (imp === 'failed') return prop.last_synced_at
        ? `Last synced ${formatRelativeTime(prop.last_synced_at)} · Import failed`
        : 'Import failed';
    if (prop.last_synced_at) return `Synced ${formatRelativeTime(prop.last_synced_at)}`;
    return 'Never synced';
}

function ImportBadge({ status, progress }: { status: string | null; progress?: number | null }) {
    if (!status || status === 'completed') return null;

    if (status === 'failed') {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                Import failed
            </span>
        );
    }

    if (status === 'pending' || progress == null) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
                <Loader2 className="h-3 w-3 animate-spin" />
                {status === 'pending' ? 'Import queued' : 'Importing…'}
            </span>
        );
    }

    // Running with known progress — show inline progress bar
    return (
        <span className="inline-flex items-center gap-2">
            <span className="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
                <Loader2 className="h-3 w-3 animate-spin" />
                Importing history
            </span>
            <span className="inline-flex items-center gap-1.5">
                <span className="h-1.5 w-24 overflow-hidden rounded-full bg-zinc-100">
                    <span
                        className="block h-full rounded-full bg-blue-500 transition-all duration-700"
                        style={{ width: `${progress}%` }}
                    />
                </span>
                <span className="text-xs font-medium tabular-nums text-blue-600">{progress}%</span>
            </span>
        </span>
    );
}

function GscTypeBadge({ propertyUrl }: { propertyUrl: string }) {
    const isDomain = getGscPropertyType(propertyUrl) === 'domain';
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${isDomain ? 'bg-violet-100 text-violet-700' : 'bg-sky-100 text-sky-700'}`}>
            {isDomain ? 'Domain' : 'URL prefix'}
        </span>
    );
}

const FRESHNESS_DOT: Record<'green' | 'amber' | 'red', string> = {
    green: 'bg-green-500',
    amber: 'bg-amber-400',
    red:   'bg-red-500',
};

const FRESHNESS_LABEL: Record<'green' | 'amber' | 'red', string> = {
    green: 'Fresh',
    amber: 'Delayed',
    red:   'Stale',
};

function WebhookHealthBadge({ syncMethod, freshness }: {
    syncMethod: 'real_time' | 'polling';
    freshness: 'green' | 'amber' | 'red';
}) {
    const methodLabel = syncMethod === 'real_time' ? 'Real-time' : 'Polling (90 min)';
    return (
        <span className="inline-flex items-center gap-1.5" title={`${methodLabel} · Data is ${FRESHNESS_LABEL[freshness].toLowerCase()}`}>
            <span className={`inline-block h-2 w-2 shrink-0 rounded-full ${FRESHNESS_DOT[freshness]}`} />
            <span className="text-xs text-zinc-400">{methodLabel}</span>
        </span>
    );
}

const PLATFORM_LABELS: Record<string, string> = {
    woocommerce: 'WooCommerce',
    shopify:     'Shopify',
    bigcommerce: 'BigCommerce',
    magento:     'Magento',
    prestashop:  'PrestaShop',
    opencart:    'OpenCart',
    facebook:    'Facebook Ads',
    google:      'Google Ads',
};

// ─── Section ──────────────────────────────────────────────────────────────────

function SectionCard({ title, children, action }: {
    title: string;
    children: React.ReactNode;
    action?: React.ReactNode;
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
            <div className="flex items-center justify-between border-b border-zinc-200 px-6 py-4">
                <h3 className="text-base font-semibold text-zinc-900">{title}</h3>
                {action}
            </div>
            {children}
        </div>
    );
}

function EmptyRow({ message }: { message: string }) {
    return (
        <div className="px-6 py-8 text-center text-sm text-zinc-400">{message}</div>
    );
}

function OAuthErrorRow({ message }: { message: string }) {
    return (
        <div className="flex items-start gap-3 border-t border-amber-200 bg-amber-50 px-6 py-4">
            <svg className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" viewBox="0 0 20 20" fill="currentColor">
                <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
            </svg>
            <p className="text-sm text-amber-800">{message}</p>
        </div>
    );
}

// ─── GSC Property Picker ──────────────────────────────────────────────────────

function GscPropertyPicker({ pending }: { pending: GscPending }) {
    const { data, setData, post, processing, errors } = useForm({
        property_url:    '',
        gsc_pending_key: pending.key,
    });
    const { workspace: ws } = usePage<PageProps>().props;
    const w = (path: string) => wurl(ws?.slug, path);

    const submit = (e: React.SyntheticEvent) => {
        e.preventDefault();
        post('/oauth/gsc/connect');
    };

    return (
        <div className="bg-primary/10 px-6 py-5">
            <p className="text-sm font-semibold text-zinc-900">Select a Search Console property to connect</p>
            <p className="mt-1 text-xs text-primary">Choose the property you want to link to this workspace.</p>
            <form onSubmit={submit} className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div className="flex-1">
                    <label htmlFor="gsc-property" className="block text-xs font-medium text-zinc-700 mb-1">
                        Property
                    </label>
                    <select
                        id="gsc-property"
                        value={data.property_url}
                        onChange={(e) => setData('property_url', e.target.value)}
                        className="w-full rounded-md border border-primary/30 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        required
                    >
                        <option value="">— select a property —</option>
                        {pending.items.map((url: string) => (
                            <option key={url} value={url}>{url}</option>
                        ))}
                    </select>
                    {errors.property_url && (
                        <p className="mt-1 text-xs text-red-600">{errors.property_url}</p>
                    )}
                </div>
                <div className="flex shrink-0 gap-2">
                    <a
                        href={w('/settings/integrations')}
                        className="rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 transition-colors"
                    >
                        Cancel
                    </a>
                    <button
                        type="submit"
                        disabled={processing || data.property_url === ''}
                        className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors"
                    >
                        {processing ? 'Connecting…' : 'Connect property'}
                    </button>
                </div>
            </form>
        </div>
    );
}

// ─── Ad Account Picker (shared by Facebook and Google Ads) ───────────────────

function AdAccountPicker({ pending, connectRoute, pendingKeyField, label, connectedExternalIds }: {
    pending: AdAccountPending;
    connectRoute: string;
    pendingKeyField: string;
    label: string;
    connectedExternalIds: string[];
}) {
    const { workspace: ws } = usePage<PageProps>().props;
    const w = (path: string) => wurl(ws?.slug, path);
    const alreadyConnected = (id: string) => connectedExternalIds.includes(id);

    const [selected, setSelected] = useState<string[]>(
        () => pending.items.filter((a) => alreadyConnected(a.id)).map((a) => a.id)
    );
    const { post, processing, setData } = useForm<{
        [key: string]: string | string[];
        account_ids: string[];
    }>({
        [pendingKeyField]: pending.key,
        account_ids: pending.items.filter((a) => alreadyConnected(a.id)).map((a) => a.id),
    });

    const toggle = (id: string) => {
        const next = selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id];
        setSelected(next);
        setData('account_ids', next);
    };

    const toggleAll = () => {
        if (allSelected) {
            setSelected([]);
            setData('account_ids', []);
        } else {
            const all = pending.items.map((a) => a.id);
            setSelected(all);
            setData('account_ids', all);
        }
    };

    const allSelected = selected.length === pending.items.length && pending.items.length > 0;

    const submit = (e: React.SyntheticEvent) => {
        e.preventDefault();
        if (selected.length === 0) return;
        post(connectRoute);
    };

    const newCount = selected.filter((id) => !alreadyConnected(id)).length;
    const reconnectCount = selected.filter((id) => alreadyConnected(id)).length;

    if (pending.items.length === 0) {
        return (
            <div className="bg-primary/10 px-6 py-5">
                <p className="text-sm font-semibold text-zinc-900">No connectable accounts found</p>
                <p className="mt-1 text-xs text-zinc-500">
                    No eligible {label} accounts were found for this Google user. Manager accounts (MCCs) and disabled accounts are not supported.
                </p>
                <div className="mt-3 flex justify-end">
                    <a
                        href={w('/settings/integrations')}
                        className="rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 transition-colors"
                    >
                        Dismiss
                    </a>
                </div>
            </div>
        );
    }

    return (
        <div className="bg-primary/10 px-6 py-5">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-sm font-semibold text-zinc-900">Select {label} accounts to connect</p>
                    <p className="mt-0.5 text-xs text-primary">
                        Already-connected accounts are pre-selected — reconnecting refreshes their token.
                    </p>
                </div>
                <button type="button" onClick={toggleAll} className="text-xs text-primary hover:text-primary/70">
                    {allSelected ? 'Deselect all' : 'Select all'}
                </button>
            </div>

            <form onSubmit={submit} className="mt-3">
                <ul className="divide-y divide-primary/10 rounded-md border border-primary/20 bg-white overflow-hidden">
                    {pending.items.map((account) => {
                        const isConnected = alreadyConnected(account.id);
                        return (
                            <li key={account.id}>
                                <label className="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-primary/10 transition-colors">
                                    <input
                                        type="checkbox"
                                        checked={selected.includes(account.id)}
                                        onChange={() => toggle(account.id)}
                                        className="h-4 w-4 rounded border-zinc-300 text-primary focus:ring-primary"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <p className="text-sm font-medium text-zinc-900">{account.name}</p>
                                            {isConnected && (
                                                <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">
                                                    Connected
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-xs text-zinc-400">ID {account.id} · {account.currency}</p>
                                    </div>
                                </label>
                            </li>
                        );
                    })}
                </ul>

                <div className="mt-3 flex justify-end gap-2">
                    <a
                        href={w('/settings/integrations')}
                        className="rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 transition-colors"
                    >
                        Cancel
                    </a>
                    <button
                        type="submit"
                        disabled={processing || selected.length === 0}
                        className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors"
                    >
                        {processing ? 'Connecting…' : (
                            newCount > 0 && reconnectCount > 0
                                ? `Connect ${newCount} new · Refresh ${reconnectCount}`
                                : newCount > 0
                                    ? `Connect ${newCount} account${newCount === 1 ? '' : 's'}`
                                    : `Refresh ${reconnectCount} account${reconnectCount === 1 ? '' : 's'}`
                        )}
                    </button>
                </div>
            </form>
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function Integrations({
    stores,
    ad_accounts,
    gsc_properties,
    user_role,
    gsc_pending,
    fb_pending,
    gads_pending,
    oauth_error,
    oauth_platform,
}: Props) {
    const canManage = user_role === 'owner' || user_role === 'admin';
    const { workspace: ws } = usePage<PageProps>().props;
    const w = (path: string) => wurl(ws?.slug, path);

    // Auto-refresh every 15 s while any import is running/pending or any sync is in progress.
    const anyImportActive =
        ad_accounts.some((a) => a.historical_import_status === 'running' || a.historical_import_status === 'pending') ||
        stores.some((s) => s.historical_import_status === 'running' || s.historical_import_status === 'pending') ||
        gsc_properties.some((p) => p.historical_import_status === 'running' || p.historical_import_status === 'pending');

    const anySyncRunning =
        ad_accounts.some((a) => a.sync_running) ||
        stores.some((s) => s.sync_running) ||
        gsc_properties.some((p) => p.sync_running);

    useEffect(() => {
        if (!anyImportActive && !anySyncRunning) return;
        const timer = setInterval(() => {
            router.reload({ only: ['ad_accounts', 'stores', 'gsc_properties'] });
        }, 15_000);
        return () => clearInterval(timer);
    }, [anyImportActive, anySyncRunning]);

    const [connectingTo, setConnectingTo] = useState<string | null>(null);

    // Single processing key tracks which row is waiting on a server response.
    // Format: "{action}-{type}-{id}" e.g. "sync-store-my-shop", "retry-ad-42"
    const [processingAction, setProcessingAction] = useState<string | null>(null);

    // Track slugs/ids where sync was just queued so we can show "Syncing…" until
    // the next page update (sync_running won't be true until the job actually starts).
    const [queuedSlugs, setQueuedSlugs] = useState<Set<string>>(new Set());
    const [queuedAdIds, setQueuedAdIds] = useState<Set<number>>(new Set());
    const [queuedGscIds, setQueuedGscIds] = useState<Set<number>>(new Set());

    // Controls the re-import date dialog.
    const [reimportTarget, setReimportTarget] = useState<ReimportTarget | null>(null);

    // ── Actions ────────────────────────────────────────────────────────────────

    const removeStore = (slug: string, name: string) => {
        if (!confirm(`Remove "${name}"?\n\nThis will permanently delete all data for this store — orders, snapshots, and products. This cannot be undone.`)) return;
        const key = `remove-store-${slug}`;
        setProcessingAction(key);
        router.delete(w(`/settings/integrations/stores/${slug}`), {
            onFinish: () => setProcessingAction((prev) => prev === key ? null : prev),
        });
    };

    const removeAdAccount = (id: number, name: string) => {
        if (!confirm(`Remove "${name}"?\n\nThis will permanently delete all ad data — campaigns, insights, and spend history. This cannot be undone.`)) return;
        const key = `remove-ad-${id}`;
        setProcessingAction(key);
        router.delete(w(`/settings/integrations/ad-accounts/${id}`), {
            onFinish: () => setProcessingAction((prev) => prev === key ? null : prev),
        });
    };

    const removeGsc = (id: number, url: string) => {
        if (!confirm(`Remove "${url}"?\n\nThis will permanently delete all Search Console data — clicks, impressions, queries, and pages. This cannot be undone.`)) return;
        const key = `remove-gsc-${id}`;
        setProcessingAction(key);
        router.delete(w(`/settings/integrations/gsc/${id}`), {
            onFinish: () => setProcessingAction((prev) => prev === key ? null : prev),
        });
    };

    const syncStore = (slug: string) => {
        const key = `sync-store-${slug}`;
        setProcessingAction(key);
        router.post(w(`/settings/integrations/stores/${slug}/sync`), {}, {
            onSuccess: () => setQueuedSlugs((prev) => new Set(prev).add(slug)),
            onFinish:  () => setProcessingAction((prev) => prev === key ? null : prev),
        });
    };

    const syncAdAccount = (id: number) => {
        const key = `sync-ad-${id}`;
        setProcessingAction(key);
        router.post(w(`/settings/integrations/ad-accounts/${id}/sync`), {}, {
            onSuccess: () => setQueuedAdIds((prev) => new Set(prev).add(id)),
            onFinish:  () => setProcessingAction((prev) => prev === key ? null : prev),
        });
    };

    const syncGsc = (id: number) => {
        const key = `sync-gsc-${id}`;
        setProcessingAction(key);
        router.post(w(`/settings/integrations/gsc/${id}/sync`), {}, {
            onSuccess: () => setQueuedGscIds((prev) => new Set(prev).add(id)),
            onFinish:  () => setProcessingAction((prev) => prev === key ? null : prev),
        });
    };

    const retryImportStore = (slug: string) => {
        const key = `retry-store-${slug}`;
        setProcessingAction(key);
        router.post(w(`/settings/integrations/stores/${slug}/retry-import`), {}, {
            onFinish: () => setProcessingAction((prev) => prev === key ? null : prev),
        });
    };

    const retryImportAdAccount = (id: number) => {
        const key = `retry-ad-${id}`;
        setProcessingAction(key);
        router.post(w(`/settings/integrations/ad-accounts/${id}/retry-import`), {}, {
            onFinish: () => setProcessingAction((prev) => prev === key ? null : prev),
        });
    };

    const retryImportGsc = (id: number) => {
        const key = `retry-gsc-${id}`;
        setProcessingAction(key);
        router.post(w(`/settings/integrations/gsc/${id}/retry-import`), {}, {
            onFinish: () => setProcessingAction((prev) => prev === key ? null : prev),
        });
    };

    const confirmReimport = (fromDate: string | null) => {
        if (!reimportTarget) return;
        const { type, id } = reimportTarget;
        const key = `reimport-${type}-${id}`;
        setProcessingAction(key);

        const url = type === 'store'
            ? w(`/settings/integrations/stores/${id as string}/reimport`)
            : type === 'ad'
                ? w(`/settings/integrations/ad-accounts/${id as number}/reimport`)
                : w(`/settings/integrations/gsc/${id as number}/reimport`);

        // fromDate is null when "All available data" mode is selected.
        // The backend treats a missing from_date as "fetch from the beginning".
        router.post(url, fromDate ? { from_date: fromDate } : {}, {
            onSuccess: () => setReimportTarget(null),
            onFinish:  () => setProcessingAction((prev) => prev === key ? null : prev),
        });
    };

    // ── Derived ────────────────────────────────────────────────────────────────

    const facebookAccounts = ad_accounts.filter((a) => a.platform === 'facebook');
    const googleAccounts   = ad_accounts.filter((a) => a.platform === 'google');

    const isProcessing = (key: string) => processingAction === key;

    return (
        <AppLayout>
            <Head title="Integrations" />

            <PageHeader
                title="Integrations"
                subtitle="Connected stores, ad platforms, and Search Console properties"
            />

            <div className="mt-6 max-w-3xl space-y-6">

                {oauth_error && !oauth_platform && (
                    <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-6 py-4">
                        <svg className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" viewBox="0 0 20 20" fill="currentColor">
                            <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                        </svg>
                        <p className="text-sm text-amber-800">{oauth_error}</p>
                    </div>
                )}

                {/* ── Stores ── */}
                <SectionCard
                    title={`Stores (${stores.length})`}
                    action={
                        canManage ? (
                            <a
                                href={wurl(ws?.slug, '/stores/connect')}
                                onClick={() => setConnectingTo('store')}
                                className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
                            >
                                {connectingTo === 'store' && <Loader2 className="h-3 w-3 animate-spin" />}
                                Connect
                            </a>
                        ) : undefined
                    }
                >
                    {stores.length === 0 ? (
                        <EmptyRow message="No stores connected yet. Connect a store to start tracking." />
                    ) : (
                        <ul className="divide-y divide-zinc-100">
                            {stores.map((store) => {
                                const syncing = store.sync_running || queuedSlugs.has(store.slug);
                                const importActive = store.historical_import_status === 'running' || store.historical_import_status === 'pending';
                                const canSync = ['active', 'error'].includes(store.status) && !importActive && !syncing;
                                const importFailed = store.historical_import_status === 'failed';

                                const menuItems: ActionItem[] = [
                                    ...(canSync ? [{
                                        label: 'Sync now',
                                        icon: <RefreshCw className="h-3.5 w-3.5" />,
                                        onClick: () => syncStore(store.slug),
                                        disabled: isProcessing(`sync-store-${store.slug}`),
                                    }] : []),
                                    {
                                        label: 'Re-import data…',
                                        icon: <Database className="h-3.5 w-3.5" />,
                                        onClick: () => setReimportTarget({
                                            type: 'store',
                                            id: store.slug,
                                            name: store.name,
                                            defaultDate: store.historical_import_from ?? new Date(Date.now() - 90 * 86400_000).toISOString().split('T')[0],
                                        }),
                                    },
                                    {
                                        label: 'Remove',
                                        icon: <Trash2 className="h-3.5 w-3.5" />,
                                        onClick: () => removeStore(store.slug, store.name),
                                        variant: 'destructive',
                                        disabled: isProcessing(`remove-store-${store.slug}`),
                                        separator: true,
                                    },
                                ];

                                return (
                                    <li key={store.id} className="flex items-center justify-between px-6 py-4">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="text-sm font-medium text-zinc-900">{store.name}</p>
                                                <StatusBadge status={store.status} />
                                                <ImportBadge status={store.historical_import_status} progress={store.historical_import_progress} />
                                                {store.consecutive_sync_failures >= 3 && (
                                                    <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                                        {store.consecutive_sync_failures} failures
                                                    </span>
                                                )}
                                            </div>
                                            <div className="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                                                <span className="text-xs text-zinc-400">
                                                    {PLATFORM_LABELS[store.type] ?? store.type}
                                                    {' · '}
                                                    {store.domain}
                                                    {' · '}
                                                    {store.currency}
                                                    {' · '}
                                                    {storeSyncLabel(store, syncing)}
                                                </span>
                                                <span className="text-xs text-zinc-300">·</span>
                                                <WebhookHealthBadge syncMethod={store.sync_method} freshness={store.freshness} />
                                            </div>
                                        </div>
                                        {canManage && (
                                            <div className="ml-4 flex shrink-0 items-center gap-2">
                                                {importFailed && (
                                                    <button
                                                        onClick={() => retryImportStore(store.slug)}
                                                        disabled={isProcessing(`retry-store-${store.slug}`)}
                                                        className="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 transition-colors disabled:opacity-50"
                                                    >
                                                        <RotateCcw className="h-3 w-3" />
                                                        Resume import
                                                    </button>
                                                )}
                                                <IntegrationActionsMenu items={menuItems} />
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </SectionCard>

                {/* ── Facebook Ads ── */}
                <SectionCard
                    title={`Facebook Ads (${facebookAccounts.length})`}
                    action={
                        canManage && !fb_pending ? (
                            <a
                                href="/oauth/facebook"
                                onClick={() => setConnectingTo('facebook')}
                                className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
                            >
                                {connectingTo === 'facebook' && <Loader2 className="h-3 w-3 animate-spin" />}
                                Connect
                            </a>
                        ) : undefined
                    }
                >
                    {fb_pending && (
                        <AdAccountPicker
                            pending={fb_pending}
                            connectRoute="/oauth/facebook/connect"
                            pendingKeyField="fb_pending_key"
                            label="Facebook Ads"
                            connectedExternalIds={facebookAccounts.map((a) => a.external_id)}
                        />
                    )}
                    {oauth_error && oauth_platform === 'facebook' && (
                        <OAuthErrorRow message={oauth_error} />
                    )}
                    {facebookAccounts.length === 0 && !fb_pending && !(oauth_error && oauth_platform === 'facebook') && (
                        <EmptyRow message="No Facebook Ads accounts connected." />
                    )}
                    {facebookAccounts.length > 0 && (
                        <ul className="divide-y divide-zinc-100">
                            {facebookAccounts.map((account) => {
                                const syncing = account.sync_running || queuedAdIds.has(account.id);
                                const importActive = account.historical_import_status === 'running' || account.historical_import_status === 'pending';
                                const canSync = ['active', 'error'].includes(account.status) && !importActive && !syncing;
                                const tokenExpired = account.status === 'token_expired';
                                const importFailed = account.historical_import_status === 'failed' && !tokenExpired;

                                const menuItems: ActionItem[] = [
                                    ...(canSync ? [{
                                        label: 'Sync now',
                                        icon: <RefreshCw className="h-3.5 w-3.5" />,
                                        onClick: () => syncAdAccount(account.id),
                                        disabled: isProcessing(`sync-ad-${account.id}`),
                                    }] : []),
                                    {
                                        label: 'Re-import data…',
                                        icon: <Database className="h-3.5 w-3.5" />,
                                        onClick: () => setReimportTarget({
                                            type: 'ad',
                                            id: account.id,
                                            name: account.name,
                                            defaultDate: account.historical_import_from ?? new Date(Date.now() - 90 * 86400_000).toISOString().split('T')[0],
                                            notice: PLATFORM_NOTICES.facebook,
                                        }),
                                    },
                                    {
                                        label: 'Remove',
                                        icon: <Trash2 className="h-3.5 w-3.5" />,
                                        onClick: () => removeAdAccount(account.id, account.name),
                                        variant: 'destructive',
                                        disabled: isProcessing(`remove-ad-${account.id}`),
                                        separator: true,
                                    },
                                ];

                                return (
                                    <li key={account.id} className="flex items-center justify-between px-6 py-4">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="text-sm font-medium text-zinc-900">{account.name}</p>
                                                <StatusBadge status={account.status} />
                                                <ImportBadge status={account.historical_import_status} progress={account.historical_import_progress} />
                                            </div>
                                            <p className="mt-0.5 text-xs text-zinc-400">
                                                Account {account.external_id}
                                                {' · '}
                                                {account.currency}
                                                {' · '}
                                                {adAccountSyncLabel(account, syncing)}
                                            </p>
                                        </div>
                                        {canManage && (
                                            <div className="ml-4 flex shrink-0 items-center gap-2">
                                                {tokenExpired && (
                                                    <a
                                                        href={`/oauth/facebook?reconnect_id=${account.id}`}
                                                        className="inline-flex items-center gap-1.5 rounded-md bg-amber-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-600 transition-colors"
                                                    >
                                                        <PlugZap className="h-3 w-3" />
                                                        Reconnect
                                                    </a>
                                                )}
                                                {importFailed && (
                                                    <button
                                                        onClick={() => retryImportAdAccount(account.id)}
                                                        disabled={isProcessing(`retry-ad-${account.id}`)}
                                                        className="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 transition-colors disabled:opacity-50"
                                                    >
                                                        <RotateCcw className="h-3 w-3" />
                                                        Resume import
                                                    </button>
                                                )}
                                                <IntegrationActionsMenu items={menuItems} />
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </SectionCard>

                {/* ── Google Ads ── */}
                <SectionCard
                    title={`Google Ads (${googleAccounts.length})`}
                    action={
                        canManage && !gads_pending ? (
                            <a
                                href="/oauth/google/ads"
                                onClick={() => setConnectingTo('google_ads')}
                                className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
                            >
                                {connectingTo === 'google_ads' && <Loader2 className="h-3 w-3 animate-spin" />}
                                Connect
                            </a>
                        ) : undefined
                    }
                >
                    {gads_pending && (
                        <AdAccountPicker
                            pending={gads_pending}
                            connectRoute="/oauth/google/ads/connect"
                            pendingKeyField="gads_pending_key"
                            label="Google Ads"
                            connectedExternalIds={googleAccounts.map((a) => a.external_id)}
                        />
                    )}
                    {oauth_error && oauth_platform === 'google_ads' && (
                        <OAuthErrorRow message={oauth_error} />
                    )}
                    {googleAccounts.length === 0 && !gads_pending && !(oauth_error && oauth_platform === 'google_ads') && (
                        <EmptyRow message="No Google Ads accounts connected." />
                    )}
                    {googleAccounts.length > 0 && (
                        <ul className="divide-y divide-zinc-100">
                            {googleAccounts.map((account) => {
                                const syncing = account.sync_running || queuedAdIds.has(account.id);
                                const importActive = account.historical_import_status === 'running' || account.historical_import_status === 'pending';
                                const canSync = ['active', 'error'].includes(account.status) && !importActive && !syncing;
                                const tokenExpired = account.status === 'token_expired';
                                const importFailed = account.historical_import_status === 'failed' && !tokenExpired;

                                const menuItems: ActionItem[] = [
                                    ...(canSync ? [{
                                        label: 'Sync now',
                                        icon: <RefreshCw className="h-3.5 w-3.5" />,
                                        onClick: () => syncAdAccount(account.id),
                                        disabled: isProcessing(`sync-ad-${account.id}`),
                                    }] : []),
                                    {
                                        label: 'Re-import data…',
                                        icon: <Database className="h-3.5 w-3.5" />,
                                        onClick: () => setReimportTarget({
                                            type: 'ad',
                                            id: account.id,
                                            name: account.name,
                                            defaultDate: account.historical_import_from ?? new Date(Date.now() - 90 * 86400_000).toISOString().split('T')[0],
                                        }),
                                    },
                                    {
                                        label: 'Remove',
                                        icon: <Trash2 className="h-3.5 w-3.5" />,
                                        onClick: () => removeAdAccount(account.id, account.name),
                                        variant: 'destructive',
                                        disabled: isProcessing(`remove-ad-${account.id}`),
                                        separator: true,
                                    },
                                ];

                                return (
                                    <li key={account.id} className="flex items-center justify-between px-6 py-4">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="text-sm font-medium text-zinc-900">{account.name}</p>
                                                <StatusBadge status={account.status} />
                                                <ImportBadge status={account.historical_import_status} progress={account.historical_import_progress} />
                                            </div>
                                            <p className="mt-0.5 text-xs text-zinc-400">
                                                Account {account.external_id}
                                                {' · '}
                                                {account.currency}
                                                {' · '}
                                                {adAccountSyncLabel(account, syncing)}
                                            </p>
                                        </div>
                                        {canManage && (
                                            <div className="ml-4 flex shrink-0 items-center gap-2">
                                                {tokenExpired && (
                                                    <a
                                                        href={`/oauth/google/ads?reconnect_id=${account.id}`}
                                                        className="inline-flex items-center gap-1.5 rounded-md bg-amber-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-600 transition-colors"
                                                    >
                                                        <PlugZap className="h-3 w-3" />
                                                        Reconnect
                                                    </a>
                                                )}
                                                {importFailed && (
                                                    <button
                                                        onClick={() => retryImportAdAccount(account.id)}
                                                        disabled={isProcessing(`retry-ad-${account.id}`)}
                                                        className="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 transition-colors disabled:opacity-50"
                                                    >
                                                        <RotateCcw className="h-3 w-3" />
                                                        Resume import
                                                    </button>
                                                )}
                                                <IntegrationActionsMenu items={menuItems} />
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </SectionCard>

                {/* ── Google Search Console ── */}
                <SectionCard
                    title={`Search Console (${gsc_properties.length})`}
                    action={
                        canManage && !gsc_pending ? (
                            <a
                                href="/oauth/google/gsc"
                                onClick={() => setConnectingTo('gsc')}
                                className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
                            >
                                {connectingTo === 'gsc' && <Loader2 className="h-3 w-3 animate-spin" />}
                                Connect
                            </a>
                        ) : undefined
                    }
                >
                    {gsc_pending && <GscPropertyPicker pending={gsc_pending} />}
                    {oauth_error && oauth_platform === 'gsc' && (
                        <OAuthErrorRow message={oauth_error} />
                    )}
                    {gsc_properties.length === 0 && !gsc_pending && !(oauth_error && oauth_platform === 'gsc') && (
                        <EmptyRow message="No Search Console properties connected." />
                    )}
                    {gsc_properties.length > 0 && (
                        <ul className="divide-y divide-zinc-100">
                            {gsc_properties.map((prop) => {
                                const syncing = prop.sync_running || queuedGscIds.has(prop.id);
                                const importActive = prop.historical_import_status === 'running' || prop.historical_import_status === 'pending';
                                const canSync = ['active', 'error'].includes(prop.status) && !importActive && !syncing;
                                const tokenExpired = prop.status === 'token_expired';
                                const importFailed = prop.historical_import_status === 'failed' && !tokenExpired;

                                const menuItems: ActionItem[] = [
                                    ...(canSync ? [{
                                        label: 'Sync now',
                                        icon: <RefreshCw className="h-3.5 w-3.5" />,
                                        onClick: () => syncGsc(prop.id),
                                        disabled: isProcessing(`sync-gsc-${prop.id}`),
                                    }] : []),
                                    {
                                        label: 'Re-import data…',
                                        icon: <Database className="h-3.5 w-3.5" />,
                                        onClick: () => setReimportTarget({
                                            type: 'gsc',
                                            id: prop.id,
                                            name: formatGscProperty(prop.property_url),
                                            defaultDate: prop.historical_import_from ?? new Date(Date.now() - 90 * 86400_000).toISOString().split('T')[0],
                                            notice: PLATFORM_NOTICES.gsc,
                                        }),
                                    },
                                    {
                                        label: 'Remove',
                                        icon: <Trash2 className="h-3.5 w-3.5" />,
                                        onClick: () => removeGsc(prop.id, prop.property_url),
                                        variant: 'destructive',
                                        disabled: isProcessing(`remove-gsc-${prop.id}`),
                                        separator: true,
                                    },
                                ];

                                return (
                                    <li key={prop.id} className="flex items-center justify-between px-6 py-4">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="text-sm font-medium text-zinc-900 break-all">
                                                    {formatGscProperty(prop.property_url)}
                                                </p>
                                                <GscTypeBadge propertyUrl={prop.property_url} />
                                                <StatusBadge status={prop.status} />
                                                <ImportBadge status={prop.historical_import_status} progress={prop.historical_import_progress} />
                                            </div>
                                            <p className="mt-0.5 text-xs text-zinc-400">
                                                {gscSyncLabel(prop, syncing)}
                                            </p>
                                        </div>
                                        {canManage && (
                                            <div className="ml-4 flex shrink-0 items-center gap-2">
                                                {tokenExpired && (
                                                    <a
                                                        href={`/oauth/google/gsc?reconnect_id=${prop.id}`}
                                                        className="inline-flex items-center gap-1.5 rounded-md bg-amber-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-600 transition-colors"
                                                    >
                                                        <PlugZap className="h-3 w-3" />
                                                        Reconnect
                                                    </a>
                                                )}
                                                {importFailed && (
                                                    <button
                                                        onClick={() => retryImportGsc(prop.id)}
                                                        disabled={isProcessing(`retry-gsc-${prop.id}`)}
                                                        className="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 transition-colors disabled:opacity-50"
                                                    >
                                                        <RotateCcw className="h-3 w-3" />
                                                        Resume import
                                                    </button>
                                                )}
                                                <IntegrationActionsMenu items={menuItems} />
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </SectionCard>

            </div>

            {/* Re-import dialog — single instance, controlled by reimportTarget state */}
            <ReimportDialog
                open={reimportTarget !== null}
                onClose={() => setReimportTarget(null)}
                onConfirm={confirmReimport}
                defaultDate={reimportTarget?.defaultDate ?? ''}
                name={reimportTarget?.name ?? ''}
                processing={processingAction?.startsWith('reimport-') ?? false}
                notice={reimportTarget?.notice}
            />

        </AppLayout>
    );
}
