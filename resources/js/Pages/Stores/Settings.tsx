import { useEffect, useState } from 'react';
import { Head, useForm, usePage, router } from '@inertiajs/react';
import { wurl } from '@/lib/workspace-url';
import AppLayout from '@/Components/layouts/AppLayout';
import { StoreLayout } from '@/Components/layouts/StoreLayout';
import type { StoreData, StoreCostSettings } from '@/Components/layouts/StoreLayout';
import type { PageProps } from '@/types';
import { TimezoneSelect } from '@/Components/shared/TimezoneSelect';
import { StoreCountryPrompt } from '@/Components/shared/StoreCountryPrompt';

interface Props extends PageProps {
    store: StoreData;
}

// ── Cost settings form ────────────────────────────────────────────────────────

function CostSettingsForm({ store, saveUrl }: { store: StoreData; saveUrl: string }) {
    const cs = store.cost_settings;

    const { data, setData, patch, processing, errors, reset } = useForm<StoreCostSettings>({
        tax: {
            deduct_tax:        cs.tax.deduct_tax,
            default_tax_rate:  cs.tax.default_tax_rate,
            country_tax_rates: cs.tax.country_tax_rates,
            zero_tax_is_b2b:   cs.tax.zero_tax_is_b2b,
        },
        shipping: {
            cost_mode:  cs.shipping.cost_mode,
            flat_rate:  cs.shipping.flat_rate,
            percentage: cs.shipping.percentage,
        },
        fixed_monthly_costs: cs.fixed_monthly_costs,
        cogs: { custom_meta_keys: cs.cogs.custom_meta_keys },
    });

    useEffect(() => { reset(); }, [store.cost_settings]);

    // ── Country tax rate rows ─────────────────────────────────────────────────
    const [countryRateInput, setCountryRateInput] = useState({ code: '', rate: '' });

    function addCountryRate() {
        const code = countryRateInput.code.toUpperCase().trim();
        const rate = parseFloat(countryRateInput.rate);
        if (!code || isNaN(rate)) return;
        setData('tax', { ...data.tax, country_tax_rates: { ...data.tax.country_tax_rates, [code]: rate } });
        setCountryRateInput({ code: '', rate: '' });
    }

    function removeCountryRate(code: string) {
        const updated = { ...data.tax.country_tax_rates };
        delete updated[code];
        setData('tax', { ...data.tax, country_tax_rates: updated });
    }

    // ── Custom COGS meta key rows ─────────────────────────────────────────────
    const [cogsKeyInput, setCogsKeyInput] = useState({ key: '', value_type: 'unit' as 'unit' | 'total' });

    function addCogsKey() {
        const key = cogsKeyInput.key.trim();
        if (!key || !/^[a-zA-Z0-9_]+$/.test(key)) return;
        if (data.cogs.custom_meta_keys.some(k => k.key === key)) return;
        setData('cogs', { custom_meta_keys: [...data.cogs.custom_meta_keys, { key, value_type: cogsKeyInput.value_type }] });
        setCogsKeyInput({ key: '', value_type: 'unit' });
    }

    function removeCogsKey(key: string) {
        setData('cogs', { custom_meta_keys: data.cogs.custom_meta_keys.filter(k => k.key !== key) });
    }

    // ── Fixed cost rows ───────────────────────────────────────────────────────
    const [fixedInput, setFixedInput] = useState({ name: '', amount: '', currency: store.currency });

    function addFixedCost() {
        const amount = parseFloat(fixedInput.amount);
        if (!fixedInput.name.trim() || isNaN(amount)) return;
        setData('fixed_monthly_costs', [
            ...data.fixed_monthly_costs,
            { name: fixedInput.name.trim(), amount, currency: fixedInput.currency || store.currency },
        ]);
        setFixedInput({ name: '', amount: '', currency: store.currency });
    }

    function removeFixedCost(idx: number) {
        setData('fixed_monthly_costs', data.fixed_monthly_costs.filter((_, i) => i !== idx));
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        patch(saveUrl);
    }

    return (
        <form onSubmit={handleSubmit} className="space-y-6">

            {/* ── Tax ─────────────────────────────────────────────────────── */}
            <div>
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-400">Tax</h3>
                <div className="space-y-4">

                    <label className="flex items-center gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={data.tax.deduct_tax}
                            onChange={e => setData('tax', { ...data.tax, deduct_tax: e.target.checked })}
                            className="h-4 w-4 rounded border-zinc-300 text-primary focus:ring-primary"
                        />
                        <span className="text-sm text-zinc-700">
                            Prices include tax — deduct it from revenue when calculating profit
                        </span>
                    </label>

                    {data.tax.deduct_tax && (
                        <>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="mb-1.5 block text-xs font-medium text-zinc-500">
                                        Default tax rate (%)
                                    </label>
                                    <input
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        placeholder="e.g. 20"
                                        value={data.tax.default_tax_rate ?? ''}
                                        onChange={e => setData('tax', {
                                            ...data.tax,
                                            default_tax_rate: e.target.value !== '' ? parseFloat(e.target.value) : null,
                                        })}
                                        className="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                    <p className="mt-1 text-xs text-zinc-400">
                                        Applied to orders where no tax is recorded.
                                    </p>
                                </div>
                            </div>

                            <label className="flex items-center gap-3 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={data.tax.zero_tax_is_b2b}
                                    onChange={e => setData('tax', { ...data.tax, zero_tax_is_b2b: e.target.checked })}
                                    className="h-4 w-4 rounded border-zinc-300 text-primary focus:ring-primary"
                                />
                                <span className="text-sm text-zinc-700">
                                    Orders with no tax are B2B / tax-exempt — don't apply the fallback rate to them
                                </span>
                            </label>

                            {/* Per-country rates */}
                            <div>
                                <p className="mb-2 text-xs font-medium text-zinc-500">Per-country tax rates</p>
                                {Object.entries(data.tax.country_tax_rates).length > 0 && (
                                    <div className="mb-2 divide-y divide-zinc-100 rounded-lg border border-zinc-200">
                                        {Object.entries(data.tax.country_tax_rates).map(([code, rate]) => (
                                            <div key={code} className="flex items-center justify-between px-3 py-2 text-sm">
                                                <span className="font-medium text-zinc-700">{code}</span>
                                                <span className="text-zinc-500">{rate}%</span>
                                                <button
                                                    type="button"
                                                    onClick={() => removeCountryRate(code)}
                                                    className="text-xs text-red-500 hover:text-red-700"
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                <div className="flex gap-2">
                                    <input
                                        type="text"
                                        placeholder="Country (e.g. SI)"
                                        maxLength={2}
                                        value={countryRateInput.code}
                                        onChange={e => setCountryRateInput(s => ({ ...s, code: e.target.value }))}
                                        className="w-28 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                    <input
                                        type="number"
                                        placeholder="Rate %"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        value={countryRateInput.rate}
                                        onChange={e => setCountryRateInput(s => ({ ...s, rate: e.target.value }))}
                                        className="w-28 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                    <button
                                        type="button"
                                        onClick={addCountryRate}
                                        className="rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-600 hover:bg-zinc-50"
                                    >
                                        Add
                                    </button>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>

            {/* ── Shipping ─────────────────────────────────────────────────── */}
            <div>
                <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-400">Shipping cost</h3>
                <div className="space-y-4">
                    <div>
                        <label className="mb-1.5 block text-xs font-medium text-zinc-500">Cost mode</label>
                        <select
                            value={data.shipping.cost_mode}
                            onChange={e => setData('shipping', {
                                ...data.shipping,
                                cost_mode: e.target.value as 'order' | 'flat' | 'percentage',
                            })}
                            className="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                            <option value="order">Use shipping charged to customer (default)</option>
                            <option value="flat">Fixed rate per order</option>
                            <option value="percentage">Percentage of shipping charged</option>
                        </select>
                    </div>

                    {data.shipping.cost_mode === 'flat' && (
                        <div>
                            <label className="mb-1.5 block text-xs font-medium text-zinc-500">
                                Flat rate per order ({store.currency})
                            </label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="e.g. 3.50"
                                value={data.shipping.flat_rate ?? ''}
                                onChange={e => setData('shipping', {
                                    ...data.shipping,
                                    flat_rate: e.target.value !== '' ? parseFloat(e.target.value) : null,
                                })}
                                className="w-40 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>
                    )}

                    {data.shipping.cost_mode === 'percentage' && (
                        <div>
                            <label className="mb-1.5 block text-xs font-medium text-zinc-500">
                                Actual cost as % of charged shipping
                            </label>
                            <div className="flex items-center gap-2">
                                <input
                                    type="number"
                                    min="0"
                                    max="1000"
                                    step="1"
                                    placeholder="e.g. 90"
                                    value={data.shipping.percentage !== null ? Math.round((data.shipping.percentage ?? 1) * 100) : ''}
                                    onChange={e => setData('shipping', {
                                        ...data.shipping,
                                        percentage: e.target.value !== '' ? parseFloat(e.target.value) / 100 : null,
                                    })}
                                    className="w-24 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                />
                                <span className="text-sm text-zinc-500">%</span>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* ── Fixed monthly costs ───────────────────────────────────────── */}
            <div>
                <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-400">Fixed monthly costs</h3>
                <p className="mb-3 text-xs text-zinc-400">
                    Platform subscriptions, apps, warehouse fees, etc. Prorated over the selected date range.
                </p>

                {data.fixed_monthly_costs.length > 0 && (
                    <div className="mb-3 divide-y divide-zinc-100 rounded-lg border border-zinc-200">
                        {data.fixed_monthly_costs.map((fc, idx) => (
                            <div key={idx} className="flex items-center justify-between px-3 py-2 text-sm">
                                <span className="text-zinc-700">{fc.name}</span>
                                <span className="text-zinc-500">{fc.currency} {fc.amount.toFixed(2)}/mo</span>
                                <button
                                    type="button"
                                    onClick={() => removeFixedCost(idx)}
                                    className="text-xs text-red-500 hover:text-red-700"
                                >
                                    Remove
                                </button>
                            </div>
                        ))}
                    </div>
                )}

                <div className="flex gap-2">
                    <input
                        type="text"
                        placeholder="Name (e.g. Shopify plan)"
                        value={fixedInput.name}
                        onChange={e => setFixedInput(s => ({ ...s, name: e.target.value }))}
                        className="flex-1 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                    <input
                        type="number"
                        placeholder="Amount"
                        min="0"
                        step="0.01"
                        value={fixedInput.amount}
                        onChange={e => setFixedInput(s => ({ ...s, amount: e.target.value }))}
                        className="w-28 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                    <input
                        type="text"
                        placeholder="EUR"
                        maxLength={3}
                        value={fixedInput.currency}
                        onChange={e => setFixedInput(s => ({ ...s, currency: e.target.value.toUpperCase() }))}
                        className="w-16 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                    <button
                        type="button"
                        onClick={addFixedCost}
                        className="rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-600 hover:bg-zinc-50"
                    >
                        Add
                    </button>
                </div>
            </div>

            {/* ── WooCommerce COGS meta keys ───────────────────────────── */}
            {store.type === 'woocommerce' && (
                <div>
                    <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-400">WooCommerce COGS source</h3>
                    <p className="mb-3 text-xs text-zinc-400">
                        Nexstage reads cost from these order-item meta keys in priority order. Add a custom key if your plugin isn't listed.
                    </p>

                    {/* Built-in keys — read-only reference */}
                    <div className="mb-3 divide-y divide-zinc-100 rounded-lg border border-zinc-200 bg-zinc-50">
                        {[
                            { key: '_wc_cogs_total_cost', label: 'WooCommerce 10.3+ core',          type: 'total / qty' },
                            { key: '_wc_cog_cost',        label: 'SkyVerge Cost of Goods extension', type: 'unit' },
                            { key: '_alg_wc_cog_cost',    label: 'WPFactory Cost of Goods (free)',   type: 'unit' },
                            { key: '_wcj_purchase_price', label: 'Booster for WooCommerce',          type: 'unit' },
                        ].map(({ key, label, type }) => (
                            <div key={key} className="flex items-center justify-between px-3 py-2 text-xs">
                                <code className="text-zinc-600">{key}</code>
                                <span className="text-zinc-400">{label}</span>
                                <span className="rounded bg-zinc-200 px-1.5 py-0.5 text-zinc-500">{type}</span>
                            </div>
                        ))}
                    </div>

                    {/* Custom keys */}
                    {data.cogs.custom_meta_keys.length > 0 && (
                        <div className="mb-2 divide-y divide-zinc-100 rounded-lg border border-zinc-200">
                            {data.cogs.custom_meta_keys.map(({ key, value_type }) => (
                                <div key={key} className="flex items-center justify-between px-3 py-2 text-sm">
                                    <code className="text-zinc-700">{key}</code>
                                    <span className="text-xs text-zinc-400">{value_type === 'total' ? 'total ÷ qty' : 'unit'}</span>
                                    <button
                                        type="button"
                                        onClick={() => removeCogsKey(key)}
                                        className="text-xs text-red-500 hover:text-red-700"
                                    >
                                        Remove
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}

                    <div className="flex gap-2">
                        <input
                            type="text"
                            placeholder="e.g. _my_plugin_cost"
                            value={cogsKeyInput.key}
                            onChange={e => setCogsKeyInput(s => ({ ...s, key: e.target.value }))}
                            className="flex-1 rounded-lg border border-zinc-200 bg-white px-3 py-2 font-mono text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        />
                        <select
                            value={cogsKeyInput.value_type}
                            onChange={e => setCogsKeyInput(s => ({ ...s, value_type: e.target.value as 'unit' | 'total' }))}
                            className="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                            <option value="unit">Unit cost</option>
                            <option value="total">Total ÷ qty</option>
                        </select>
                        <button
                            type="button"
                            onClick={addCogsKey}
                            className="rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-600 hover:bg-zinc-50"
                        >
                            Add
                        </button>
                    </div>
                </div>
            )}

            <div className="flex justify-end">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
                >
                    {processing ? 'Saving…' : 'Save cost settings'}
                </button>
            </div>
        </form>
    );
}

// ── Main component ────────────────────────────────────────────────────────────

export default function StoreSettings({ store }: Props) {
    const { flash, workspace } = usePage<PageProps>().props;
    const w = (path: string) => wurl(workspace?.slug, path);

    // ── General settings form ──────────────────────────────────────────────────
    const { data, setData, patch, processing, errors, reset } = useForm({
        name:     store.name,
        slug:     store.slug,
        timezone: store.timezone,
    });

    useEffect(() => {
        reset();
        setData({ name: store.name, slug: store.slug, timezone: store.timezone });
    }, [store.name, store.slug, store.timezone]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        patch(w(`/stores/${store.slug}/settings`));
    }

    function handleRemove() {
        if (!confirm(`Remove "${store.name}"?\n\nThis will permanently delete all data for this store — orders, snapshots, and products. This cannot be undone.`)) return;
        router.delete(w(`/settings/integrations/stores/${store.slug}`));
    }

    return (
        <AppLayout>
            <Head title={`${store.name} — Settings`} />
            <StoreLayout store={store} activeTab="settings">
                <div className="max-w-xl space-y-8">

                    {/* Success flash */}
                    {flash?.success && (
                        <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                            {flash.success}
                        </div>
                    )}

                    {/* General settings form */}
                    <div className="rounded-xl border border-zinc-200 bg-white p-6">
                        <h2 className="mb-5 text-sm font-semibold text-zinc-900">General</h2>

                        <form onSubmit={handleSubmit} className="space-y-5">
                            {/* Name */}
                            <div>
                                <label className="mb-1.5 block text-xs font-medium text-zinc-500">
                                    Store name
                                </label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                />
                                {errors.name && (
                                    <p className="mt-1 text-xs text-red-600">{errors.name}</p>
                                )}
                            </div>

                            {/* Slug */}
                            <div>
                                <label className="mb-1.5 block text-xs font-medium text-zinc-500">
                                    URL identifier
                                </label>
                                <div className="flex items-center rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm">
                                    <span className="shrink-0 text-zinc-400">{w('/stores/')}</span>
                                    <input
                                        type="text"
                                        value={data.slug}
                                        onChange={(e) => setData('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'))}
                                        className="min-w-0 flex-1 bg-transparent text-zinc-900 focus:outline-none"
                                    />
                                </div>
                                {errors.slug ? (
                                    <p className="mt-1 text-xs text-red-600">{errors.slug}</p>
                                ) : (
                                    <p className="mt-1 text-xs text-zinc-400">
                                        Lowercase letters, numbers, and hyphens only. Changing this updates all store URLs.
                                    </p>
                                )}
                            </div>

                            {/* Timezone */}
                            <div>
                                <label className="mb-1.5 block text-xs font-medium text-zinc-500">
                                    Timezone
                                </label>
                                <TimezoneSelect
                                    value={data.timezone}
                                    onChange={(tz) => setData('timezone', tz)}
                                />
                                {errors.timezone ? (
                                    <p className="mt-1 text-xs text-red-600">{errors.timezone}</p>
                                ) : (
                                    <p className="mt-1 text-xs text-zinc-400">
                                        Used for displaying hourly data.
                                    </p>
                                )}
                            </div>

                            {/* Read-only info */}
                            <div className="grid grid-cols-2 gap-4 rounded-lg border border-zinc-100 bg-zinc-50 px-4 py-3">
                                <div>
                                    <p className="text-xs text-zinc-400">Platform</p>
                                    <p className="text-sm font-medium capitalize text-zinc-700">{store.type}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-zinc-400">Domain</p>
                                    <p className="text-sm font-medium text-zinc-700 truncate">{store.domain}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-zinc-400">Currency</p>
                                    <p className="text-sm font-medium text-zinc-700">{store.currency}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-zinc-400">Status</p>
                                    <p className="text-sm font-medium capitalize text-zinc-700">{store.status}</p>
                                </div>
                            </div>

                            <div className="flex justify-end">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
                                >
                                    {processing ? 'Saving…' : 'Save changes'}
                                </button>
                            </div>
                        </form>
                    </div>

                    {/* ── Primary country ───────────────────────────────────────────── */}
                    <div className="rounded-xl border border-zinc-200 bg-white p-6">
                        <h2 className="mb-1 text-sm font-semibold text-zinc-900">Primary country</h2>
                        <p className="mb-4 text-sm text-zinc-500">
                            Used as a fallback country for ad spend attribution when campaign names
                            don't include a country code. Multi-country stores can leave this blank.
                        </p>

                        {store.primary_country_code === null && (
                            <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                No primary country set. Ad spend without country tagging will show as
                                "Country unknown" on analytics pages.
                            </div>
                        )}

                        <StoreCountryPrompt
                            value={store.primary_country_code}
                            saveUrl={w(`/stores/${store.slug}/country`)}
                            storeName={store.name}
                            websiteUrl={store.website_url}
                            compact
                        />
                    </div>

                    {/* ── Cost settings ─────────────────────────────────────────────── */}
                    <div className="rounded-xl border border-zinc-200 bg-white p-6">
                        <h2 className="mb-1 text-sm font-semibold text-zinc-900">Profit cost settings</h2>
                        <p className="mb-5 text-sm text-zinc-500">
                            Controls how tax, shipping costs, and fixed overheads are deducted
                            when calculating contribution margin and real profit.
                        </p>
                        <CostSettingsForm
                            store={store}
                            saveUrl={w(`/stores/${store.slug}/cost-settings`)}
                        />
                    </div>

                    {/* Danger zone */}
                    <div className="rounded-xl border border-red-100 bg-white p-6">
                        <h2 className="mb-1 text-sm font-semibold text-zinc-900">Danger zone</h2>
                        <p className="mb-4 text-sm text-zinc-500">
                            Permanently removes this store and all its data — orders, snapshots, and products.
                            This cannot be undone.
                        </p>
                        <button
                            type="button"
                            onClick={handleRemove}
                            className="rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
                        >
                            Remove store
                        </button>
                    </div>
                </div>
            </StoreLayout>
        </AppLayout>
    );
}
