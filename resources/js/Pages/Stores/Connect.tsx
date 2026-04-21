import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';
import { useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface Props {
    step: 1 | 2 | 3;
    // step 2 (country prompt)
    store_id?: number;
    store_name?: string;
    website_url?: string | null;
    country?: string | null;
    ip_detected_country?: string | null;
    // step 3 (date range)
    // store_id + store_name shared with step 2
}

// ---------------------------------------------------------------------------
// Country data (mirrors Onboarding/Index.tsx)
// ---------------------------------------------------------------------------

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

function detectCountryFromUrl(url: string | null): string | null {
    if (!url) return null;
    try {
        const hostname = new URL(url.startsWith('http') ? url : `https://${url}`).hostname;
        const parts    = hostname.split('.');
        const tld      = parts[parts.length - 1].toLowerCase();
        if (parts.length >= 3 && parts[parts.length - 2].toLowerCase() === 'co') {
            if (CCTLD_TO_COUNTRY[tld]) return CCTLD_TO_COUNTRY[tld];
        }
        return CCTLD_TO_COUNTRY[tld] ?? null;
    } catch {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Step 1 — Store connection form
// ---------------------------------------------------------------------------

function StepConnect() {
    const { workspace } = usePage<PageProps>().props;
    const w = (path: string) => wurl(workspace?.slug, path);

    const [platform, setPlatform] = useState<'woocommerce' | 'shopify'>('woocommerce');

    const { data, setData, post, processing, errors } = useForm({
        domain: '',
        consumer_key: '',
        consumer_secret: '',
    });

    const [shop, setShop] = useState('');

    function submitWoo(e: React.FormEvent) {
        e.preventDefault();
        post(w('/stores/connect'));
    }

    return (
        <div className="max-w-lg">
            <p className="text-sm text-zinc-500">
                Connect a new store to this workspace. Ads and Search Console integrations are
                shared across all stores.
            </p>

            {/* Platform toggle */}
            <div className="mt-6 flex rounded-md border border-zinc-200 p-0.5 max-w-xs">
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
                    Shopify
                </button>
            </div>

            {platform === 'woocommerce' ? (
                <form onSubmit={submitWoo} className="mt-5 space-y-3">
                    <p className="text-xs text-zinc-500">
                        Go to <strong>WooCommerce → Settings → Advanced → REST API</strong> and
                        create a key with <strong>Read/Write</strong> permissions.
                    </p>

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
                        {errors.domain && (
                            <p className="mt-1 text-xs text-red-600">{errors.domain}</p>
                        )}
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
                            {errors.consumer_key && (
                                <p className="mt-1 text-xs text-red-600">{errors.consumer_key}</p>
                            )}
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
                            {errors.consumer_secret && (
                                <p className="mt-1 text-xs text-red-600">{errors.consumer_secret}</p>
                            )}
                        </div>
                    </div>

                    <Button type="submit" disabled={processing} className="w-full mt-2">
                        {processing ? 'Connecting…' : 'Connect store'}
                    </Button>
                </form>
            ) : (
                <div className="mt-5 space-y-3">
                    <p className="text-xs text-zinc-500">
                        Enter your myshopify.com domain. You will be redirected to Shopify to
                        approve the connection.
                    </p>
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
                        href={route('shopify.install') + '?shop=' + encodeURIComponent(shop) + '&from=connect'}
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
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Step 2 — Country prompt
// ---------------------------------------------------------------------------

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
    ipDetectedCountry: string | null;
}) {
    const { workspace } = usePage<PageProps>().props;
    const w = (path: string) => wurl(workspace?.slug, path);

    const detected = detectCountryFromUrl(websiteUrl);
    const [selected, setSelected] = useState<string>(
        initialCountry ?? detected ?? ipDetectedCountry ?? '',
    );
    const [processing, setProcessing] = useState(false);

    function handleSave(e: React.FormEvent) {
        e.preventDefault();
        setProcessing(true);
        router.post(
            w('/stores/connect/country'),
            { store_id: storeId, country_code: selected || null },
            { onFinish: () => setProcessing(false) },
        );
    }

    function handleSkip() {
        setProcessing(true);
        router.post(
            w('/stores/connect/country'),
            { store_id: storeId, country_code: null },
            { onFinish: () => setProcessing(false) },
        );
    }

    function handleReset() {
        router.post(w('/stores/connect/reset'));
    }

    return (
        <div className="max-w-lg">
            <p className="text-sm text-zinc-500">
                Where does <span className="font-medium text-zinc-700">{storeName}</span> mainly sell?
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

            <div className="mt-4 flex items-center justify-between">
                <button
                    type="button"
                    onClick={handleSkip}
                    disabled={processing}
                    className="text-sm text-zinc-400 hover:text-zinc-600"
                >
                    Skip for now
                </button>
                <button
                    type="button"
                    onClick={handleReset}
                    disabled={processing}
                    className="text-sm text-zinc-400 hover:text-zinc-600"
                >
                    ← Start over
                </button>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Step 3 — Import date range
// ---------------------------------------------------------------------------

const PERIODS = [
    { value: '30days', label: 'Last 30 days',  description: 'Quick start — good for recent trends' },
    { value: '90days', label: 'Last 90 days',  description: 'Three months of order history' },
    { value: '1year',  label: 'Last year',     description: 'Full year for seasonal comparisons' },
    { value: 'all',    label: 'All history',   description: 'Everything since your store opened' },
] as const;

function StepDateRange({
    storeId,
    storeName,
}: {
    storeId: number;
    storeName: string;
}) {
    const { workspace } = usePage<PageProps>().props;
    const w = (path: string) => wurl(workspace?.slug, path);

    const { data, setData, post, processing } = useForm<{ store_id: number; period: string }>({
        store_id: storeId,
        period: '90days',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(w('/stores/connect/import'));
    }

    return (
        <div className="max-w-lg">
            <p className="text-sm text-zinc-500">
                Connected to <span className="font-medium text-zinc-700">{storeName}</span>.
                Choose how much order history to import. You can always re-import more data
                later from Settings → Integrations.
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
                            <div className="text-sm font-medium text-zinc-900">{p.label}</div>
                            <div className="text-xs text-zinc-500">{p.description}</div>
                        </div>
                    </label>
                ))}

                <div className="pt-2">
                    <Button type="submit" disabled={processing} className="w-full">
                        {processing ? 'Starting…' : 'Start import'}
                    </Button>
                </div>
            </form>

            <div className="mt-4 text-right">
                <button
                    type="button"
                    onClick={() => router.post(w('/stores/connect/reset'))}
                    className="text-sm text-zinc-400 hover:text-zinc-600"
                >
                    ← Start over
                </button>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Step indicator (compact, 3 steps)
// ---------------------------------------------------------------------------

function StepIndicator({ current }: { current: 1 | 2 | 3 }) {
    const steps = ['Connect', 'Country', 'Import range'];
    return (
        <div className="mb-8 flex items-center gap-2">
            {steps.map((label, i) => {
                const n = i + 1;
                const done    = n < current;
                const active  = n === current;
                return (
                    <div key={label} className="flex items-center gap-2">
                        <div
                            className={[
                                'flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium',
                                done   ? 'bg-primary text-white'
                                       : active ? 'bg-primary text-white'
                                                : 'bg-zinc-100 text-zinc-400',
                            ].join(' ')}
                        >
                            {done ? (
                                <svg className="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                </svg>
                            ) : n}
                        </div>
                        <span
                            className={[
                                'text-xs',
                                active ? 'font-medium text-zinc-900' : 'text-zinc-400',
                            ].join(' ')}
                        >
                            {label}
                        </span>
                        {i < steps.length - 1 && (
                            <div className={['h-px w-8', done ? 'bg-primary' : 'bg-zinc-200'].join(' ')} />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Root page component
// ---------------------------------------------------------------------------

export default function StoresConnect({
    step,
    store_id,
    store_name,
    website_url,
    country,
    ip_detected_country,
}: Props) {
    return (
        <AppLayout>
            <Head title="Connect a store" />
            <PageHeader
                title="Connect a store"
                subtitle="Add a new store to this workspace"
            />

            <div className="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
                <StepIndicator current={step} />

                {step === 1 && <StepConnect />}

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
            </div>
        </AppLayout>
    );
}
