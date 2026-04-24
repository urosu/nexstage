import { useState } from 'react';
import { ChevronRight, ChevronDown } from 'lucide-react';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { cn } from '@/lib/utils';

// ── Types ─────────────────────────────────────────────────────────────────────

export interface ChannelMatrixChildRow {
    id: number | string;
    name: string;
    spend: number | null;
    revenue: number | null;
    real_roas: number | null;
    platform_roas: number | null;
    clicks?: number | null;
    impressions?: number | null;
    position?: number | null;
}

export interface ChannelMatrixRow {
    channel_type: string;
    rollup_key?: string;
    channel_name: string;
    spend: number | null;
    revenue: number;
    real_profit: number | null;
    real_roas: number | null;
    platform_roas: number | null;
    platform_roas_delta_pct: number | null;
    cac: number | null;
    first_order_roas: number | null;
    day_30_roas: number | null;
    day_30_roas_pending: boolean;
    day_30_roas_locks_in: number | null;
    orders: number;
    new_customers: number | null;
    wl_tag: 'winner' | 'loser' | null;
    children?: ChannelMatrixChildRow[];
}

interface Props {
    rows: ChannelMatrixRow[];
    currency: string;
    loading?: boolean;
    attributionModel: 'first_touch' | 'last_touch';
}

// ── Channel color map ─────────────────────────────────────────────────────────

const CHANNEL_COLORS: Record<string, string> = {
    paid_search:    '#8b5cf6',
    paid_social:    '#3b82f6',
    organic:        '#16a34a',
    organic_search: '#16a34a',
    email:          '#f59e0b',
    direct:         '#a1a1aa',
    not_tracked:    '#d4d4d8',
};

function channelColor(row: ChannelMatrixRow): string {
    return CHANNEL_COLORS[row.rollup_key ?? row.channel_type] ?? '#a1a1aa';
}

// ── Shared cell primitives ────────────────────────────────────────────────────

function Th({ children, className, title }: { children: React.ReactNode; className?: string; title?: string }) {
    return (
        <th title={title} className={cn('px-3 py-3 text-right text-[10px] font-medium uppercase tracking-wide text-zinc-400', className)}>
            {children}
        </th>
    );
}

function Td({ children, className }: { children: React.ReactNode; className?: string }) {
    return (
        <td className={cn('px-3 py-3 text-right tabular-nums text-zinc-700', className)}>
            {children}
        </td>
    );
}

function PlatformRoasDelta({ delta }: { delta: number | null }) {
    if (delta === null) return null;
    const color = delta > 10
        ? 'text-red-600 bg-red-50 border-red-200'
        : delta < -5
            ? 'text-green-700 bg-green-50 border-green-200'
            : 'text-zinc-500 bg-zinc-50 border-zinc-200';
    return (
        <span className={cn('ml-1 inline-flex items-center rounded border px-1 text-[10px] font-medium', color)}>
            {delta > 0 ? '+' : ''}{delta}%
        </span>
    );
}

// ── Main component ────────────────────────────────────────────────────────────

/**
 * Channel matrix table with §M1 rollup rows and expandable campaign/query children.
 *
 * Data is passed entirely via props — zero data-fetching capability (CLAUDE.md gotcha).
 * All data is pre-joined by AcquisitionController and passed as flat arrays.
 *
 * Columns: Channel · Spend · Revenue · Real Profit · Real ROAS · Platform ROAS (Δ) ·
 *          CAC · First-order ROAS · Day-30 ROAS
 *
 * Mobile: channel column is sticky-left; remaining columns scroll horizontally.
 */
export function ChannelMatrix({ rows, currency, loading = false, attributionModel }: Props) {
    const [expanded, setExpanded] = useState<Set<string>>(new Set());

    function toggleExpand(key: string) {
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    }

    if (rows.length === 0 && !loading) {
        return (
            <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-16 text-center">
                <p className="text-sm text-zinc-500">No channel data for this period.</p>
            </div>
        );
    }

    const attrLabel = attributionModel === 'first_touch' ? 'first touch' : 'last touch';

    return (
        <div className="rounded-xl border border-zinc-200 bg-white overflow-x-auto">
            <table className="w-full min-w-[900px] text-sm">
                <thead>
                    <tr className="border-b border-zinc-100">
                        {/* Sticky channel column */}
                        <th className="sticky left-0 z-10 bg-white px-4 py-3 text-left text-[10px] font-medium uppercase tracking-wide text-zinc-400 min-w-[160px]">
                            Channel
                        </th>
                        <Th>Spend</Th>
                        <Th>Revenue</Th>
                        <Th>Real Profit</Th>
                        <Th title={`Real revenue / spend — ${attrLabel} attribution`}>
                            Real ROAS
                        </Th>
                        <Th title="Platform-reported ROAS (includes modeled conversions). Δ = gap vs Real ROAS.">
                            Platform ROAS
                        </Th>
                        <Th title={`Ad spend / new customers — ${attrLabel} attribution`}>
                            CAC
                        </Th>
                        <Th title={`Revenue from first-time customers / spend — ${attrLabel}`}>
                            1st-Order ROAS
                        </Th>
                        <Th title="Revenue in the 30 days after customer acquisition / spend. Locks in 30 days after period end.">
                            Day-30 ROAS
                        </Th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-zinc-100">
                    {rows.map((row) => {
                        const key      = row.rollup_key ?? row.channel_type;
                        const isOpen   = expanded.has(key);
                        const children = row.children ?? [];
                        const color    = channelColor(row);
                        const profitCls = row.real_profit != null
                            ? row.real_profit >= 0 ? 'text-green-700' : 'text-red-600'
                            : '';

                        return [
                            // Parent row
                            <tr
                                key={key}
                                className={cn(
                                    'hover:bg-zinc-50 transition-colors',
                                    loading && 'opacity-40',
                                )}
                            >
                                {/* Sticky channel cell */}
                                <td className="sticky left-0 z-10 bg-white px-4 py-3 hover:bg-zinc-50">
                                    <div className="flex items-center gap-2">
                                        {/* Expand chevron — only when children exist */}
                                        <button
                                            onClick={() => children.length > 0 && toggleExpand(key)}
                                            className={cn(
                                                'h-4 w-4 shrink-0 text-zinc-400',
                                                children.length === 0 && 'invisible',
                                            )}
                                            aria-label={isOpen ? 'Collapse' : 'Expand'}
                                        >
                                            {isOpen
                                                ? <ChevronDown className="h-4 w-4" />
                                                : <ChevronRight className="h-4 w-4" />}
                                        </button>
                                        <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: color }} />
                                        <span className="font-medium text-zinc-800 truncate">{row.channel_name}</span>
                                        {row.wl_tag === 'winner' && (
                                            <span className="rounded-full bg-green-50 px-1.5 py-0.5 text-[10px] font-medium text-green-700 border border-green-200">W</span>
                                        )}
                                        {row.wl_tag === 'loser' && (
                                            <span className="rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-medium text-red-600 border border-red-200">L</span>
                                        )}
                                    </div>
                                </td>

                                <Td>{row.spend != null ? formatCurrency(row.spend, currency, true) : '—'}</Td>
                                <Td>{formatCurrency(row.revenue, currency, true)}</Td>
                                <Td className={profitCls}>
                                    {row.real_profit != null ? formatCurrency(row.real_profit, currency, true) : '—'}
                                </Td>
                                <Td>{row.real_roas != null ? `${row.real_roas}x` : '—'}</Td>
                                <Td>
                                    {row.platform_roas != null ? (
                                        <span className="text-blue-600">
                                            {row.platform_roas}x
                                            <PlatformRoasDelta delta={row.platform_roas_delta_pct} />
                                        </span>
                                    ) : '—'}
                                </Td>
                                <Td>{row.cac != null ? formatCurrency(row.cac, currency, true) : '—'}</Td>
                                <Td>{row.first_order_roas != null ? `${row.first_order_roas}x` : '—'}</Td>
                                <Td>
                                    {row.day_30_roas_pending ? (
                                        <span
                                            className="text-zinc-400 text-[11px]"
                                            title={`Locks in ${row.day_30_roas_locks_in ?? '?'} days — cohort window still open`}
                                        >
                                            pending
                                        </span>
                                    ) : row.day_30_roas != null ? (
                                        `${row.day_30_roas}x`
                                    ) : '—'}
                                </Td>
                            </tr>,

                            // Child rows (campaigns or queries)
                            ...(isOpen ? children.map((child) => (
                                <tr
                                    key={`${key}-child-${child.id}`}
                                    className="bg-zinc-50/60 hover:bg-zinc-50"
                                >
                                    <td className="sticky left-0 z-10 bg-zinc-50/60 px-4 py-2.5 hover:bg-zinc-50">
                                        <div className="flex items-center gap-2 pl-6">
                                            <div
                                                className="h-3 w-0.5 shrink-0 rounded-full"
                                                style={{ backgroundColor: color, opacity: 0.4 }}
                                            />
                                            <span className="text-xs text-zinc-600 truncate max-w-[200px]" title={child.name}>
                                                {child.name}
                                            </span>
                                        </div>
                                    </td>
                                    <Td className="text-xs">
                                        {child.spend != null ? formatCurrency(child.spend, currency, true) : '—'}
                                    </Td>
                                    <Td className="text-xs">
                                        {child.revenue != null ? formatCurrency(child.revenue, currency, true) : '—'}
                                    </Td>
                                    <Td className="text-xs">—</Td>
                                    <Td className="text-xs">
                                        {child.real_roas != null ? `${child.real_roas}x` : '—'}
                                    </Td>
                                    <Td className="text-xs text-blue-600">
                                        {child.platform_roas != null ? `${child.platform_roas}x` : '—'}
                                    </Td>
                                    <Td className="text-xs">—</Td>
                                    <Td className="text-xs">—</Td>
                                    <Td className="text-xs">—</Td>
                                </tr>
                            )) : []),
                        ];
                    })}
                </tbody>
            </table>
        </div>
    );
}
