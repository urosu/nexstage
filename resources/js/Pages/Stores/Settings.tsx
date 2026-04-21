import { useEffect } from 'react';
import { Head, useForm, usePage, router } from '@inertiajs/react';
import { wurl } from '@/lib/workspace-url';
import AppLayout from '@/Components/layouts/AppLayout';
import { StoreLayout } from '@/Components/layouts/StoreLayout';
import type { StoreData } from '@/Components/layouts/StoreLayout';
import type { PageProps } from '@/types';
import { TimezoneSelect } from '@/Components/shared/TimezoneSelect';
import { StoreCountryPrompt } from '@/Components/shared/StoreCountryPrompt';

interface Props extends PageProps {
    store: StoreData;
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

                        {/* Persistent notice when NULL — shown prominently so it's hard to miss */}
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
