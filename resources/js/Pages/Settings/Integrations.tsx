import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Loader2, RefreshCw } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';

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
    consecutive_sync_failures: number;
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
}

interface GscPropertyItem {
    id: number;
    property_url: string;
    status: string;
    last_synced_at: string | null;
    consecutive_sync_failures: number;
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
}

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

function storeSyncLabel(store: StoreItem): string {
    if (store.last_synced_at) return `Synced ${formatRelativeTime(store.last_synced_at)}`;
    if (store.last_webhook_at) return `Webhook ${formatRelativeTime(store.last_webhook_at)}`;
    return 'Never synced';
}

type StatusVariant = 'green' | 'yellow' | 'red' | 'zinc';

function statusVariant(status: string): StatusVariant {
    switch (status) {
        case 'active':      return 'green';
        case 'connecting':  return 'yellow';
        case 'error':
        case 'token_expired': return 'red';
        case 'disconnected': return 'zinc';
        default:            return 'zinc';
    }
}

const STATUS_COLORS: Record<StatusVariant, string> = {
    green:  'bg-green-100 text-green-700',
    yellow: 'bg-yellow-100 text-yellow-700',
    red:    'bg-red-100 text-red-700',
    zinc:   'bg-zinc-100 text-zinc-500',
};

function StatusBadge({ status }: { status: string }) {
    const variant = statusVariant(status);
    const label = status.replace(/_/g, ' ');
    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${STATUS_COLORS[variant]}`}>
            {status === 'connecting' && <Loader2 className="h-3 w-3 animate-spin" />}
            {label}
        </span>
    );
}

function ImportBadge({ status }: { status: string | null }) {
    if (!status || status === 'completed') return null;
    const colors: Record<string, string> = {
        pending: 'bg-zinc-100 text-zinc-600',
        running: 'bg-blue-100 text-blue-700',
        failed:  'bg-red-100 text-red-700',
    };
    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium capitalize ${colors[status] ?? 'bg-zinc-100 text-zinc-600'}`}>
            {status === 'running' && <Loader2 className="h-3 w-3 animate-spin" />}
            Import {status}
        </span>
    );
}

function gscDisplayUrl(propertyUrl: string): string {
    if (propertyUrl.startsWith('sc-domain:')) {
        return propertyUrl.slice('sc-domain:'.length);
    }
    return propertyUrl.replace(/^https?:\/\//, '').replace(/\/$/, '');
}

function GscTypeBadge({ propertyUrl }: { propertyUrl: string }) {
    const isDomain = propertyUrl.startsWith('sc-domain:');
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${isDomain ? 'bg-violet-100 text-violet-700' : 'bg-sky-100 text-sky-700'}`}>
            {isDomain ? 'Domain' : 'URL prefix'}
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

// ─── GSC Property Picker ──────────────────────────────────────────────────────

function GscPropertyPicker({ pending }: { pending: GscPending }) {
    const { data, setData, post, processing, errors } = useForm({
        property_url:    '',
        gsc_pending_key: pending.key,
    });

    const submit = (e: React.SyntheticEvent) => {
        e.preventDefault();
        post(route('oauth.gsc.connect'));
    };

    return (
        <div className="bg-indigo-50 px-6 py-5">
            <p className="text-sm font-semibold text-indigo-900">Select a Search Console property to connect</p>
            <p className="mt-1 text-xs text-indigo-700">Choose the property you want to link to this workspace.</p>
            <form onSubmit={submit} className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div className="flex-1">
                    <label htmlFor="gsc-property" className="block text-xs font-medium text-indigo-800 mb-1">
                        Property
                    </label>
                    <select
                        id="gsc-property"
                        value={data.property_url}
                        onChange={(e) => setData('property_url', e.target.value)}
                        className="w-full rounded-md border border-indigo-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
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
                        href={route('settings.integrations')}
                        className="rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 transition-colors"
                    >
                        Cancel
                    </a>
                    <button
                        type="submit"
                        disabled={processing || data.property_url === ''}
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors"
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
    // Pre-select already-connected accounts so reconnecting refreshes their token.
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

    return (
        <div className="bg-indigo-50 px-6 py-5">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-sm font-semibold text-indigo-900">Select {label} accounts to connect</p>
                    <p className="mt-0.5 text-xs text-indigo-700">
                        Already-connected accounts are pre-selected — reconnecting refreshes their token.
                    </p>
                </div>
                <button type="button" onClick={toggleAll} className="text-xs text-indigo-600 hover:text-indigo-800">
                    {allSelected ? 'Deselect all' : 'Select all'}
                </button>
            </div>

            <form onSubmit={submit} className="mt-3">
                <ul className="divide-y divide-indigo-100 rounded-md border border-indigo-200 bg-white overflow-hidden">
                    {pending.items.map((account) => {
                        const isConnected = alreadyConnected(account.id);
                        return (
                            <li key={account.id}>
                                <label className="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-indigo-50 transition-colors">
                                    <input
                                        type="checkbox"
                                        checked={selected.includes(account.id)}
                                        onChange={() => toggle(account.id)}
                                        className="h-4 w-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500"
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
                        href={route('settings.integrations')}
                        className="rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 transition-colors"
                    >
                        Cancel
                    </a>
                    <button
                        type="submit"
                        disabled={processing || selected.length === 0}
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors"
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
}: Props) {
    const canManage = user_role === 'owner' || user_role === 'admin';

    const [connectingTo, setConnectingTo] = useState<string | null>(null);
    const [removingStoreSlug, setRemovingStoreSlug]     = useState<string | null>(null);
    const [removingAdAccountId, setRemovingAdAccountId] = useState<number | null>(null);
    const [removingGscId, setRemovingGscId]             = useState<number | null>(null);
    const [syncingStoreSlug, setSyncingStoreSlug]       = useState<string | null>(null);
    const [syncingAdAccountId, setSyncingAdAccountId]   = useState<number | null>(null);
    const [syncingGscId, setSyncingGscId]               = useState<number | null>(null);

    const removeStore = (slug: string, name: string) => {
        if (!confirm(`Remove "${name}"?\n\nThis will permanently delete all data for this store — orders, snapshots, and products. This cannot be undone.`)) return;
        setRemovingStoreSlug(slug);
        router.delete(route('settings.integrations.stores.disconnect', { storeSlug: slug }), {
            onFinish: () => setRemovingStoreSlug(null),
        });
    };

    const removeAdAccount = (id: number, name: string) => {
        if (!confirm(`Remove "${name}"?\n\nThis will permanently delete all ad data — campaigns, insights, and spend history. This cannot be undone.`)) return;
        setRemovingAdAccountId(id);
        router.delete(route('settings.integrations.ad-accounts.disconnect', id), {
            onFinish: () => setRemovingAdAccountId(null),
        });
    };

    const removeGsc = (id: number, url: string) => {
        if (!confirm(`Remove "${url}"?\n\nThis will permanently delete all Search Console data — clicks, impressions, queries, and pages. This cannot be undone.`)) return;
        setRemovingGscId(id);
        router.delete(route('settings.integrations.gsc.disconnect', id), {
            onFinish: () => setRemovingGscId(null),
        });
    };

    const facebookAccounts = ad_accounts.filter((a) => a.platform === 'facebook');
    const googleAccounts   = ad_accounts.filter((a) => a.platform === 'google');

    return (
        <AppLayout>
            <Head title="Integrations" />

            <PageHeader
                title="Integrations"
                subtitle="Connected stores, ad platforms, and Search Console properties"
            />

            <div className="mt-6 max-w-3xl space-y-6">

                {/* Stores */}
                <SectionCard
                    title={`Stores (${stores.length})`}
                    action={
                        canManage ? (
                            <a
                                href={route('onboarding') + '?add_store=1'}
                                onClick={() => setConnectingTo('store')}
                                className="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 transition-colors"
                            >
                                {connectingTo === 'store' && <Loader2 className="h-3 w-3 animate-spin" />}
                                Connect store
                            </a>
                        ) : undefined
                    }
                >
                    {stores.length === 0 ? (
                        <EmptyRow message="No stores connected yet. Connect a store to start tracking." />
                    ) : (
                        <ul className="divide-y divide-zinc-100">
                            {stores.map((store) => (
                                <li key={store.id} className="flex items-center justify-between px-6 py-4">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="text-sm font-medium text-zinc-900">{store.name}</p>
                                            <StatusBadge status={store.status} />
                                            <ImportBadge status={store.historical_import_status} />
                                            {store.consecutive_sync_failures >= 3 && (
                                                <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                                    {store.consecutive_sync_failures} failures
                                                </span>
                                            )}
                                        </div>
                                        <p className="mt-0.5 text-xs text-zinc-400">
                                            {PLATFORM_LABELS[store.type] ?? store.type}
                                            {' · '}
                                            {store.domain}
                                            {' · '}
                                            {store.currency}
                                            {' · '}
                                            {storeSyncLabel(store)}
                                        </p>
                                    </div>
                                    {canManage && (
                                        <div className="ml-4 flex shrink-0 items-center gap-3">
                                            {['active', 'error'].includes(store.status) && (
                                                <button
                                                    type="button"
                                                    disabled={syncingStoreSlug === store.slug}
                                                    onClick={() => {
                                                        setSyncingStoreSlug(store.slug);
                                                        router.post(
                                                            route('settings.integrations.stores.sync', { storeSlug: store.slug }),
                                                            {},
                                                            { onFinish: () => setSyncingStoreSlug(null) },
                                                        );
                                                    }}
                                                    className="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 disabled:opacity-50 transition-colors"
                                                >
                                                    {syncingStoreSlug === store.slug
                                                        ? <Loader2 className="h-3 w-3 animate-spin" />
                                                        : <RefreshCw className="h-3 w-3" />}
                                                    Sync now
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => removeStore(store.slug, store.name)}
                                                disabled={removingStoreSlug === store.slug}
                                                className="inline-flex items-center gap-1 text-xs text-red-600 hover:text-red-800 disabled:opacity-50 transition-colors"
                                            >
                                                {removingStoreSlug === store.slug && <Loader2 className="h-3 w-3 animate-spin" />}
                                                Remove
                                            </button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                {/* Facebook Ads */}
                <SectionCard
                    title={`Facebook Ads (${facebookAccounts.length})`}
                    action={
                        canManage && !fb_pending ? (
                            <a
                                href={route('oauth.facebook.redirect')}
                                onClick={() => setConnectingTo('facebook')}
                                className="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 transition-colors"
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
                            connectRoute={route('oauth.facebook.connect')}
                            pendingKeyField="fb_pending_key"
                            label="Facebook Ads"
                            connectedExternalIds={facebookAccounts.map((a) => a.external_id)}
                        />
                    )}
                    {facebookAccounts.length === 0 && !fb_pending && (
                        <EmptyRow message="No Facebook Ads accounts connected." />
                    )}
                    {facebookAccounts.length > 0 && (
                        <ul className="divide-y divide-zinc-100">
                            {facebookAccounts.map((account) => (
                                <li key={account.id} className="flex items-center justify-between px-6 py-4">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="text-sm font-medium text-zinc-900">{account.name}</p>
                                            <StatusBadge status={account.status} />
                                        </div>
                                        <p className="mt-0.5 text-xs text-zinc-400">
                                            Account {account.external_id}
                                            {' · '}
                                            {account.currency}
                                            {' · '}
                                            Synced {formatRelativeTime(account.last_synced_at)}
                                        </p>
                                    </div>
                                    {canManage && (
                                        <div className="ml-4 flex shrink-0 items-center gap-3">
                                            {['active', 'error'].includes(account.status) && (
                                                <button
                                                    type="button"
                                                    disabled={syncingAdAccountId === account.id}
                                                    onClick={() => {
                                                        setSyncingAdAccountId(account.id);
                                                        router.post(
                                                            route('settings.integrations.ad-accounts.sync', account.id),
                                                            {},
                                                            { onFinish: () => setSyncingAdAccountId(null) },
                                                        );
                                                    }}
                                                    className="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 disabled:opacity-50 transition-colors"
                                                >
                                                    {syncingAdAccountId === account.id
                                                        ? <Loader2 className="h-3 w-3 animate-spin" />
                                                        : <RefreshCw className="h-3 w-3" />}
                                                    Sync now
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => removeAdAccount(account.id, account.name)}
                                                disabled={removingAdAccountId === account.id}
                                                className="inline-flex items-center gap-1 text-xs text-red-600 hover:text-red-800 disabled:opacity-50 transition-colors"
                                            >
                                                {removingAdAccountId === account.id && <Loader2 className="h-3 w-3 animate-spin" />}
                                                Remove
                                            </button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                {/* Google Ads */}
                <SectionCard
                    title={`Google Ads (${googleAccounts.length})`}
                    action={
                        canManage && !gads_pending ? (
                            <a
                                href={route('oauth.google.ads.redirect')}
                                onClick={() => setConnectingTo('google_ads')}
                                className="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 transition-colors"
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
                            connectRoute={route('oauth.google.ads.connect')}
                            pendingKeyField="gads_pending_key"
                            label="Google Ads"
                            connectedExternalIds={googleAccounts.map((a) => a.external_id)}
                        />
                    )}
                    {googleAccounts.length === 0 && !gads_pending && (
                        <EmptyRow message="No Google Ads accounts connected." />
                    )}
                    {googleAccounts.length > 0 && (
                        <ul className="divide-y divide-zinc-100">
                            {googleAccounts.map((account) => (
                                <li key={account.id} className="flex items-center justify-between px-6 py-4">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="text-sm font-medium text-zinc-900">{account.name}</p>
                                            <StatusBadge status={account.status} />
                                        </div>
                                        <p className="mt-0.5 text-xs text-zinc-400">
                                            Account {account.external_id}
                                            {' · '}
                                            {account.currency}
                                            {' · '}
                                            Synced {formatRelativeTime(account.last_synced_at)}
                                        </p>
                                    </div>
                                    {canManage && (
                                        <div className="ml-4 flex shrink-0 items-center gap-3">
                                            {['active', 'error'].includes(account.status) && (
                                                <button
                                                    type="button"
                                                    disabled={syncingAdAccountId === account.id}
                                                    onClick={() => {
                                                        setSyncingAdAccountId(account.id);
                                                        router.post(
                                                            route('settings.integrations.ad-accounts.sync', account.id),
                                                            {},
                                                            { onFinish: () => setSyncingAdAccountId(null) },
                                                        );
                                                    }}
                                                    className="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 disabled:opacity-50 transition-colors"
                                                >
                                                    {syncingAdAccountId === account.id
                                                        ? <Loader2 className="h-3 w-3 animate-spin" />
                                                        : <RefreshCw className="h-3 w-3" />}
                                                    Sync now
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => removeAdAccount(account.id, account.name)}
                                                disabled={removingAdAccountId === account.id}
                                                className="inline-flex items-center gap-1 text-xs text-red-600 hover:text-red-800 disabled:opacity-50 transition-colors"
                                            >
                                                {removingAdAccountId === account.id && <Loader2 className="h-3 w-3 animate-spin" />}
                                                Remove
                                            </button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                {/* Google Search Console */}
                <SectionCard
                    title={`Search Console (${gsc_properties.length})`}
                    action={
                        canManage && !gsc_pending ? (
                            <a
                                href={route('oauth.google.gsc.redirect')}
                                onClick={() => setConnectingTo('gsc')}
                                className="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 transition-colors"
                            >
                                {connectingTo === 'gsc' && <Loader2 className="h-3 w-3 animate-spin" />}
                                Connect
                            </a>
                        ) : undefined
                    }
                >
                    {gsc_pending && <GscPropertyPicker pending={gsc_pending} />}
                    {gsc_properties.length === 0 && !gsc_pending && (
                        <EmptyRow message="No Search Console properties connected." />
                    )}
                    {gsc_properties.length > 0 && (
                        <ul className="divide-y divide-zinc-100">
                            {gsc_properties.map((prop) => (
                                <li key={prop.id} className="flex items-center justify-between px-6 py-4">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="text-sm font-medium text-zinc-900 break-all">
                                                {gscDisplayUrl(prop.property_url)}
                                            </p>
                                            <GscTypeBadge propertyUrl={prop.property_url} />
                                            <StatusBadge status={prop.status} />
                                        </div>
                                        <p className="mt-0.5 text-xs text-zinc-400">
                                            Synced {formatRelativeTime(prop.last_synced_at)}
                                        </p>
                                    </div>
                                    {canManage && (
                                        <div className="ml-4 flex shrink-0 items-center gap-3">
                                            {['active', 'error'].includes(prop.status) && (
                                                <button
                                                    type="button"
                                                    disabled={syncingGscId === prop.id}
                                                    onClick={() => {
                                                        setSyncingGscId(prop.id);
                                                        router.post(
                                                            route('settings.integrations.gsc.sync', prop.id),
                                                            {},
                                                            { onFinish: () => setSyncingGscId(null) },
                                                        );
                                                    }}
                                                    className="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 disabled:opacity-50 transition-colors"
                                                >
                                                    {syncingGscId === prop.id
                                                        ? <Loader2 className="h-3 w-3 animate-spin" />
                                                        : <RefreshCw className="h-3 w-3" />}
                                                    Sync now
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => removeGsc(prop.id, prop.property_url)}
                                                disabled={removingGscId === prop.id}
                                                className="inline-flex items-center gap-1 text-xs text-red-600 hover:text-red-800 disabled:opacity-50 transition-colors"
                                            >
                                                {removingGscId === prop.id && <Loader2 className="h-3 w-3 animate-spin" />}
                                                Remove
                                            </button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

            </div>
        </AppLayout>
    );
}
