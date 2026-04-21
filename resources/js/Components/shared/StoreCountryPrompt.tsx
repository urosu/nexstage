import { useState, useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';

/**
 * Country options list — ISO 3166-1 alpha-2 codes for markets Nexstage targets.
 * Shared between the onboarding StepCountry component and the store settings page.
 */
export const COUNTRY_OPTIONS: { code: string; name: string }[] = [
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

/**
 * Map common ccTLDs to ISO 3166-1 alpha-2 country codes.
 *
 * Handles .co.uk / .co.nz patterns (caller passes the tld portion only).
 * Returns null for generic TLDs (.com, .net, .org, etc.).
 */
const CCTLD_MAP: Record<string, string> = {
    ac: 'GB', ad: 'AD', ae: 'AE', at: 'AT', au: 'AU', be: 'BE', bg: 'BG',
    br: 'BR', ca: 'CA', ch: 'CH', cn: 'CN', cy: 'CY', cz: 'CZ', de: 'DE',
    dk: 'DK', ee: 'EE', es: 'ES', fi: 'FI', fr: 'FR', gb: 'GB', gr: 'GR',
    hr: 'HR', hu: 'HU', ie: 'IE', it: 'IT', jp: 'JP', kr: 'KR', lt: 'LT',
    lu: 'LU', lv: 'LV', mt: 'MT', mx: 'MX', nl: 'NL', no: 'NO', nz: 'NZ',
    pl: 'PL', pt: 'PT', ro: 'RO', ru: 'RU', se: 'SE', si: 'SI', sk: 'SK',
    tr: 'TR', ua: 'UA', uk: 'GB', us: 'US',
};

/**
 * Infer a two-letter country code from a store URL by reading the ccTLD.
 * Returns null for .com / .net / .org and other generic TLDs.
 */
export function detectCountryFromUrl(url: string | null | undefined): string | null {
    if (!url) return null;
    try {
        const hostname = new URL(url.startsWith('http') ? url : `https://${url}`).hostname;
        const parts = hostname.toLowerCase().split('.');
        const tld = parts[parts.length - 1];
        // Handle second-level ccTLDs like co.uk, co.nz
        if (parts.length >= 3 && parts[parts.length - 2] === 'co') {
            const code = CCTLD_MAP[tld];
            if (code) return code;
        }
        return CCTLD_MAP[tld] ?? null;
    } catch {
        return null;
    }
}

interface Props {
    /** Current value (from the server). Pass null when unset. */
    value: string | null;
    /** POST URL to save the country. Receives { primary_country_code: string|null }. */
    saveUrl: string;
    /** Shown alongside the dropdown label for context. */
    storeName: string;
    /** Used for ccTLD detection pre-fill. */
    websiteUrl?: string | null;
    /** Called after a successful save/clear so the parent can update its own state. */
    onSaved?: (code: string | null) => void;
    /** When true, renders in compact "settings field" mode rather than a full prompt card. */
    compact?: boolean;
}

/**
 * Country dropdown for `stores.primary_country_code`.
 *
 * Used in two places:
 *   1. Onboarding step 2 (via the inline StepCountry component in Onboarding/Index.tsx)
 *   2. Store settings page — `compact` mode, always visible
 *
 * ccTLD detection pre-fills the dropdown from `websiteUrl` when the value is currently null.
 * "Clear" writes null explicitly (multi-country stores legitimately leave this null).
 *
 * @see PLANNING.md section 5.7
 */
export function StoreCountryPrompt({
    value,
    saveUrl,
    storeName: _storeName,
    websiteUrl,
    onSaved,
    compact = false,
}: Props) {
    const detected  = value === null ? detectCountryFromUrl(websiteUrl) : null;
    const [selected, setSelected] = useState<string>(value ?? detected ?? '');
    const [processing, setProcessing] = useState(false);
    const [saved, setSaved] = useState(false);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => () => { if (timerRef.current) clearTimeout(timerRef.current); }, []);

    function save(code: string | null) {
        setProcessing(true);
        router.patch(
            saveUrl,
            { primary_country_code: code },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSaved(true);
                    if (timerRef.current) clearTimeout(timerRef.current);
                    timerRef.current = setTimeout(() => setSaved(false), 2000);
                    onSaved?.(code);
                },
                onFinish: () => setProcessing(false),
            },
        );
    }

    function handleSave(e: React.FormEvent) {
        e.preventDefault();
        save(selected || null);
    }

    if (compact) {
        return (
            <form onSubmit={handleSave} className="flex items-end gap-3">
                <div className="flex-1">
                    <label className="mb-1.5 block text-xs font-medium text-zinc-500">
                        Primary country
                    </label>
                    <select
                        value={selected}
                        onChange={(e) => { setSelected(e.target.value); setSaved(false); }}
                        className="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="">None</option>
                        {COUNTRY_OPTIONS.map((c) => (
                            <option key={c.code} value={c.code}>
                                {c.name} ({c.code})
                            </option>
                        ))}
                    </select>
                    {detected && value === null && (
                        <p className="mt-1 text-xs text-zinc-400">
                            Pre-filled from domain ccTLD
                        </p>
                    )}
                </div>
                <Button type="submit" size="sm" disabled={processing}>
                    {saved ? 'Saved' : 'Save'}
                </Button>
            </form>
        );
    }

    return (
        <form onSubmit={handleSave} className="space-y-5">
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
                {detected && value === null && (
                    <p className="mt-1 text-xs text-zinc-400">
                        Detected from domain ccTLD
                    </p>
                )}
            </div>

            <Button type="submit" className="w-full" disabled={!selected || processing}>
                Continue →
            </Button>
        </form>
    );
}
