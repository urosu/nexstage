import { cn } from '@/lib/utils';
import { formatCurrency } from '@/lib/formatters';

export interface CohortRow {
    month: string;                          // "2025-01"
    initial_customers: number;
    revenue: (number | null)[];             // non-cumulative, M0–M11
    cumulative_revenue: (number | null)[];  // cumulative, M0–M11
}

interface Props {
    rows: CohortRow[];
    weightedAvg: (number | null)[];
    mode: 'cumulative' | 'non_cumulative';
    format: 'absolute' | 'percent';
    currency?: string;
}

function formatMonth(ym: string): string {
    const [y, m] = ym.split('-');
    const date = new Date(Number(y), Number(m) - 1);
    return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
}

/**
 * Cohort retention table per Phase 3.2 spec.
 * Rows = first-purchase month; columns = M0–M11.
 * Toggles: cumulative/non-cumulative and absolute/percent.
 * Weighted-average footer row (Metorik pattern).
 * Heatmap shading: darker = higher LTV per initial customer.
 */
export function CohortTable({ rows, weightedAvg, mode, format, currency = '€' }: Props) {
    const MONTHS = Array.from({ length: 12 }, (_, i) => `M${i}`);

    function getCellValue(row: CohortRow, m: number): number | null {
        const series = mode === 'cumulative' ? row.cumulative_revenue : row.revenue;
        const raw = series[m] ?? null;
        if (raw === null || row.initial_customers === 0) return null;
        if (format === 'percent') {
            // % of M0 cumulative revenue (LTV relative to first month)
            const m0 = row.cumulative_revenue[0] ?? null;
            if (m0 === null || m0 === 0) return null;
            return raw / m0;
        }
        // Absolute: per-customer average
        return raw / row.initial_customers;
    }

    function getWeightedValue(m: number): number | null {
        const raw = weightedAvg[m] ?? null;
        if (raw === null) return null;
        if (format === 'percent') {
            const m0 = weightedAvg[0] ?? null;
            if (m0 === null || m0 === 0) return null;
            return raw / m0;
        }
        return raw;
    }

    // Compute max value for heatmap normalisation
    const allValues: number[] = [];
    for (const row of rows) {
        for (let m = 0; m < 12; m++) {
            const v = getCellValue(row, m);
            if (v !== null) allValues.push(v);
        }
    }
    const maxVal = allValues.length > 0 ? Math.max(...allValues) : 1;

    function heatmapClass(value: number | null): string {
        if (value === null) return 'bg-zinc-50 text-zinc-300';
        const ratio = maxVal > 0 ? value / maxVal : 0;
        if (ratio >= 0.80) return 'bg-green-600 text-white';
        if (ratio >= 0.60) return 'bg-green-400 text-white';
        if (ratio >= 0.40) return 'bg-green-200 text-green-900';
        if (ratio >= 0.20) return 'bg-green-100 text-green-800';
        return 'bg-zinc-100 text-zinc-600';
    }

    function displayValue(value: number | null): string {
        if (value === null) return '—';
        if (format === 'percent') return `${(value * 100).toFixed(0)}%`;
        return formatCurrency(value, currency);
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-xs border-collapse">
                <thead>
                    <tr>
                        <th className="sticky left-0 z-10 bg-white text-left px-3 py-2 text-zinc-500 font-medium whitespace-nowrap border-b border-zinc-200">
                            Cohort
                        </th>
                        <th className="px-2 py-2 text-zinc-500 font-medium whitespace-nowrap border-b border-zinc-200 text-right">
                            Customers
                        </th>
                        {MONTHS.map((m) => (
                            <th key={m} className="px-2 py-2 text-zinc-500 font-medium border-b border-zinc-200 text-right min-w-[72px]">
                                {m}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.month} className="hover:bg-zinc-50">
                            <td className="sticky left-0 z-10 bg-white px-3 py-1.5 text-zinc-700 font-medium whitespace-nowrap border-b border-zinc-100">
                                {formatMonth(row.month)}
                            </td>
                            <td className="px-2 py-1.5 text-zinc-600 text-right border-b border-zinc-100">
                                {row.initial_customers}
                            </td>
                            {Array.from({ length: 12 }, (_, m) => {
                                const value = getCellValue(row, m);
                                return (
                                    <td
                                        key={m}
                                        className={cn(
                                            'px-2 py-1.5 text-right border-b border-zinc-100 rounded-sm',
                                            heatmapClass(value),
                                        )}
                                        title={value !== null ? `${formatMonth(row.month)} M${m}: ${displayValue(value)}` : undefined}
                                    >
                                        {displayValue(value)}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
                {/* Weighted-average footer (Metorik pattern) */}
                <tfoot>
                    <tr className="bg-zinc-50 font-semibold">
                        <td className="sticky left-0 z-10 bg-zinc-50 px-3 py-2 text-zinc-700 whitespace-nowrap border-t-2 border-zinc-200">
                            Weighted avg
                        </td>
                        <td className="px-2 py-2 text-zinc-500 text-right border-t-2 border-zinc-200">—</td>
                        {Array.from({ length: 12 }, (_, m) => {
                            const value = getWeightedValue(m);
                            return (
                                <td key={m} className={cn('px-2 py-2 text-right border-t-2 border-zinc-200', heatmapClass(value))}>
                                    {displayValue(value)}
                                </td>
                            );
                        })}
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}
