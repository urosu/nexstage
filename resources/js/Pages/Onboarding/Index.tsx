import OnboardingLayout from '@/Components/layouts/OnboardingLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatGscProperty } from '@/lib/gsc';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';
import { useEffect, useRef, useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface AdAccount {
    id: string;
    name: string;
    currency: string;
}

interface Pending<T> {
    key: string;
    items: T;
}

interface ImportStatus {
    status: 'pending' | 'running' | 'completed' | 'failed' | null;
    progress: number | null;
    total_orders: number | null;
    started_at: string | null;
    completed_at: string | null;
    duration_seconds: number | null;
    error_message: string | null;
}

interface Props {
    step: 1 | 2 | 3 | 4;
    // step 1
    has_ads?: boolean;
    has_gsc?: boolean;
    fb_pending?: Pending<AdAccount[]> | null;
    gads_pending?: Pending<AdAccount[]> | null;
    gsc_pending?: Pending<string[]> | null;
    oauth_error?: string | null;
    oauth_platform?: string | null;
    // step 2 (country prompt)
    store_id?: number;
    store_name?: string;
    website_url?: string | null;
    country?: string | null;
    ip_detected_country?: string | null;
    // step 4 (progress)
    store_slug?: string;
    workspace_slug?: string;
    // step 1 only — passed explicitly because shared `workspace`/`workspaces` props are null on
    // onboarding routes (HandleInertiaRequests::share() runs before the controller sets WorkspaceContext)
    has_other_workspaces?: boolean;
    is_workspace_owner?: boolean;
    current_workspace_id?: number;
}

// ---------------------------------------------------------------------------
// Shared UI
// ---------------------------------------------------------------------------

function ConnectedBadge() {
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
            <svg className="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                <path
                    fillRule="evenodd"
                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                    clipRule="evenodd"
                />
            </svg>
            Connected
        </span>
    );
}

// ---------------------------------------------------------------------------
// Store Tile — WooCommerce or Shopify connection form
// ---------------------------------------------------------------------------

function WooCommerceForm() {
    const { data, setData, post, processing, errors } = useForm({
        domain: '',
        consumer_key: '',
        consumer_secret: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(route('onboarding.store'));
    }

    return (
        <>
            <p className="mb-4 text-xs text-zinc-500">
                Go to <strong>WooCommerce → Settings → Advanced → REST API</strong> and create
                a key with <strong>Read/Write</strong> permissions.
            </p>

            <form onSubmit={submit} className="space-y-3">
                <div>
                    <Label htmlFor="domain" className="text-xs">Store URL</Label>
                    <Input
                        id="domain"
                        type="url"
                        placeholder="https://yourstore.com"
                        value={data.domain}
                        className="mt-1 h-9 text-sm"
                        onChange={(e) => setData('domain', e.target.value)}
                        required
                        autoFocus
                    />
                    {errors.domain && <p className="mt-1 text-xs text-red-600">{errors.domain}</p>}
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label htmlFor="consumer_key" className="text-xs">Consumer key</Label>
                        <Input
                            id="consumer_key"
                            placeholder="ck_…"
                            value={data.consumer_key}
                            className="mt-1 h-9 font-mono text-xs"
                            onChange={(e) => setData('consumer_key', e.target.value)}
                            required
                        />
                        {errors.consumer_key && <p className="mt-1 text-xs text-red-600">{errors.consumer_key}</p>}
                    </div>
                    <div>
                        <Label htmlFor="consumer_secret" className="text-xs">Consumer secret</Label>
                        <Input
                            id="consumer_secret"
                            type="password"
                            placeholder="cs_…"
                            value={data.consumer_secret}
                            className="mt-1 h-9 font-mono text-xs"
                            onChange={(e) => setData('consumer_secret', e.target.value)}
                            required
                        />
                        {errors.consumer_secret && <p className="mt-1 text-xs text-red-600">{errors.consumer_secret}</p>}
                    </div>
                </div>

                <Button type="submit" disabled={processing} className="w-full">
                    {processing ? 'Connecting…' : 'Connect store'}
                </Button>
            </form>
        </>
    );
}

function ShopifyForm({ workspaceId }: { workspaceId?: number }) {
    const [shop, setShop] = useState('');
    const wsParam = workspaceId ? `&workspace_id=${workspaceId}` : '';

    return (
        <>
            <p className="mb-4 text-xs text-zinc-500">
                Enter your myshopify.com domain. You will be redirected to Shopify to approve
                the connection.
            </p>

            <div className="space-y-3">
                <div>
                    <Label htmlFor="shopify-domain" className="text-xs">Shopify domain</Label>
                    <Input
                        id="shopify-domain"
                        type="text"
                        placeholder="my-store.myshopify.com"
                        value={shop}
                        className="mt-1 h-9 text-sm"
                        onChange={(e) => setShop(e.target.value)}
                        autoFocus
                    />
                </div>

                <a
                    href={route('shopify.install') + '?shop=' + encodeURIComponent(shop) + '&from=onboarding' + wsParam}
                    className={[
                        'flex w-full items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors',
                        shop.trim()
                            ? 'bg-[#008060] text-white hover:bg-[#006e52]'
                            : 'pointer-events-none cursor-not-allowed bg-zinc-100 text-zinc-400',
                    ].join(' ')}
                    aria-disabled={!shop.trim()}
                >
                    Connect with Shopify
                </a>
            </div>
        </>
    );
}

function StoreTile({ workspaceId }: { workspaceId?: number }) {
    const [platform, setPlatform] = useState<'woocommerce' | 'shopify'>('woocommerce');

    return (
        <div className="rounded-lg border border-zinc-200 bg-white p-5">
            {/* Header */}
            <div className="mb-4 flex items-center gap-3">
                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-zinc-100">
                    <svg className="h-5 w-5 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 2.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z" />
                    </svg>
                </div>
                <div>
                    <div className="text-sm font-semibold text-zinc-900">Ecommerce Store</div>
                    <div className="text-xs text-zinc-500">WooCommerce or Shopify</div>
                </div>
            </div>

            {/* Platform toggle */}
            <div className="mb-4 flex rounded-md border border-zinc-200 p-0.5">
                <button
                    type="button"
                    onClick={() => setPlatform('woocommerce')}
                    className={[
                        'flex flex-1 items-center justify-center gap-1.5 rounded py-1.5 text-xs font-medium transition-colors',
                        platform === 'woocommerce'
                            ? 'bg-[#7f54b3] text-white'
                            : 'text-zinc-500 hover:text-zinc-700',
                    ].join(' ')}
                >
                    {/* WooCommerce W logo */}
                    <svg className="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M2.047 5.651C2.622 4.866 3.497 4.47 4.674 4.47h14.652c1.177 0 2.052.396 2.627 1.181.574.786.707 1.759.396 2.918l-2.363 9.48c-.265 1.018-.795 1.808-1.59 2.369-.796.562-1.712.844-2.75.844H8.354c-1.037 0-1.953-.282-2.75-.844-.795-.561-1.325-1.35-1.59-2.369L1.651 8.569c-.31-1.16-.178-2.132.396-2.918z" />
                    </svg>
                    WooCommerce
                </button>
                <button
                    type="button"
                    onClick={() => setPlatform('shopify')}
                    className={[
                        'flex flex-1 items-center justify-center gap-1.5 rounded py-1.5 text-xs font-medium transition-colors',
                        platform === 'shopify'
                            ? 'bg-[#008060] text-white'
                            : 'text-zinc-500 hover:text-zinc-700',
                    ].join(' ')}
                >
                    {/* Shopify bag icon */}
                    <svg className="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M15.337 2.088c-.054-.028-.19-.056-.352-.056-.163 0-.38.027-.569.082C14.225 1.406 13.576.78 12.715.78c-.027 0-.055 0-.081.002C12.443.296 12.036 0 11.575 0c-1.795 0-2.659 2.245-2.93 3.386-.692.214-1.182.366-1.234.383-.383.12-.394.132-.444.495C6.93 4.493 5 18.92 5 18.92L16.754 21 21 19.686S15.391 2.116 15.337 2.088zm-2.667.724c-.452.14-.959.298-1.467.455.283-1.093.823-1.622 1.298-1.83.172.44.222 1.064.169 1.375zm-.703-2.003c.084 0 .165.027.241.08-.583.274-1.208.967-1.47 2.348l-1.11.345c.31-1.06 1.04-2.773 2.339-2.773zM12.016 5.25c.467 0 .846.028 1.16.074l-.014.055c-.357 1.307-.507 1.842-.507 2.619 0 .777.406 1.33.812 1.852.314.402.61.78.61 1.215 0 .647-.457 1.128-.88 1.128-.611 0-.912-.46-.912-.46l-.285-2.005-1.038-.087-.195 2.006s-.307.546-.954.546c-.37 0-.778-.32-.778-1.128 0-.435.296-.813.61-1.215.406-.522.812-1.075.812-1.852 0-.777-.15-1.312-.507-2.62l-.014-.054c.314-.046.693-.074 1.16-.074h.92z"/>
                    </svg>
                    Shopify
                </button>
            </div>

            {platform === 'woocommerce' ? <WooCommerceForm /> : <ShopifyForm workspaceId={workspaceId} />}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Ad Accounts Tile
// ---------------------------------------------------------------------------

function AccountPicker({
    pending,
    pendingField,
    connectRoute,
}: {
    pending: Pending<AdAccount[]>;
    pendingField: string;
    connectRoute: string;
}) {
    const [selected, setSelected] = useState<string[]>(
        pending.items.map((a) => a.id),
    );
    const [submitting, setSubmitting] = useState(false);

    function toggle(id: string) {
        setSelected((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (selected.length === 0) return;
        setSubmitting(true);
        router.post(
            route(connectRoute),
            { [pendingField]: pending.key, account_ids: selected },
            { onFinish: () => setSubmitting(false) },
        );
    }

    return (
        <form onSubmit={submit} className="mt-3 space-y-2">
            <p className="text-xs text-zinc-500">Select the accounts to connect:</p>
            {pending.items.map((account) => (
                <label
                    key={account.id}
                    className={[
                        'flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 text-sm transition-colors',
                        selected.includes(account.id)
                            ? 'border-primary bg-primary/5'
                            : 'border-zinc-200 hover:border-zinc-300',
                    ].join(' ')}
                >
                    <input
                        type="checkbox"
                        checked={selected.includes(account.id)}
                        onChange={() => toggle(account.id)}
                        className="h-3.5 w-3.5 accent-primary"
                    />
                    <span className="flex-1 font-medium text-zinc-900">{account.name}</span>
                    <span className="text-xs text-zinc-400">{account.currency}</span>
                </label>
            ))}
            <Button
                type="submit"
                disabled={submitting || selected.length === 0}
                className="mt-2 w-full"
                size="sm"
            >
                {submitting ? 'Connecting…' : `Connect ${selected.length} account${selected.length !== 1 ? 's' : ''}`}
            </Button>
        </form>
    );
}

function AdAccountsTile({
    hasAds,
    fbPending,
    gadsPending,
}: {
    hasAds: boolean;
    fbPending: Pending<AdAccount[]> | null | undefined;
    gadsPending: Pending<AdAccount[]> | null | undefined;
}) {
    return (
        <div className="rounded-lg border border-zinc-200 bg-white p-5">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50">
                        <svg className="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                        </svg>
                    </div>
                    <div>
                        <div className="text-sm font-semibold text-zinc-900">Ad Accounts</div>
                        <div className="text-xs text-zinc-500">Facebook & Google Ads</div>
                    </div>
                </div>
                {hasAds && !fbPending && !gadsPending && <ConnectedBadge />}
            </div>

            {/* Facebook pending account picker */}
            {fbPending && (
                <div className="mt-3 rounded-md bg-blue-50 px-3 py-2">
                    <p className="text-xs font-medium text-blue-800">Facebook — select accounts to connect</p>
                    <AccountPicker
                        pending={fbPending}
                        pendingField="fb_pending_key"
                        connectRoute="oauth.facebook.connect"
                    />
                </div>
            )}

            {/* Google Ads pending account picker */}
            {gadsPending && (
                <div className="mt-3 rounded-md bg-blue-50 px-3 py-2">
                    <p className="text-xs font-medium text-blue-800">Google Ads — select accounts to connect</p>
                    <AccountPicker
                        pending={gadsPending}
                        pendingField="gads_pending_key"
                        connectRoute="oauth.google.ads.connect"
                    />
                </div>
            )}

            {/* OAuth buttons — shown when not connected OR to add more */}
            {(!hasAds || fbPending || gadsPending) && !fbPending && !gadsPending && (
                <div className="mt-4 flex flex-col gap-2">
                    <a
                        href={route('oauth.facebook.redirect') + '?from=onboarding'}
                        className="flex items-center justify-center gap-2 rounded-md border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-50"
                    >
                        <svg className="h-4 w-4 text-[#1877F2]" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
                        </svg>
                        Connect Facebook Ads
                    </a>
                    <a
                        href={route('oauth.google.ads.redirect') + '?from=onboarding'}
                        className="flex items-center justify-center gap-2 rounded-md border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-50"
                    >
                        <svg className="h-4 w-4" viewBox="0 0 24 24">
                            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
                            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
                            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
                            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
                        </svg>
                        Connect Google Ads
                    </a>
                </div>
            )}

            {/* Already connected — offer to add more */}
            {hasAds && !fbPending && !gadsPending && (
                <div className="mt-4 flex gap-2">
                    <a
                        href={route('oauth.facebook.redirect') + '?from=onboarding'}
                        className="text-xs text-zinc-400 hover:text-zinc-600"
                    >
                        + Add Facebook
                    </a>
                    <span className="text-xs text-zinc-300">·</span>
                    <a
                        href={route('oauth.google.ads.redirect') + '?from=onboarding'}
                        className="text-xs text-zinc-400 hover:text-zinc-600"
                    >
                        + Add Google Ads
                    </a>
                </div>
            )}

            {/* UTM tracking nudge — shown in the main app after onboarding, not here.
                See: PLANNING.md "Tag Generator" — prominent reference after ad connect */}
        </div>
    );
}

// ---------------------------------------------------------------------------
// GSC Tile
// ---------------------------------------------------------------------------

function GscPropertyPicker({ pending }: { pending: Pending<string[]> }) {
    const [selected, setSelected] = useState(pending.items[0] ?? '');
    const [submitting, setSubmitting] = useState(false);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!selected) return;
        setSubmitting(true);
        router.post(
            route('oauth.gsc.connect'),
            { property_url: selected, gsc_pending_key: pending.key },
            { onFinish: () => setSubmitting(false) },
        );
    }

    return (
        <form onSubmit={submit} className="mt-3 space-y-2">
            <p className="text-xs text-zinc-500">Select a property to connect:</p>
            {pending.items.map((url) => (
                <label
                    key={url}
                    className={[
                        'flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 text-sm transition-colors',
                        selected === url
                            ? 'border-primary bg-primary/5'
                            : 'border-zinc-200 hover:border-zinc-300',
                    ].join(' ')}
                >
                    <input
                        type="radio"
                        name="property_url"
                        checked={selected === url}
                        onChange={() => setSelected(url)}
                        className="h-3.5 w-3.5 accent-primary"
                    />
                    <span className="flex-1 break-all font-medium text-zinc-900">{formatGscProperty(url)}</span>
                </label>
            ))}
            <Button
                type="submit"
                disabled={submitting || !selected}
                className="mt-2 w-full"
                size="sm"
            >
                {submitting ? 'Connecting…' : 'Connect property'}
            </Button>
        </form>
    );
}

function GscTile({
    hasGsc,
    gscPending,
}: {
    hasGsc: boolean;
    gscPending: Pending<string[]> | null | undefined;
}) {
    return (
        <div className="rounded-lg border border-zinc-200 bg-white p-5">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50">
                        <svg className="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>
                    <div>
                        <div className="text-sm font-semibold text-zinc-900">Organic Search</div>
                        <div className="text-xs text-zinc-500">Google Search Console</div>
                    </div>
                </div>
                {hasGsc && !gscPending && <ConnectedBadge />}
            </div>

            {gscPending && (
                <div className="mt-3 rounded-md bg-emerald-50 px-3 py-2">
                    <p className="text-xs font-medium text-emerald-800">Select a Search Console property</p>
                    <GscPropertyPicker pending={gscPending} />
                </div>
            )}

            {!hasGsc && !gscPending && (
                <div className="mt-4">
                    <a
                        href={route('oauth.google.gsc.redirect') + '?from=onboarding'}
                        className="flex items-center justify-center gap-2 rounded-md border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-50"
                    >
                        <svg className="h-4 w-4" viewBox="0 0 24 24">
                            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4" />
                            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
                            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
                            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
                        </svg>
                        Connect Google Search Console
                    </a>
                </div>
            )}

            {hasGsc && !gscPending && (
                <div className="mt-4">
                    <a
                        href={route('oauth.google.gsc.redirect') + '?from=onboarding'}
                        className="text-xs text-zinc-400 hover:text-zinc-600"
                    >
                        + Add another property
                    </a>
                </div>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Step 1 — Connection tiles
// ---------------------------------------------------------------------------

function StepConnect({
    hasAds,
    hasGsc,
    fbPending,
    gadsPending,
    gscPending,
    oauthError,
    hasOtherWorkspaces,
    isWorkspaceOwner,
    currentWorkspaceId,
}: {
    hasAds: boolean;
    hasGsc: boolean;
    fbPending: Pending<AdAccount[]> | null | undefined;
    gadsPending: Pending<AdAccount[]> | null | undefined;
    gscPending: Pending<string[]> | null | undefined;
    oauthError: string | null | undefined;
    hasOtherWorkspaces: boolean;
    isWorkspaceOwner: boolean;
    currentWorkspaceId: number | undefined;
}) {
    const hasAnyConnection = hasAds || hasGsc;
    const { workspace } = usePage<PageProps>().props;
    const w = (path: string) => wurl(workspace?.slug, path);

    return (
        <>
            <Head title="Connect your integrations" />

            <h1 className="text-lg font-semibold text-zinc-900">Connect what you have</h1>
            <p className="mt-1 text-sm text-zinc-500">
                You can add more integrations later from Settings.
            </p>

            {/* OAuth error banner */}
            {oauthError && (
                <div className="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {oauthError}
                </div>
            )}

            <div className="mt-6 space-y-4">
                <StoreTile workspaceId={currentWorkspaceId} />
                <AdAccountsTile hasAds={hasAds} fbPending={fbPending} gadsPending={gadsPending} />
                <GscTile hasGsc={hasGsc} gscPending={gscPending} />
            </div>

            {/* Continue to dashboard CTA — only when something is connected but no store */}
            {hasAnyConnection && (
                <div className="mt-6 border-t border-zinc-100 pt-5 text-center">
                    <Button
                        variant="outline"
                        className="w-full"
                        onClick={() => router.visit(w('/dashboard'))}
                    >
                        Continue to dashboard →
                    </Button>
                    <p className="mt-2 text-xs text-zinc-400">
                        You can connect a store later from Settings → Integrations.
                    </p>
                </div>
            )}

            {/* Discard — only owners can discard; non-owners (invited members) cannot */}
            {hasOtherWorkspaces && isWorkspaceOwner && !hasAnyConnection && (
                <div className="mt-6 border-t border-zinc-100 pt-5 text-center">
                    <button
                        type="button"
                        onClick={() => router.delete(route('workspaces.discard', { workspace: currentWorkspaceId }))}
                        className="text-sm text-zinc-400 hover:text-zinc-600"
                    >
                        ← Cancel and go back
                    </button>
                </div>
            )}
        </>
    );
}

// ---------------------------------------------------------------------------
// Step 2 — Store country prompt
// ---------------------------------------------------------------------------

/**
 * Map common ccTLDs to ISO 3166-1 alpha-2 country codes.
 * Used to pre-fill the dropdown from stores.website_url.
 */
const CCTLD_TO_COUNTRY: Record<string, string> = {
    ac: 'GB', ad: 'AD', ae: 'AE', at: 'AT', au: 'AU', be: 'BE', bg: 'BG',
    br: 'BR', ca: 'CA', ch: 'CH', cn: 'CN', cy: 'CY', cz: 'CZ', de: 'DE',
    dk: 'DK', ee: 'EE', es: 'ES', fi: 'FI', fr: 'FR', gb: 'GB', gr: 'GR',
    hr: 'HR', hu: 'HU', ie: 'IE', it: 'IT', jp: 'JP', kr: 'KR', lt: 'LT',
    lu: 'LU', lv: 'LV', mt: 'MT', mx: 'MX', nl: 'NL', no: 'NO', nz: 'NZ',
    pl: 'PL', pt: 'PT', ro: 'RO', ru: 'RU', se: 'SE', si: 'SI', sk: 'SK',
    tr: 'TR', ua: 'UA', uk: 'GB',
};

const COUNTRY_OPTIONS: { code: string; name: string }[] = [
    { code: 'AD', name: 'Andorra' },       { code: 'AE', name: 'UAE' },
    { code: 'AT', name: 'Austria' },        { code: 'AU', name: 'Australia' },
    { code: 'BE', name: 'Belgium' },        { code: 'BG', name: 'Bulgaria' },
    { code: 'BR', name: 'Brazil' },         { code: 'CA', name: 'Canada' },
    { code: 'CH', name: 'Switzerland' },    { code: 'CN', name: 'China' },
    { code: 'CY', name: 'Cyprus' },         { code: 'CZ', name: 'Czech Republic' },
    { code: 'DE', name: 'Germany' },        { code: 'DK', name: 'Denmark' },
    { code: 'EE', name: 'Estonia' },        { code: 'ES', name: 'Spain' },
    { code: 'FI', name: 'Finland' },        { code: 'FR', name: 'France' },
    { code: 'GB', name: 'United Kingdom' }, { code: 'GR', name: 'Greece' },
    { code: 'HR', name: 'Croatia' },        { code: 'HU', name: 'Hungary' },
    { code: 'IE', name: 'Ireland' },        { code: 'IT', name: 'Italy' },
    { code: 'JP', name: 'Japan' },          { code: 'KR', name: 'South Korea' },
    { code: 'LT', name: 'Lithuania' },      { code: 'LU', name: 'Luxembourg' },
    { code: 'LV', name: 'Latvia' },         { code: 'MT', name: 'Malta' },
    { code: 'MX', name: 'Mexico' },         { code: 'NL', name: 'Netherlands' },
    { code: 'NO', name: 'Norway' },         { code: 'NZ', name: 'New Zealand' },
    { code: 'PL', name: 'Poland' },         { code: 'PT', name: 'Portugal' },
    { code: 'RO', name: 'Romania' },        { code: 'RU', name: 'Russia' },
    { code: 'SE', name: 'Sweden' },         { code: 'SI', name: 'Slovenia' },
    { code: 'SK', name: 'Slovakia' },       { code: 'TR', name: 'Turkey' },
    { code: 'UA', name: 'Ukraine' },        { code: 'US', name: 'United States' },
];

/** Detect a country code from a store URL by reading the ccTLD. Returns null for .com/.net/etc. */
function detectCountryFromUrl(url: string | null): string | null {
    if (!url) return null;
    try {
        const hostname = new URL(url.startsWith('http') ? url : `https://${url}`).hostname;
        const parts = hostname.split('.');
        const tld = parts[parts.length - 1].toLowerCase();
        // Handle co.uk, co.nz, etc. — look for "co" + known ccTLD
        if (parts.length >= 3 && parts[parts.length - 2].toLowerCase() === 'co') {
            const ccTld = tld;
            if (CCTLD_TO_COUNTRY[ccTld]) return CCTLD_TO_COUNTRY[ccTld];
        }
        return CCTLD_TO_COUNTRY[tld] ?? null;
    } catch {
        return null;
    }
}

function StepCountry({
    storeId,
    storeName,
    websiteUrl,
    initialCountry,
    ipDetectedCountry,
}: {
    storeId: number;
    storeName: string;
    websiteUrl: string | null;
    initialCountry: string | null;
    /** Country code detected from the user's IP on first login (lowest-priority fallback). */
    ipDetectedCountry: string | null;
}) {
    const detected = detectCountryFromUrl(websiteUrl);
    // Priority: stored DB value > ccTLD > IP geolocation
    const [selected, setSelected] = useState<string>(initialCountry ?? detected ?? ipDetectedCountry ?? '');
    const [processing, setProcessing] = useState(false);

    function handleSave(e: React.FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.post(
            route('onboarding.country'),
            { store_id: storeId, country_code: selected || null },
            { onFinish: () => setProcessing(false) },
        );
    }

    function handleSkip() {
        setProcessing(true);
        router.post(
            route('onboarding.country'),
            { store_id: storeId, country_code: null },
            { onFinish: () => setProcessing(false) },
        );
    }

    return (
        <>
            <Head title="Store setup" />

            <h1 className="text-lg font-semibold text-zinc-900">Where does {storeName} mainly sell?</h1>
            <p className="mt-1 text-sm text-zinc-500">
                Used as a fallback when ad campaign names don't include a country code.
                Multi-country stores can skip this.
            </p>

            {detected && !initialCountry && (
                <p className="mt-3 text-xs text-zinc-400">
                    Detected from <span className="font-mono">{websiteUrl}</span>
                </p>
            )}
            {!detected && !initialCountry && ipDetectedCountry && (
                <p className="mt-3 text-xs text-zinc-400">
                    Pre-filled from your location — change if needed.
                </p>
            )}

            <form onSubmit={handleSave} className="mt-6 space-y-5">
                <div>
                    <label className="mb-1.5 block text-xs font-medium text-zinc-500">
                        Primary country
                    </label>
                    <select
                        value={selected}
                        onChange={(e) => setSelected(e.target.value)}
                        className="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="">Select a country…</option>
                        {COUNTRY_OPTIONS.map((c) => (
                            <option key={c.code} value={c.code}>
                                {c.name} ({c.code})
                            </option>
                        ))}
                    </select>
                </div>

                <Button type="submit" className="w-full" disabled={!selected || processing}>
                    Continue →
                </Button>
            </form>

            <div className="mt-4 text-center">
                <button
                    type="button"
                    onClick={handleSkip}
                    disabled={processing}
                    className="text-sm text-zinc-400 hover:text-zinc-600"
                >
                    Skip for now
                </button>
            </div>
        </>
    );
}

// ---------------------------------------------------------------------------
// Step 3 — Choose import date range
// ---------------------------------------------------------------------------

const PERIODS = [
    {
        value: '30days',
        label: 'Last 30 days',
        description: 'Quick start — good for recent trends',
    },
    {
        value: '90days',
        label: 'Last 90 days',
        description: 'Three months of order history',
    },
    {
        value: '1year',
        label: 'Last year',
        description: 'Full year for seasonal comparisons',
    },
    {
        value: 'all',
        label: 'All history',
        description: 'Everything since your store opened',
    },
] as const;

function StepDateRange({
    storeId,
    storeName,
}: {
    storeId: number;
    storeName: string;
}) {
    const { data, setData, post, processing } = useForm<{
        store_id: number;
        period: string;
    }>({
        store_id: storeId,
        period: '90days',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(route('onboarding.import'));
    }

    return (
        <>
            <Head title="Choose import range" />

            <h1 className="text-lg font-semibold text-zinc-900">
                How much history should we import?
            </h1>
            <p className="mt-1 text-sm text-zinc-500">
                Connected to <span className="font-medium text-zinc-700">{storeName}</span>.
                We'll count your orders before starting — you'll see a time estimate on
                the next screen.
            </p>

            <form onSubmit={submit} className="mt-6 space-y-3">
                <input type="hidden" name="store_id" value={data.store_id} />

                {PERIODS.map((p) => (
                    <label
                        key={p.value}
                        className={[
                            'flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors',
                            data.period === p.value
                                ? 'border-primary bg-primary/10'
                                : 'border-zinc-200 hover:border-zinc-300 hover:bg-zinc-50',
                        ].join(' ')}
                    >
                        <input
                            type="radio"
                            name="period"
                            value={p.value}
                            checked={data.period === p.value}
                            onChange={() => setData('period', p.value)}
                            className="mt-0.5 h-4 w-4 accent-primary"
                        />
                        <div>
                            <div className="text-sm font-medium text-zinc-900">
                                {p.label}
                            </div>
                            <div className="text-xs text-zinc-500">
                                {p.description}
                            </div>
                        </div>
                    </label>
                ))}

                <div className="pt-2">
                    <Button type="submit" disabled={processing} className="w-full">
                        {processing ? 'Starting…' : 'Start import'}
                    </Button>
                </div>
            </form>

            <div className="mt-4 border-t border-zinc-100 pt-4 text-center">
                <button
                    type="button"
                    onClick={() => router.post(route('onboarding.reset'))}
                    className="text-sm text-zinc-400 hover:text-zinc-600"
                >
                    ← Start over
                </button>
            </div>
        </>
    );
}

// ---------------------------------------------------------------------------
// Step 4 — Import progress polling
// ---------------------------------------------------------------------------

function formatDuration(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return s > 0 ? `${m}m ${s}s` : `${m}m`;
}

function formatEstimate(totalOrders: number, startedAt: string): string {
    const elapsed = (Date.now() - new Date(startedAt).getTime()) / 1000;
    // Very rough: if we have no progress yet, fall back to 1 min per 1000 orders
    const estimatedTotal = Math.max(elapsed * 2, (totalOrders / 1000) * 60);
    const remaining = Math.max(0, estimatedTotal - elapsed);
    if (remaining < 60) return 'less than a minute';
    return `~${Math.ceil(remaining / 60)} min`;
}

function StepProgress({ storeSlug, workspaceSlug }: { storeSlug: string; workspaceSlug: string }) {
    const w = (path: string) => wurl(workspaceSlug, path);
    const [status, setStatus] = useState<ImportStatus>({
        status: null,
        progress: null,
        total_orders: null,
        started_at: null,
        completed_at: null,
        duration_seconds: null,
        error_message: null,
    });
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        function poll() {
            fetch(w(`/api/stores/${storeSlug}/import-status`), {
                headers: { Accept: 'application/json' },
            })
                .then((r) => r.json())
                .then((data: ImportStatus) => {
                    setStatus(data);

                    if (data.status === 'completed') {
                        if (intervalRef.current) clearInterval(intervalRef.current);
                        router.visit(w('/dashboard'));
                    } else if (data.status === 'failed') {
                        if (intervalRef.current) clearInterval(intervalRef.current);
                    }
                })
                .catch(() => {
                    // Network error — keep polling, will recover
                });
        }

        poll(); // immediate first tick
        intervalRef.current = setInterval(poll, 5000);

        return () => {
            if (intervalRef.current) clearInterval(intervalRef.current);
        };
    }, [storeSlug]);

    const progress = status.progress ?? 0;
    const isFailed = status.status === 'failed';

    return (
        <>
            <Head title="Importing data" />

            <h1 className="text-lg font-semibold text-zinc-900">
                {isFailed ? 'Import failed' : 'Importing your order history…'}
            </h1>

            {isFailed ? (
                <>
                    <p className="mt-2 text-sm text-red-600">
                        {status.error_message ?? 'An unexpected error occurred.'}
                    </p>
                    <div className="mt-6 flex flex-col gap-3">
                        <Button onClick={() => router.post(route('onboarding.import.reset'))} className="w-full">
                            Try again
                        </Button>
                        <button
                            onClick={() => router.post(route('onboarding.reset'))}
                            className="text-sm text-zinc-400 hover:text-zinc-600"
                        >
                            ← Start over
                        </button>
                    </div>
                </>
            ) : (
                <>
                    {/* Progress bar */}
                    <div className="mt-4">
                        <div className="flex items-center justify-between text-xs text-zinc-500">
                            <span>
                                {status.status === 'pending'
                                    ? 'Starting…'
                                    : progress >= 80
                                      ? 'Preparing dashboard…'
                                      : 'Importing orders'}
                            </span>
                            <span>{progress}%</span>
                        </div>
                        <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-zinc-100">
                            <div
                                className="h-full rounded-full bg-primary transition-all duration-500"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                    </div>

                    {/* Stats */}
                    <div className="mt-4 grid grid-cols-2 gap-4 text-sm">
                        {status.total_orders != null && (
                            <div>
                                <div className="text-zinc-400">Total orders</div>
                                <div className="font-medium text-zinc-900">
                                    {status.total_orders.toLocaleString()}
                                </div>
                            </div>
                        )}
                        {status.started_at && status.total_orders && progress < 100 && (
                            <div>
                                <div className="text-zinc-400">Time remaining</div>
                                <div className="font-medium text-zinc-900">
                                    {formatEstimate(status.total_orders, status.started_at)}
                                </div>
                            </div>
                        )}
                        {status.duration_seconds !== null && (
                            <div>
                                <div className="text-zinc-400">Duration</div>
                                <div className="font-medium text-zinc-900">
                                    {formatDuration(status.duration_seconds)}
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="mt-6 flex flex-col gap-3">
                        <p className="text-xs text-zinc-400">
                            The import runs in the background — you can close this tab or
                            go to the dashboard now. Your data will appear as it arrives.
                        </p>
                        <button
                            onClick={() => router.visit(w('/dashboard'))}
                            className="text-sm text-zinc-500 hover:text-zinc-700 underline underline-offset-2"
                        >
                            Continue to dashboard →
                        </button>
                    </div>
                </>
            )}
        </>
    );
}

// ---------------------------------------------------------------------------
// Root page component
// ---------------------------------------------------------------------------

export default function OnboardingIndex({
    step,
    has_ads,
    has_gsc,
    fb_pending,
    gads_pending,
    gsc_pending,
    oauth_error,
    oauth_platform: _oauth_platform,
    store_id,
    store_slug,
    workspace_slug,
    store_name,
    website_url,
    country,
    ip_detected_country,
    has_other_workspaces,
    is_workspace_owner,
    current_workspace_id,
}: Props) {
    return (
        <OnboardingLayout currentStep={step}>
            {step === 1 && (
                <StepConnect
                    hasAds={has_ads ?? false}
                    hasGsc={has_gsc ?? false}
                    fbPending={fb_pending}
                    gadsPending={gads_pending}
                    gscPending={gsc_pending}
                    oauthError={oauth_error}
                    hasOtherWorkspaces={has_other_workspaces ?? false}
                    isWorkspaceOwner={is_workspace_owner ?? false}
                    currentWorkspaceId={current_workspace_id}
                />
            )}
            {step === 2 && store_id && (
                <StepCountry
                    storeId={store_id}
                    storeName={store_name ?? ''}
                    websiteUrl={website_url ?? null}
                    initialCountry={country ?? null}
                    ipDetectedCountry={ip_detected_country ?? null}
                />
            )}
            {step === 3 && store_id && (
                <StepDateRange storeId={store_id} storeName={store_name ?? ''} />
            )}
            {step === 4 && store_slug && workspace_slug && (
                <StepProgress storeSlug={store_slug} workspaceSlug={workspace_slug} />
            )}
        </OnboardingLayout>
    );
}
