import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Head, router, usePage } from '@inertiajs/react';
import { wurl } from '@/lib/workspace-url';
import { CalendarDays, ChevronLeft, ChevronRight, ShoppingBag } from 'lucide-react';
import { PageProps } from '@/types';
import { useState } from 'react';

interface Holiday {
    id: number;
    name: string;
    date: string;
    day_label: string;
    days_away: number;
    type: 'public' | 'commercial';
    category: string | null;
}

interface Country {
    code: string;
    name: string;
}

interface Props {
    holidays: Holiday[];
    year: number;
    current_year: number;
    selected_country: string;
    workspace_country: string | null;
    countries: Country[];
}

const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

function groupByMonth(holidays: Holiday[]): Record<number, Holiday[]> {
    const groups: Record<number, Holiday[]> = {};
    for (const h of holidays) {
        const month = new Date(h.date + 'T00:00:00').getMonth();
        if (!groups[month]) groups[month] = [];
        groups[month].push(h);
    }
    return groups;
}

function DaysAway({ days, type }: { days: number; type: 'public' | 'commercial' }) {
    const isCommercial = type === 'commercial';
    if (days === 0) {
        return (
            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                isCommercial ? 'bg-violet-100 text-violet-700' : 'bg-amber-100 text-amber-700'
            }`}>Today</span>
        );
    }
    if (days > 0) {
        return (
            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                isCommercial ? 'bg-violet-50 text-violet-600' : 'bg-amber-50 text-amber-600'
            }`}>
                in {days}d
            </span>
        );
    }
    return <span className="text-xs text-zinc-400">{Math.abs(days)}d ago</span>;
}

export default function HolidaysIndex({
    holidays, year, current_year, selected_country, workspace_country, countries,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const slug = workspace?.slug ?? '';

    const [showPublic, setShowPublic]         = useState(true);
    const [showCommercial, setShowCommercial] = useState(true);

    function navigate(params: { year?: number; country?: string }) {
        router.get(
            wurl(slug, '/holidays'),
            { year: params.year ?? year, country: params.country ?? selected_country },
            { preserveState: false, replace: true },
        );
    }

    const filtered = holidays.filter(h =>
        (h.type === 'public' && showPublic) || (h.type === 'commercial' && showCommercial),
    );

    const grouped  = groupByMonth(filtered);
    const months   = Object.keys(grouped).map(Number).sort((a, b) => a - b);
    const selectedName = countries.find(c => c.code === selected_country)?.name ?? selected_country;

    return (
        <AppLayout>
            <Head title="Holidays" />

            <PageHeader
                title="Holidays"
                subtitle={`Holidays & commercial events — ${selectedName} ${year}`}
            />

            <div className="mt-6 max-w-2xl space-y-5">

                {/* Controls row: country dropdown + year navigation */}
                <div className="flex flex-wrap items-center gap-3">
                    {/* Country selector */}
                    <select
                        value={selected_country}
                        onChange={(e) => navigate({ country: e.target.value })}
                        className="rounded-md border border-zinc-300 bg-white py-1.5 pl-3 pr-8 text-sm text-zinc-900 shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        {workspace_country && (
                            <optgroup label="Your workspace">
                                {countries
                                    .filter(c => c.code === workspace_country)
                                    .map(c => (
                                        <option key={c.code} value={c.code}>{c.name} ({c.code})</option>
                                    ))}
                            </optgroup>
                        )}
                        <optgroup label="All countries">
                            {countries.map(c => (
                                <option key={c.code} value={c.code}>{c.name} ({c.code})</option>
                            ))}
                        </optgroup>
                    </select>

                    {/* Year navigation */}
                    <div className="flex items-center gap-2">
                        <button
                            onClick={() => navigate({ year: year - 1 })}
                            className="rounded-md border border-zinc-200 p-1.5 text-zinc-500 hover:bg-zinc-50 hover:text-zinc-700 transition-colors"
                            aria-label="Previous year"
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </button>
                        <span className="min-w-[4rem] text-center text-sm font-semibold text-zinc-900">{year}</span>
                        <button
                            onClick={() => navigate({ year: year + 1 })}
                            disabled={year >= current_year + 2}
                            className="rounded-md border border-zinc-200 p-1.5 text-zinc-500 hover:bg-zinc-50 hover:text-zinc-700 disabled:opacity-40 transition-colors"
                            aria-label="Next year"
                        >
                            <ChevronRight className="h-4 w-4" />
                        </button>
                        {year !== current_year && (
                            <button
                                onClick={() => navigate({ year: current_year })}
                                className="text-xs text-primary hover:underline"
                            >
                                Back to {current_year}
                            </button>
                        )}
                    </div>
                </div>

                {/* Type filter toggles */}
                <div className="flex items-center gap-2">
                    <button
                        onClick={() => setShowPublic(v => !v)}
                        className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                            showPublic
                                ? 'border-amber-300 bg-amber-50 text-amber-700'
                                : 'border-zinc-200 bg-white text-zinc-400'
                        }`}
                    >
                        <CalendarDays className="h-3.5 w-3.5" />
                        Public holidays
                    </button>
                    <button
                        onClick={() => setShowCommercial(v => !v)}
                        className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                            showCommercial
                                ? 'border-violet-300 bg-violet-50 text-violet-700'
                                : 'border-zinc-200 bg-white text-zinc-400'
                        }`}
                    >
                        <ShoppingBag className="h-3.5 w-3.5" />
                        Commercial events
                    </button>
                </div>

                {/* No results */}
                {filtered.length === 0 && (
                    <div className="rounded-lg border border-zinc-200 bg-zinc-50 px-6 py-10 text-center">
                        <CalendarDays className="mx-auto mb-3 h-8 w-8 text-zinc-400" />
                        <p className="text-sm font-medium text-zinc-700">
                            {holidays.length === 0
                                ? `No data found for ${selectedName} in ${year}`
                                : 'No results match the active filters'}
                        </p>
                        <p className="mt-1 text-xs text-zinc-400">
                            {holidays.length === 0
                                ? 'Data for this country and year may not have been loaded yet.'
                                : 'Toggle the filter pills above to show more.'}
                        </p>
                    </div>
                )}

                {/* Holiday list grouped by month */}
                {months.map((month) => (
                    <div key={month} className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                        <div className="border-b border-zinc-100 bg-zinc-50 px-4 py-2.5">
                            <h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                {MONTH_NAMES[month]}
                            </h3>
                        </div>
                        <ul className="divide-y divide-zinc-100">
                            {grouped[month].map((h) => {
                                const isPast       = h.days_away < 0;
                                const isCommercial = h.type === 'commercial';
                                return (
                                    <li
                                        key={h.id}
                                        className={`flex items-center justify-between px-4 py-3 ${isPast ? 'opacity-50' : ''}`}
                                    >
                                        <div className="flex items-center gap-3">
                                            <div className="w-20 shrink-0">
                                                <p className="text-sm font-medium tabular-nums text-zinc-900">
                                                    {h.date.slice(5).replace('-', '/')}
                                                </p>
                                                <p className="text-xs text-zinc-400">{h.day_label}</p>
                                            </div>
                                            <div className="flex items-center gap-1.5">
                                                {isCommercial && (
                                                    <ShoppingBag className="h-3.5 w-3.5 shrink-0 text-violet-500" />
                                                )}
                                                <p className="text-sm text-zinc-800">{h.name}</p>
                                            </div>
                                        </div>
                                        {year === current_year && <DaysAway days={h.days_away} type={h.type} />}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
