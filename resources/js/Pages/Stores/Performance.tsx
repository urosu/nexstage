import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, RefreshCw } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { wurl } from '@/lib/workspace-url';
import AppLayout from '@/Components/layouts/AppLayout';
import { StoreLayout } from '@/Components/layouts/StoreLayout';
import type { StoreData } from '@/Components/layouts/StoreLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface StoreUrlData {
    id: number;
    url: string;
    label: string | null;
    is_homepage: boolean;
    is_active: boolean;
    // Latest mobile scores (null until first check completes)
    performance_score: number | null;
    lcp_ms: number | null;
    checked_at: string | null;
}

interface Props extends PageProps {
    store: StoreData;
    store_urls: StoreUrlData[];
    gsc_suggestions: string[];
    narrative: string | null;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

type ScoreGrade = 'good' | 'needs-improvement' | 'poor' | 'none';

function scoreGrade(score: number | null): ScoreGrade {
    if (score === null) return 'none';
    if (score >= 90)   return 'good';
    if (score >= 50)   return 'needs-improvement';
    return 'poor';
}

function scoreColor(grade: ScoreGrade): string {
    switch (grade) {
        case 'good':              return 'text-green-600';
        case 'needs-improvement': return 'text-amber-600';
        case 'poor':              return 'text-red-600';
        default:                  return 'text-zinc-400';
    }
}

function fmtMs(ms: number | null): string {
    if (ms === null) return '—';
    if (ms >= 1000) return `${(ms / 1000).toFixed(2)} s`;
    return `${ms} ms`;
}

function fmtDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en', { month: 'short', day: 'numeric' });
}

// ─── URL row ──────────────────────────────────────────────────────────────────

function UrlRow({
    storeUrl,
    storeSlug,
    workspaceSlug,
}: {
    storeUrl: StoreUrlData;
    storeSlug: string;
    workspaceSlug: string | undefined;
}) {
    const [label, setLabel] = useState(storeUrl.label ?? '');
    const grade = scoreGrade(storeUrl.performance_score);
    const w = (path: string) => wurl(workspaceSlug, path);

    function handleLabelBlur() {
        const trimmed = label.trim();
        if (trimmed === (storeUrl.label ?? '')) return;
        router.patch(w(`/stores/${storeSlug}/urls/${storeUrl.id}`), {
            label:     trimmed || null,
            is_active: storeUrl.is_active,
        }, { preserveScroll: true });
    }

    function handleToggleActive() {
        router.patch(w(`/stores/${storeSlug}/urls/${storeUrl.id}`), {
            label:     storeUrl.label,
            is_active: !storeUrl.is_active,
        }, { preserveScroll: true });
    }

    function handleRemove() {
        if (!confirm(`Remove "${storeUrl.url}" from monitored URLs?`)) return;
        router.delete(w(`/stores/${storeSlug}/urls/${storeUrl.id}`), { preserveScroll: true });
    }

    function handleCheckNow() {
        router.post(w(`/stores/${storeSlug}/urls/${storeUrl.id}/check`), {}, { preserveScroll: true });
    }

    return (
        <div className="flex items-start gap-3 border-t border-zinc-100 px-5 py-3.5">
            {/* Active toggle */}
            <button
                type="button"
                onClick={handleToggleActive}
                className={`relative mt-0.5 h-5 w-9 shrink-0 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-primary/30 ${
                    storeUrl.is_active ? 'bg-primary' : 'bg-zinc-300'
                }`}
                title={storeUrl.is_active ? 'Disable monitoring' : 'Enable monitoring'}
            >
                <span
                    className={`absolute top-0.5 left-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform ${
                        storeUrl.is_active ? 'translate-x-4' : 'translate-x-0'
                    }`}
                />
            </button>

            {/* URL + label */}
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm text-zinc-700 break-all">{storeUrl.url}</span>
                    {storeUrl.is_homepage && (
                        <span className="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-500">
                            Homepage
                        </span>
                    )}
                    {!storeUrl.is_active && (
                        <span className="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-400">
                            Paused
                        </span>
                    )}
                </div>
                <input
                    type="text"
                    value={label}
                    placeholder="Add label…"
                    onChange={(e) => setLabel(e.target.value)}
                    onBlur={handleLabelBlur}
                    className="mt-0.5 w-full bg-transparent text-xs text-zinc-400 placeholder-zinc-300 focus:outline-none focus:text-zinc-600"
                />
            </div>

            {/* Latest scores */}
            <div className="shrink-0 text-right">
                {storeUrl.performance_score !== null ? (
                    <>
                        <div className={`text-sm font-semibold tabular-nums ${scoreColor(grade)}`}>
                            {storeUrl.performance_score}
                            <span className="ml-0.5 text-xs font-normal text-zinc-400">/ 100</span>
                        </div>
                        <div className="text-xs text-zinc-400">
                            LCP {fmtMs(storeUrl.lcp_ms)}
                        </div>
                        {storeUrl.checked_at && (
                            <div className="text-xs text-zinc-300">{fmtDate(storeUrl.checked_at)}</div>
                        )}
                    </>
                ) : storeUrl.is_active ? (
                    <span className="text-xs text-zinc-400">Pending…</span>
                ) : (
                    <span className="text-xs text-zinc-300">—</span>
                )}
            </div>

            {/* Actions */}
            <div className="shrink-0 flex items-center gap-1 mt-0.5">
                {storeUrl.is_active && (
                    <button
                        type="button"
                        onClick={handleCheckNow}
                        className="flex h-7 w-7 items-center justify-center rounded text-zinc-300 hover:bg-zinc-100 hover:text-zinc-500 transition-colors"
                        title="Check now"
                    >
                        <RefreshCw className="h-3.5 w-3.5" />
                    </button>
                )}
                <a
                    href={storeUrl.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex h-7 w-7 items-center justify-center rounded text-zinc-300 hover:bg-zinc-100 hover:text-zinc-500 transition-colors"
                    title="Open URL"
                >
                    <ExternalLink className="h-3.5 w-3.5" />
                </a>
                {storeUrl.is_homepage ? (
                    <div className="h-7 w-7" />
                ) : (
                    <button
                        type="button"
                        onClick={handleRemove}
                        className="flex h-7 w-7 items-center justify-center rounded text-zinc-300 hover:bg-red-50 hover:text-red-500 transition-colors"
                        title="Remove URL"
                    >
                        <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                    </button>
                )}
            </div>
        </div>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function StorePerformance({ store, store_urls, gsc_suggestions, narrative }: Props) {
    const { flash, workspace } = usePage<PageProps>().props;
    const w = (path: string) => wurl(workspace?.slug, path);
    const addForm = useForm({ url: '', label: '' });

    function handleAddUrl(e: React.FormEvent) {
        e.preventDefault();
        addForm.post(w(`/stores/${store.slug}/urls`), {
            preserveScroll: true,
            onSuccess: () => addForm.reset(),
        });
    }

    function handleSuggestionClick(page: string) {
        addForm.setData('url', page);
    }

    const urlCount = store_urls.length;

    return (
        <AppLayout>
            <Head title={`${store.name} — Performance`} />
            <StoreLayout store={store} activeTab="performance">
                <PageHeader title="Performance" subtitle="Lighthouse scores for monitored URLs" narrative={narrative} />
                <div className="max-w-2xl space-y-6">

                    {flash?.success && (
                        <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                            {flash.success}
                        </div>
                    )}

                    {/* Monitored URLs */}
                    <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white">
                        <div className="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                            <div>
                                <h2 className="text-sm font-semibold text-zinc-900">Monitored URLs</h2>
                                <p className="mt-0.5 text-xs text-zinc-500">
                                    PageSpeed Insights checks run daily. Labels are auto-detected from page titles.
                                </p>
                            </div>
                            <div className="flex items-center gap-3">
                                <span className="text-xs text-zinc-400">{urlCount} / 10</span>
                                <Link
                                    href={w('/performance')}
                                    className="text-xs font-medium text-primary hover:text-primary/80"
                                >
                                    Full report →
                                </Link>
                            </div>
                        </div>

                        {/* Column header */}
                        {urlCount > 0 && (
                            <div className="flex items-center gap-3 px-5 py-2 bg-zinc-50 border-b border-zinc-100 text-xs text-zinc-400">
                                <span className="w-9 shrink-0" />
                                <span className="flex-1">URL / Label</span>
                                <span className="shrink-0 text-right w-20">Perf · LCP</span>
                                <span className="w-16 shrink-0" />
                            </div>
                        )}

                        {urlCount > 0 ? (
                            <div>
                                {store_urls.map((su) => (
                                    <UrlRow key={su.id} storeUrl={su} storeSlug={store.slug} workspaceSlug={workspace?.slug} />
                                ))}
                            </div>
                        ) : (
                            <div className="px-5 py-8 text-center text-sm text-zinc-400">
                                No URLs monitored yet. The homepage is added automatically when the store connects.
                            </div>
                        )}

                        {/* GSC suggestions */}
                        {gsc_suggestions.length > 0 && urlCount < 10 && (
                            <div className="border-t border-zinc-100 px-5 py-4">
                                <p className="mb-2 text-xs font-medium text-zinc-500">Suggested from GSC top pages</p>
                                <div className="flex flex-wrap gap-1.5">
                                    {gsc_suggestions.map((page) => (
                                        <button
                                            key={page}
                                            type="button"
                                            onClick={() => handleSuggestionClick(page)}
                                            className="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs text-zinc-600 hover:border-primary hover:text-primary transition-colors whitespace-nowrap"
                                        >
                                            {page}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Add URL form */}
                        {urlCount < 10 ? (
                            <div className="border-t border-zinc-100 px-5 py-4">
                                <form onSubmit={handleAddUrl} className="flex gap-2">
                                    <input
                                        type="url"
                                        value={addForm.data.url}
                                        onChange={(e) => addForm.setData('url', e.target.value)}
                                        placeholder="https://example.com/page"
                                        className="flex-1 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                    <input
                                        type="text"
                                        value={addForm.data.label}
                                        onChange={(e) => addForm.setData('label', e.target.value)}
                                        placeholder="Label (optional)"
                                        className="w-36 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                    <button
                                        type="submit"
                                        disabled={addForm.processing || !addForm.data.url}
                                        className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
                                    >
                                        Add
                                    </button>
                                </form>
                                {addForm.errors.url && (
                                    <p className="mt-1.5 text-xs text-red-600">{addForm.errors.url}</p>
                                )}
                            </div>
                        ) : (
                            <div className="border-t border-zinc-100 px-5 py-3">
                                <p className="text-xs text-zinc-400">Maximum of 10 URLs reached. Remove a URL to add a new one.</p>
                            </div>
                        )}
                    </div>

                </div>
            </StoreLayout>
        </AppLayout>
    );
}
