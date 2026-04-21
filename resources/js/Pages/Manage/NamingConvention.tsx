/**
 * Naming Convention — read-only explainer + parse status table.
 *
 * Companion to CampaignNameParserService. Shows:
 *   1. The three supported shapes with examples
 *   2. Coverage badge: % of campaigns with 30-day spend that parse cleanly
 *   3. Parse status table grouped as Clean / Partial / Minimal
 *   4. Link to Tag Generator for building correctly-named future campaigns
 *
 * The page is read-only by design — users fix campaign names inside
 * Facebook / Google Ads and parsed_convention updates on the next sync.
 *
 * @see PLANNING.md section 16.5
 */

import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowRight, ChevronDown, ChevronRight, Tag } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface CampaignRow {
    id: number;
    name: string;
    platform: string;
    spend_30d: number;
    country: string | null;
    campaign: string | null;
    raw_target: string | null;
    target_type: string | null;
    target_slug: string | null;
    shape: string | null;
}

interface Props {
    buckets: {
        clean:   CampaignRow[];
        partial: CampaignRow[];
        minimal: CampaignRow[];
    };
    coverage: {
        percent:     number | null;
        numerator:   number;
        denominator: number;
    };
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function coverageTone(pct: number | null): 'red' | 'amber' | 'green' | 'neutral' {
    if (pct == null) return 'neutral';
    if (pct >= 80) return 'green';
    if (pct >= 50) return 'amber';
    return 'red';
}

function formatCurrency(amount: number): string {
    // Simple fixed formatter — the spend is in reporting currency; the symbol is
    // intentionally omitted because we don't ship currency context into this page.
    return amount.toLocaleString(undefined, { maximumFractionDigits: 0 });
}

function PlatformBadge({ platform }: { platform: string }) {
    const label = platform === 'facebook' ? 'Meta'
        : platform === 'google' ? 'Google'
        : platform;
    return (
        <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-zinc-600">
            {label}
        </span>
    );
}

// ─── Coverage badge card ──────────────────────────────────────────────────────

function CoverageBadge({ coverage }: { coverage: Props['coverage'] }) {
    const tone = coverageTone(coverage.percent);

    const toneClasses = {
        green:   'border-emerald-200 bg-emerald-50 text-emerald-800',
        amber:   'border-amber-200 bg-amber-50 text-amber-800',
        red:     'border-rose-200 bg-rose-50 text-rose-800',
        neutral: 'border-zinc-200 bg-zinc-50 text-zinc-600',
    }[tone];

    const label = {
        green:   'Healthy',
        amber:   'Needs attention',
        red:     'Poor',
        neutral: 'No ad spend yet',
    }[tone];

    return (
        <div className={cn('rounded-xl border p-5', toneClasses)}>
            <div className="flex items-baseline justify-between gap-3">
                <div>
                    <div className="text-xs font-medium uppercase tracking-wide opacity-70">
                        30-day naming coverage
                    </div>
                    <div className="mt-1 flex items-baseline gap-2">
                        <span className="text-3xl font-semibold">
                            {coverage.percent != null ? `${coverage.percent}%` : 'N/A'}
                        </span>
                        <span className="text-sm opacity-80">{label}</span>
                    </div>
                </div>
                <div className="text-right text-xs opacity-80">
                    {coverage.denominator > 0 ? (
                        <>
                            <div>{coverage.numerator} of {coverage.denominator} campaigns</div>
                            <div className="mt-0.5">parsed cleanly</div>
                        </>
                    ) : (
                        <div>Connect an ad account and wait for spend</div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── Template explainer card ──────────────────────────────────────────────────

function TemplateExplainer() {
    const shapes = [
        {
            label: 'Full',
            example: 'US | summer-sale | hoodie-blue',
            note: 'country · campaign · target',
        },
        {
            label: 'No country',
            example: 'summer-sale | hoodie-blue',
            note: 'campaign · target',
        },
        {
            label: 'Minimal',
            example: 'summer-sale',
            note: 'campaign only',
        },
    ];

    return (
        <div className="rounded-xl border border-zinc-200 bg-white p-5">
            <h2 className="mb-3 text-sm font-semibold text-zinc-900">Convention format</h2>
            <div className="mb-4 space-y-1.5 text-xs text-zinc-500">
                <p>
                    Fields are separated by <code className="rounded bg-zinc-100 px-1 py-0.5 font-mono">|</code>.
                    Country is detected when the first field is exactly 2 uppercase letters (e.g. <code className="rounded bg-zinc-100 px-1 py-0.5 font-mono">US</code>, <code className="rounded bg-zinc-100 px-1 py-0.5 font-mono">DE</code>).
                </p>
                <p>
                    The target field is matched against your <strong>product slugs</strong> then <strong>category slugs</strong>.
                    No match still parses — it just falls back to UTM-only attribution.
                </p>
            </div>
            <div className="space-y-2">
                {shapes.map((s) => (
                    <div key={s.label} className="flex items-center gap-3 rounded-md bg-zinc-50 px-3 py-2">
                        <span className="w-20 shrink-0 text-[10px] font-semibold uppercase tracking-wide text-zinc-400">
                            {s.label}
                        </span>
                        <code className="flex-1 font-mono text-xs text-zinc-800">{s.example}</code>
                        <span className="text-[10px] text-zinc-400">{s.note}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

// ─── Bucket section ───────────────────────────────────────────────────────────

function BucketSection({
    title,
    description,
    tone,
    rows,
    renderRow,
    defaultCollapsed = false,
}: {
    title:    string;
    description: string;
    tone:     'green' | 'amber' | 'zinc';
    rows:     CampaignRow[];
    renderRow: (row: CampaignRow) => React.ReactNode;
    defaultCollapsed?: boolean;
}) {
    const [expanded, setExpanded] = useState(!defaultCollapsed);

    const pillClasses = {
        green: 'bg-emerald-100 text-emerald-800',
        amber: 'bg-amber-100 text-amber-800',
        zinc:  'bg-zinc-100 text-zinc-700',
    }[tone];

    return (
        <div className="rounded-xl border border-zinc-200 bg-white p-5">
            <button
                type="button"
                onClick={() => setExpanded((v) => !v)}
                className="flex w-full items-start justify-between gap-3 text-left"
            >
                <div>
                    <div className="flex items-center gap-2">
                        <h2 className="text-sm font-semibold text-zinc-900">{title}</h2>
                        <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-semibold', pillClasses)}>
                            {rows.length}
                        </span>
                    </div>
                    <p className="mt-0.5 text-xs text-zinc-500">{description}</p>
                </div>
                {expanded
                    ? <ChevronDown className="h-4 w-4 shrink-0 text-zinc-400" />
                    : <ChevronRight className="h-4 w-4 shrink-0 text-zinc-400" />}
            </button>

            {expanded && (
                <div className="mt-4 space-y-1.5">
                    {rows.length === 0
                        ? <p className="text-xs text-zinc-400">No campaigns in this bucket.</p>
                        : rows.map(renderRow)}
                </div>
            )}
        </div>
    );
}

// ─── Row renderers ────────────────────────────────────────────────────────────

function CleanRow(row: CampaignRow) {
    return (
        <div key={row.id} className="flex items-center gap-3 rounded-md bg-zinc-50 px-3 py-2">
            <PlatformBadge platform={row.platform} />
            <span className="flex-1 truncate font-mono text-xs text-zinc-800">{row.name}</span>
            {row.target_type && (
                <span className="text-[10px] text-zinc-400">
                    → {row.target_type}: <code className="font-mono">{row.target_slug}</code>
                </span>
            )}
            <span className="text-[10px] tabular-nums text-zinc-400">{formatCurrency(row.spend_30d)}</span>
        </div>
    );
}

function PartialRow(row: CampaignRow) {
    return (
        <div key={row.id} className="rounded-md border border-amber-100 bg-amber-50/40 px-3 py-2">
            <div className="flex items-center gap-3">
                <PlatformBadge platform={row.platform} />
                <span className="flex-1 truncate font-mono text-xs text-zinc-800">{row.name}</span>
                <span className="text-[10px] tabular-nums text-zinc-500">{formatCurrency(row.spend_30d)}</span>
            </div>
            <div className="mt-1 text-[11px] text-amber-900/80">
                Target <code className="rounded bg-white px-1 py-0.5 font-mono">{row.raw_target ?? '—'}</code>
                {' '}didn't match a product or category slug. Rename the target to an existing slug, or leave it and rely on UTM-only attribution.
            </div>
        </div>
    );
}

function MinimalRow(row: CampaignRow) {
    return (
        <div key={row.id} className="flex items-center gap-3 rounded-md bg-zinc-50 px-3 py-2">
            <PlatformBadge platform={row.platform} />
            <span className="flex-1 truncate font-mono text-xs text-zinc-800">{row.name}</span>
            <span className="text-[10px] tabular-nums text-zinc-400">{formatCurrency(row.spend_30d)}</span>
        </div>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function NamingConvention({ buckets, coverage }: Props) {
    const page = usePage<PageProps>();
    const workspaceSlug = page.props.workspace?.slug;

    return (
        <AppLayout>
            <Head title="Naming Convention" />
            <PageHeader
                title="Naming Convention"
                subtitle="How Nexstage parses your campaign names into country, campaign, and target."
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <CoverageBadge coverage={coverage} />
                <TemplateExplainer />
            </div>

            <div className="mt-6 space-y-4">
                <BucketSection
                    title="Clean parses"
                    description="Campaigns whose target matches a product or category slug. These get full attribution."
                    tone="green"
                    rows={buckets.clean}
                    renderRow={CleanRow}
                    defaultCollapsed={buckets.clean.length > 5}
                />

                <BucketSection
                    title="Partial parses"
                    description="Parsed, but the target didn't match any product or category. Falls back to UTM-only attribution."
                    tone="amber"
                    rows={buckets.partial}
                    renderRow={PartialRow}
                />

                <BucketSection
                    title="Minimal / unparsed"
                    description="Single-field campaign names — no target. Reliable only when your UTMs are clean."
                    tone="zinc"
                    rows={buckets.minimal}
                    renderRow={MinimalRow}
                />
            </div>

            {/* Tag Generator CTA */}
            <div className="mt-6 rounded-xl border border-zinc-100 bg-zinc-50 p-4">
                <div className="flex items-center gap-3">
                    <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-white">
                        <Tag className="h-4 w-4 text-zinc-500" />
                    </div>
                    <div className="flex-1 text-xs text-zinc-600">
                        Building a new campaign? Use the Tag Generator to produce a UTM-tagged destination URL that matches this convention.
                    </div>
                    <Link
                        href={wurl(workspaceSlug, '/manage/tag-generator')}
                        className="inline-flex items-center gap-1 rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 hover:border-zinc-300 hover:bg-zinc-50"
                    >
                        Open Tag Generator <ArrowRight className="h-3 w-3" />
                    </Link>
                </div>
            </div>
        </AppLayout>
    );
}
