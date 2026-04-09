import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/Components/layouts/AppLayout';
import { StoreLayout } from '@/Components/layouts/StoreLayout';
import type { StoreData } from '@/Components/layouts/StoreLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { formatCurrency } from '@/lib/formatters';
import type { PageProps } from '@/types';

// Inline lookup for common country names — avoids adding a library dependency
const COUNTRY_NAMES: Record<string, string> = {
    AD: 'Andorra',         AE: 'UAE',              AT: 'Austria',
    AU: 'Australia',       BE: 'Belgium',           BG: 'Bulgaria',
    BR: 'Brazil',          CA: 'Canada',            CH: 'Switzerland',
    CN: 'China',           CY: 'Cyprus',            CZ: 'Czech Republic',
    DE: 'Germany',         DK: 'Denmark',           EE: 'Estonia',
    ES: 'Spain',           FI: 'Finland',           FR: 'France',
    GB: 'United Kingdom',  GR: 'Greece',            HR: 'Croatia',
    HU: 'Hungary',         IE: 'Ireland',           IT: 'Italy',
    JP: 'Japan',           KR: 'South Korea',       LT: 'Lithuania',
    LU: 'Luxembourg',      LV: 'Latvia',            MT: 'Malta',
    MX: 'Mexico',          NL: 'Netherlands',       NO: 'Norway',
    NZ: 'New Zealand',     PL: 'Poland',            PT: 'Portugal',
    RO: 'Romania',         RU: 'Russia',            SE: 'Sweden',
    SI: 'Slovenia',        SK: 'Slovakia',          TR: 'Turkey',
    UA: 'Ukraine',         US: 'United States',
};

interface CountryRow {
    country_code: string;
    revenue: number;
    share: number;
}

interface Props extends PageProps {
    store: StoreData;
    countries: CountryRow[];
    from: string;
    to: string;
}

export default function StoreCountries({ store, countries }: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title={`${store.name} — Countries`} />
            <StoreLayout store={store} activeTab="countries">
                {countries.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-20 text-center">
                        <p className="text-sm text-zinc-400">No country data for this period.</p>
                        <p className="text-xs text-zinc-400 mt-1">
                            Country data is derived from customer billing addresses on orders.
                        </p>
                    </div>
                ) : (
                    <div className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                    <th className="px-4 py-3 font-medium text-zinc-400 w-10">#</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400">Country</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400 hidden sm:table-cell">Share</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400 text-right">Revenue</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100">
                                {countries.map((row, index) => {
                                    const code = row.country_code.toUpperCase();
                                    return (
                                        <tr key={row.country_code} className="hover:bg-zinc-50 transition-colors">
                                            <td className="px-4 py-3 text-zinc-400 tabular-nums">{index + 1}</td>
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-zinc-900">
                                                    {COUNTRY_NAMES[code] ?? code}
                                                </div>
                                                <div className="text-xs text-zinc-400">{code}</div>
                                            </td>
                                            <td className="px-4 py-3 hidden sm:table-cell">
                                                <div className="flex items-center gap-2">
                                                    <div className="w-24 h-1.5 rounded-full bg-zinc-100 overflow-hidden">
                                                        <div
                                                            className="h-full bg-indigo-500 rounded-full"
                                                            style={{ width: `${Math.min(row.share, 100)}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-zinc-500 tabular-nums w-12 text-right text-xs">
                                                        {row.share.toFixed(1)}%
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium text-zinc-900 tabular-nums">
                                                {formatCurrency(row.revenue, currency)}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </StoreLayout>
        </AppLayout>
    );
}
