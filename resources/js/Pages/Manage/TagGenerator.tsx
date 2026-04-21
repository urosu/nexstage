import { useState, useMemo, useEffect, useRef } from 'react';
import { Head } from '@inertiajs/react';
import { Copy, Check, ExternalLink, Lock, Unlock, ChevronDown, ChevronRight, X } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { InfoTooltip } from '@/Components/shared/Tooltip';
import { cn } from '@/lib/utils';

// ─── Types ────────────────────────────────────────────────────────────────────

interface Campaign {
    id: number;
    name: string;
    platform: 'facebook' | 'google';
}

interface Props {
    campaigns: Campaign[];
}

interface UtmFields {
    source: string;
    medium: string;
    campaign: string;
    content: string;
    term: string;
}

interface ConventionFields {
    country: string;
    campaign: string;
    target: string;
    audience: string;
    variant: string;
}

// ─── Templates ────────────────────────────────────────────────────────────────

/**
 * Pre-built templates that seed BOTH the UTM fields (URL builder) and the
 * naming-convention fields (campaign/adset/ad name generator) from one click.
 *
 * Facebook dynamic placeholders:  {{campaign.name}}, {{adset.name}}, {{ad.name}}
 * Google Ads dynamic placeholders: {campaignname}, {adgroupname}, {keyword}
 *
 * @see PLANNING.md sections 16.3 + 16.6
 */
interface Template {
    label: string;
    platform: 'facebook' | 'google';
    utm: Partial<UtmFields>;
    convention: Partial<ConventionFields>;
}

const TEMPLATES: Record<string, Template> = {
    facebook_conversion: {
        label: 'Conversion',
        platform: 'facebook',
        utm: { source: 'facebook', medium: 'cpc', content: '{{ad.name}}', term: '' },
        convention: { country: 'US', campaign: 'conv', target: 'product-slug', audience: 'lookalike-1pc', variant: 'v1' },
    },
    facebook_retargeting: {
        label: 'Retargeting',
        platform: 'facebook',
        utm: { source: 'facebook', medium: 'cpc', content: '{{ad.name}}', term: '' },
        convention: { country: '', campaign: 'retarget', target: '', audience: 'site-30d', variant: 'v1' },
    },
    google_shopping: {
        label: 'Shopping',
        platform: 'google',
        utm: { source: 'google', medium: 'cpc', content: '', term: '{keyword}' },
        convention: { country: '', campaign: 'shopping', target: 'category-slug', audience: '', variant: '' },
    },
    google_brand_search: {
        label: 'Brand search',
        platform: 'google',
        utm: { source: 'google', medium: 'cpc', content: '{adgroupname}', term: '{keyword}' },
        convention: { country: '', campaign: 'brand', target: 'brand-term', audience: '', variant: '' },
    },
    google_pmax: {
        label: 'Performance Max',
        platform: 'google',
        utm: { source: 'google', medium: 'cpc', content: '', term: '' },
        convention: { country: '', campaign: 'pmax', target: 'category-slug', audience: '', variant: '' },
    },
};

const FACEBOOK_TEMPLATES = Object.entries(TEMPLATES).filter(([, t]) => t.platform === 'facebook');
const GOOGLE_TEMPLATES   = Object.entries(TEMPLATES).filter(([, t]) => t.platform === 'google');

// ─── Helpers ──────────────────────────────────────────────────────────────────

function buildTaggedUrl(baseUrl: string, utm: UtmFields): string {
    if (!baseUrl) return '';
    let url: URL;
    try {
        const normalized = baseUrl.startsWith('http') ? baseUrl : 'https://' + baseUrl;
        url = new URL(normalized);
    } catch {
        return baseUrl;
    }
    const params: [string, string][] = [
        ['utm_source',   utm.source],
        ['utm_medium',   utm.medium],
        ['utm_campaign', utm.campaign],
        ['utm_content',  utm.content],
        ['utm_term',     utm.term],
    ];
    for (const [key, value] of params) {
        if (value) url.searchParams.set(key, value);
    }
    return url.toString().replace(/%7B%7B/g, '{{').replace(/%7D%7D/g, '}}');
}

/**
 * Builds the three Nexstage-convention name strings from convention fields.
 * Parser splits on `|`; country code must be exactly 2 uppercase letters.
 *
 * @see PLANNING.md section 16.3
 * @see app/Services/CampaignNameParserService.php
 */
function buildConventionNames(c: ConventionFields): { campaign: string; adset: string; ad: string } {
    if (!c.campaign.trim()) return { campaign: '', adset: '', ad: '' };

    const parts = [c.country, c.campaign, c.target]
        .map((s) => s.trim())
        .filter(Boolean);

    const campaign = parts.join(' | ');
    const adset    = c.audience.trim() ? `${campaign} | ${c.audience.trim()}` : campaign;
    const ad       = c.variant.trim()  ? `${adset} | ${c.variant.trim()}`     : adset;

    return { campaign, adset, ad };
}

// ─── Subcomponents ────────────────────────────────────────────────────────────

function CopyButton({ text, className }: { text: string; className?: string }) {
    const [copied, setCopied] = useState(false);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => () => { if (timerRef.current) clearTimeout(timerRef.current); }, []);

    function flash(): void {
        setCopied(true);
        if (timerRef.current) clearTimeout(timerRef.current);
        timerRef.current = setTimeout(() => setCopied(false), 2000);
    }

    function handleCopy(): void {
        if (!text) return;
        navigator.clipboard.writeText(text).then(() => {
            flash();
        }).catch(() => {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            flash();
        });
    }

    return (
        <button
            type="button"
            onClick={handleCopy}
            disabled={!text}
            className={cn(
                'flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-xs font-medium transition-colors',
                copied
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                    : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300 hover:bg-zinc-50',
                !text && 'cursor-not-allowed opacity-40',
                className,
            )}
        >
            {copied ? <><Check className="h-3.5 w-3.5" /> Copied!</> : <><Copy className="h-3.5 w-3.5" /> Copy</>}
        </button>
    );
}

function Field({
    label, value, onChange, placeholder, hint, readOnly, tooltip,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    placeholder?: string;
    hint?: string;
    readOnly?: boolean;
    tooltip?: string;
}) {
    return (
        <div>
            <label className="mb-1 flex items-center gap-1.5 text-xs font-medium text-zinc-600">
                {label}
                {tooltip && <InfoTooltip content={tooltip} />}
            </label>
            <input
                type="text"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                readOnly={readOnly}
                className={cn(
                    'w-full rounded-md border border-zinc-200 px-3 py-1.5 text-sm text-zinc-900 outline-none placeholder:text-zinc-300 focus:border-primary/50 focus:ring-1 focus:ring-primary/20',
                    readOnly ? 'bg-zinc-50 cursor-not-allowed' : 'bg-white',
                )}
            />
            {hint && <p className="mt-0.5 text-[10px] text-zinc-400">{hint}</p>}
        </div>
    );
}

function NameRow({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="mb-1 flex items-center justify-between">
                <label className="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{label}</label>
                <CopyButton text={value} />
            </div>
            <div className="rounded-md border border-zinc-100 bg-zinc-50 px-3 py-2">
                {value
                    ? <p className="break-all font-mono text-xs text-zinc-700">{value}</p>
                    : <p className="text-xs text-zinc-300">—</p>
                }
            </div>
        </div>
    );
}

// ─── Examples ─────────────────────────────────────────────────────────────────

// Recognised values must stay in sync with ChannelMappingsSeeder.php.
const UTM_FIELDS = [
    {
        param: 'utm_source',
        what: 'Where your ad is running. We use this to identify the platform and attribute spend to the right channel.',
        examples: ['facebook', 'instagram', 'ig', 'meta', 'fb', 'tiktok', 'pinterest', 'google', 'bing'],
    },
    {
        param: 'utm_medium',
        what: 'The type of marketing. Paid ads should use cpc or ppc — this is how we know to count the click as paid traffic.',
        examples: ['cpc', 'ppc', 'paid', 'paidsocial', 'email', 'sms', 'organic', 'social', 'affiliate'],
    },
    {
        param: 'utm_campaign',
        what: 'Which campaign this belongs to. We use this to match ad spend to your orders.',
        examples: ['conv', 'retarget', 'brand', 'shopping', 'pmax'],
    },
    {
        param: 'utm_content',
        what: 'Which specific ad or creative. Optional, but lets you compare ads against each other.',
        examples: ['{{ad.name}}', '{adgroupname}', 'video-v1', 'carousel-v2'],
    },
    {
        param: 'utm_term',
        what: 'The search keyword that triggered the ad. Google Search only — leave empty for Facebook.',
        examples: ['{keyword}'],
    },
];

const EXAMPLES = [
    {
        title: 'Facebook ad for a specific product',
        scenario: "You're promoting your Blue Hoodie on Facebook to a lookalike audience in the US.",
        fields: [
            { label: 'Landing page',  value: 'https://mystore.com/products/blue-hoodie' },
            { label: 'utm_source',    value: 'facebook' },
            { label: 'utm_medium',    value: 'cpc' },
            { label: 'utm_campaign',  value: 'US | conv | blue-hoodie' },
            { label: 'utm_content',   value: '{{ad.name}}' },
        ],
        url: 'https://mystore.com/products/blue-hoodie?utm_source=facebook&utm_medium=cpc&utm_campaign=US+%7C+conv+%7C+blue-hoodie&utm_content={{ad.name}}',
        names: {
            campaign: 'US | conv | blue-hoodie',
            adset:    'US | conv | blue-hoodie | lookalike-1pc',
            ad:       'US | conv | blue-hoodie | lookalike-1pc | v1',
        },
        note: 'Facebook replaces {{ad.name}} with the actual ad name at serving time, so each ad is tracked individually without you having to update the URL.',
    },
    {
        title: 'Google Shopping campaign',
        scenario: "You're running Google Shopping ads for your whole Jackets category.",
        fields: [
            { label: 'Landing page',  value: 'https://mystore.com/category/jackets' },
            { label: 'utm_source',    value: 'google' },
            { label: 'utm_medium',    value: 'cpc' },
            { label: 'utm_campaign',  value: 'shopping | jackets' },
            { label: 'utm_term',      value: '{keyword}' },
        ],
        url: 'https://mystore.com/category/jackets?utm_source=google&utm_medium=cpc&utm_campaign=shopping+%7C+jackets&utm_term={keyword}',
        names: {
            campaign: 'shopping | jackets',
            adset:    'shopping | jackets',
            ad:       'shopping | jackets',
        },
        note: 'Google replaces {keyword} with the search term that triggered the ad, giving you keyword-level data in your reports.',
    },
];

function ExamplesSection() {
    const [open, setOpen] = useState(false);

    return (
        <div className="mt-6">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="flex items-center gap-1.5 text-sm font-medium text-zinc-500 hover:text-zinc-800 transition-colors"
            >
                {open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                New to UTM tags? See real examples
            </button>

            {open && (
                <div className="mt-4 space-y-5">

                {/* UTM field reference */}
                <div className="rounded-xl border border-zinc-200 bg-white p-5">
                    <h3 className="mb-4 text-sm font-semibold text-zinc-900">What each field means</h3>
                    <div className="divide-y divide-zinc-100">
                        {UTM_FIELDS.map((f) => (
                            <div key={f.param} className="flex flex-col gap-1.5 py-3 first:pt-0 last:pb-0 sm:flex-row sm:gap-4">
                                <code className="w-32 shrink-0 font-mono text-xs font-semibold text-zinc-700">{f.param}</code>
                                <div className="flex flex-1 flex-col gap-1.5">
                                    <p className="text-xs text-zinc-500">{f.what}</p>
                                    <div className="flex flex-wrap gap-1">
                                        {f.examples.map((ex) => (
                                            <code key={ex} className="rounded border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[11px] text-zinc-600">{ex}</code>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Real examples */}
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    {EXAMPLES.map((ex) => (
                        <div key={ex.title} className="rounded-xl border border-zinc-200 bg-white p-5 space-y-4">
                            <div>
                                <h3 className="text-sm font-semibold text-zinc-900">{ex.title}</h3>
                                <p className="mt-0.5 text-xs text-zinc-500">{ex.scenario}</p>
                            </div>

                            {/* Fields */}
                            <div className="space-y-1.5">
                                {ex.fields.map((f) => (
                                    <div key={f.label} className="flex items-baseline gap-2">
                                        <span className="w-28 shrink-0 text-[10px] font-medium text-zinc-400">{f.label}</span>
                                        <code className="font-mono text-xs text-zinc-700">{f.value}</code>
                                    </div>
                                ))}
                            </div>

                            {/* Resulting URL */}
                            <div>
                                <p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-zinc-400">Resulting URL</p>
                                <div className="rounded-md border border-zinc-100 bg-zinc-50 px-3 py-2">
                                    <p className="break-all font-mono text-[11px] leading-relaxed text-zinc-600">{ex.url}</p>
                                </div>
                            </div>

                            {/* Ad platform names */}
                            <div>
                                <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-400">Ad platform names</p>
                                <div className="space-y-1">
                                    {(['campaign', 'adset', 'ad'] as const).map((level) => (
                                        ex.names[level] !== ex.names.campaign || level === 'campaign' ? (
                                            <div key={level} className="flex items-baseline gap-2">
                                                <span className="w-16 shrink-0 capitalize text-[10px] text-zinc-400">{level}</span>
                                                <code className="font-mono text-[11px] text-zinc-700">{ex.names[level]}</code>
                                            </div>
                                        ) : null
                                    ))}
                                </div>
                            </div>

                            {/* Plain-English note */}
                            <p className="text-[11px] leading-relaxed text-zinc-500 border-t border-zinc-100 pt-3">{ex.note}</p>
                        </div>
                    ))}
                </div>
                </div>
            )}
        </div>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function TagGenerator({ campaigns }: Props) {
    const [baseUrl, setBaseUrl]                       = useState('');
    const [activeTemplate, setActiveTemplate]         = useState<string>('facebook_conversion');
    const [showAdvanced, setShowAdvanced]             = useState(false);
    const [autoFillUtmCampaign, setAutoFillUtmCampaign] = useState(true);

    const [convention, setConvention] = useState<ConventionFields>({
        country: 'US', campaign: 'conv', target: 'product-slug', audience: 'lookalike-1pc', variant: 'v1',
    });

    const [utm, setUtm] = useState<UtmFields>({
        source: 'facebook', medium: 'cpc', campaign: '', content: '{{ad.name}}', term: '',
    });

    const names = useMemo(() => buildConventionNames(convention), [convention]);

    useEffect(() => {
        if (autoFillUtmCampaign) {
            setUtm((prev) => prev.campaign === names.campaign ? prev : { ...prev, campaign: names.campaign });
        }
    }, [autoFillUtmCampaign, names.campaign]);

    function applyTemplate(key: string): void {
        const tmpl = TEMPLATES[key];
        if (!tmpl) return;
        setActiveTemplate(key);
        setUtm((prev) => ({ ...prev, ...tmpl.utm }));
        setConvention((prev) => ({ ...prev, ...tmpl.convention }));
    }

    function setUtmField<K extends keyof UtmFields>(key: K, value: UtmFields[K]): void {
        setUtm((prev) => ({ ...prev, [key]: value }));
    }

    function setConventionField<K extends keyof ConventionFields>(key: K, value: ConventionFields[K]): void {
        setConvention((prev) => ({ ...prev, [key]: value }));
    }

    function clearCampaignSetup(): void {
        setConvention({ country: '', campaign: '', target: '', audience: '', variant: '' });
        setUtm({ source: '', medium: '', campaign: '', content: '', term: '' });
        setAutoFillUtmCampaign(true);
    }

    const taggedUrl = useMemo(() => buildTaggedUrl(baseUrl, utm), [baseUrl, utm]);

    const facebookCampaigns = campaigns.filter((c) => c.platform === 'facebook');
    const googleCampaigns   = campaigns.filter((c) => c.platform === 'google');

    // Recognised values must stay in sync with ChannelMappingsSeeder.php.
    const sourceTooltip =
        'We recognize: facebook, meta, fb, ig, instagram, tiktok, pinterest for paid social; google, adwords, bing, microsoft for paid search. ' +
        'Any other value appears as "Other Tagged" — visible in reports but not counted as paid attribution.';

    return (
        <AppLayout>
            <Head title="Tag Generator" />
            <PageHeader
                title="Tag Generator"
                subtitle="Build UTM-tagged URLs and matching campaign / adset / ad names in one step"
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {/* ── Left: inputs ── */}
                <div className="space-y-5">

                    {/* Template picker */}
                    <div className="rounded-xl border border-zinc-200 bg-white p-5">
                        <h2 className="mb-3 text-sm font-semibold text-zinc-900">Start from a template</h2>

                        <div className="space-y-3">
                            <div>
                                <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-400">Facebook / Meta</p>
                                <div className="flex flex-wrap gap-2">
                                    {FACEBOOK_TEMPLATES.map(([key, tmpl]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            onClick={() => applyTemplate(key)}
                                            className={cn(
                                                'rounded-md border px-3 py-1.5 text-xs transition-colors',
                                                activeTemplate === key
                                                    ? 'border-primary bg-primary/5 text-primary font-medium'
                                                    : 'border-zinc-200 text-zinc-600 hover:border-zinc-300 hover:bg-zinc-50',
                                            )}
                                        >
                                            {tmpl.label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div>
                                <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-400">Google Ads</p>
                                <div className="flex flex-wrap gap-2">
                                    {GOOGLE_TEMPLATES.map(([key, tmpl]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            onClick={() => applyTemplate(key)}
                                            className={cn(
                                                'rounded-md border px-3 py-1.5 text-xs transition-colors',
                                                activeTemplate === key
                                                    ? 'border-primary bg-primary/5 text-primary font-medium'
                                                    : 'border-zinc-200 text-zinc-600 hover:border-zinc-300 hover:bg-zinc-50',
                                            )}
                                        >
                                            {tmpl.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Destination URL */}
                    <div className="rounded-xl border border-zinc-200 bg-white p-5">
                        <h2 className="mb-3 text-sm font-semibold text-zinc-900">Destination URL</h2>
                        <Field
                            label="Landing page"
                            value={baseUrl}
                            onChange={setBaseUrl}
                            placeholder="https://your-store.com/product-page"
                            hint="The page your ad links to — usually your product or landing page."
                        />
                    </div>

                    {/* Campaign setup — naming convention + UTM merged */}
                    <div className="rounded-xl border border-zinc-200 bg-white p-5">
                        <div className="mb-1 flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-zinc-900">Campaign setup</h2>
                            <button
                                type="button"
                                onClick={clearCampaignSetup}
                                className="flex items-center gap-1 text-[11px] font-medium text-zinc-400 hover:text-zinc-600 transition-colors"
                            >
                                <X className="h-3 w-3" /> Clear
                            </button>
                        </div>
                        <p className="mb-4 text-[11px] text-zinc-500">
                            These fields generate your ad-platform names <em>and</em> the tagged URL simultaneously.
                        </p>

                        {/* Naming convention */}
                        <div className="space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <Field
                                    label="Country"
                                    value={convention.country}
                                    onChange={(v) => setConventionField('country', v)}
                                    placeholder="US"
                                    hint="Two-letter code. Leave blank to skip."
                                />
                                <Field
                                    label="Campaign *"
                                    value={convention.campaign}
                                    onChange={(v) => setConventionField('campaign', v)}
                                    placeholder="conv"
                                    hint="'conv', 'retarget', 'brand', etc."
                                />
                            </div>
                            <Field
                                label="Target"
                                value={convention.target}
                                onChange={(v) => setConventionField('target', v)}
                                placeholder="product-slug"
                                hint="Product or category slug."
                            />
                            <div className="grid grid-cols-2 gap-3">
                                <Field
                                    label="Audience"
                                    value={convention.audience}
                                    onChange={(v) => setConventionField('audience', v)}
                                    placeholder="lookalike-1pc"
                                    hint="Adset-level segment."
                                />
                                <Field
                                    label="Variant"
                                    value={convention.variant}
                                    onChange={(v) => setConventionField('variant', v)}
                                    placeholder="v1"
                                    hint="Creative variant. Ad name only."
                                />
                            </div>
                        </div>

                        {/* Divider linking naming → UTM */}
                        <div className="my-4 flex items-center gap-2">
                            <div className="h-px flex-1 bg-zinc-100" />
                            <span className="text-[10px] font-medium text-zinc-400">↳ UTM parameters</span>
                            <div className="h-px flex-1 bg-zinc-100" />
                        </div>

                        {/* Core UTM fields */}
                        <div className="space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <Field
                                    label="utm_source *"
                                    value={utm.source}
                                    onChange={(v) => setUtmField('source', v)}
                                    placeholder="facebook"
                                    tooltip={sourceTooltip}
                                />
                                <Field
                                    label="utm_medium *"
                                    value={utm.medium}
                                    onChange={(v) => setUtmField('medium', v)}
                                    placeholder="cpc"
                                />
                            </div>

                            {/* utm_campaign with lock toggle */}
                            <div>
                                <div className="mb-1 flex items-center justify-between">
                                    <label className="text-xs font-medium text-zinc-600">utm_campaign</label>
                                    <button
                                        type="button"
                                        onClick={() => setAutoFillUtmCampaign((v) => !v)}
                                        className={cn(
                                            'flex items-center gap-1 rounded border px-2 py-0.5 text-[11px] font-medium transition-colors',
                                            autoFillUtmCampaign
                                                ? 'border-primary/30 bg-primary/5 text-primary'
                                                : 'border-zinc-200 bg-white text-zinc-500 hover:border-zinc-300',
                                        )}
                                        title={autoFillUtmCampaign
                                            ? 'utm_campaign is synced to the campaign name above — click to override'
                                            : 'Click to sync utm_campaign to the campaign name above'}
                                    >
                                        {autoFillUtmCampaign
                                            ? <><Lock className="h-3 w-3" /> Synced</>
                                            : <><Unlock className="h-3 w-3" /> Manual</>}
                                    </button>
                                </div>
                                <input
                                    type="text"
                                    value={utm.campaign}
                                    onChange={(e) => setUtmField('campaign', e.target.value)}
                                    placeholder="Generated from campaign name above"
                                    readOnly={autoFillUtmCampaign}
                                    className={cn(
                                        'w-full rounded-md border border-zinc-200 px-3 py-1.5 text-sm text-zinc-900 outline-none placeholder:text-zinc-300 focus:border-primary/50 focus:ring-1 focus:ring-primary/20',
                                        autoFillUtmCampaign ? 'bg-zinc-50 cursor-not-allowed' : 'bg-white',
                                    )}
                                />
                            </div>

                            {/* Advanced toggle */}
                            <button
                                type="button"
                                onClick={() => setShowAdvanced((v) => !v)}
                                className="flex items-center gap-1 text-[11px] font-medium text-zinc-400 hover:text-zinc-600 transition-colors"
                            >
                                {showAdvanced ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                                Advanced — utm_content, utm_term
                            </button>

                            {showAdvanced && (
                                <div className="space-y-3 pt-1">
                                    <Field
                                        label="utm_content"
                                        value={utm.content}
                                        onChange={(v) => setUtmField('content', v)}
                                        placeholder="{{ad.name}}"
                                        hint="Ad identifier. Facebook: {{ad.name}} · Google: {adgroupname}"
                                    />
                                    <Field
                                        label="utm_term"
                                        value={utm.term}
                                        onChange={(v) => setUtmField('term', v)}
                                        placeholder="{keyword}"
                                        hint="Google search keyword: {keyword}. Usually empty for Facebook."
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* ── Right: outputs ── */}
                <div className="space-y-5">

                    {/* Tagged URL preview */}
                    <div className="rounded-xl border border-zinc-200 bg-white p-5">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-zinc-900">Tagged URL</h2>
                            <CopyButton text={taggedUrl} />
                        </div>
                        <div className="min-h-[80px] rounded-md border border-zinc-100 bg-zinc-50 p-3">
                            {taggedUrl ? (
                                <p className="break-all font-mono text-xs leading-relaxed text-zinc-700">{taggedUrl}</p>
                            ) : (
                                <p className="text-xs text-zinc-400">Enter a destination URL to see the preview.</p>
                            )}
                        </div>
                        {taggedUrl && (
                            <p className="mt-2 text-[10px] text-zinc-400">
                                Paste into your ad's destination URL field. The platform replaces dynamic placeholders at serving time.
                            </p>
                        )}
                    </div>

                    {/* Names preview */}
                    <div className="rounded-xl border border-zinc-200 bg-white p-5">
                        <h2 className="mb-1 text-sm font-semibold text-zinc-900">Campaign / Adset / Ad names</h2>
                        <p className="mb-4 text-[11px] text-zinc-500">
                            Copy these into the name fields in Ads Manager or Google Ads.
                            We read country and target from these names to power country-level spend reports and product matching.
                        </p>
                        <div className="space-y-3">
                            <NameRow label="Campaign name" value={names.campaign} />
                            <NameRow label="Adset name"    value={names.adset} />
                            <NameRow label="Ad name"       value={names.ad} />
                        </div>
                        {!names.campaign && (
                            <p className="mt-3 text-[10px] text-zinc-400">
                                Fill in at least <code className="text-[10px]">Campaign</code> above to see generated names.
                            </p>
                        )}
                    </div>

                    {/* Connected campaigns cross-check */}
                    {(facebookCampaigns.length > 0 || googleCampaigns.length > 0) && (
                        <div className="rounded-xl border border-zinc-200 bg-white p-5">
                            <h2 className="mb-1 text-sm font-semibold text-zinc-900">Your synced campaigns</h2>
                            <p className="mb-3 text-[11px] text-zinc-500">
                                Cross-check that the generated names match the shape you're using here.
                            </p>
                            {facebookCampaigns.length > 0 && (
                                <div className="mb-3">
                                    <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-400">Facebook</div>
                                    <div className="space-y-1">
                                        {facebookCampaigns.map((c) => (
                                            <div key={c.id} className="flex items-center justify-between gap-2 rounded-md bg-zinc-50 px-2.5 py-1.5">
                                                <span className="flex-1 truncate text-xs text-zinc-700">{c.name}</span>
                                                <CopyButton text={c.name} />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {googleCampaigns.length > 0 && (
                                <div>
                                    <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-400">Google</div>
                                    <div className="space-y-1">
                                        {googleCampaigns.map((c) => (
                                            <div key={c.id} className="flex items-center justify-between gap-2 rounded-md bg-zinc-50 px-2.5 py-1.5">
                                                <span className="flex-1 truncate text-xs text-zinc-700">{c.name}</span>
                                                <CopyButton text={c.name} />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Docs link */}
                    <div className="rounded-xl border border-zinc-100 bg-zinc-50 p-4">
                        <p className="text-xs text-zinc-500">
                            Need to verify existing tags?{' '}
                            <a
                                href="https://ga-dev-tools.google/ga4/campaign-url-builder/"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-0.5 font-medium text-primary hover:underline"
                            >
                                Google Campaign URL Builder <ExternalLink className="h-3 w-3" />
                            </a>
                        </p>
                    </div>
                </div>
            </div>

            {/* Examples — for users new to UTM tagging */}
            <ExamplesSection />
        </AppLayout>
    );
}
