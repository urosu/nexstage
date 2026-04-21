/**
 * BreakdownView — full implementation (Phase 1.4).
 *
 * Spec: PLANNING.md "BreakdownView Component Architecture"
 * Related: resources/js/Pages/Campaigns/Index.tsx, Analytics/Products.tsx, Stores/Index.tsx
 *
 * Interaction model:
 *   breakdownBy (how rows are grouped) × cardData (which channel's metrics are shown)
 *   are two ORTHOGONAL AXES — not one selector.
 *
 * Three view modes:
 *   Cards  — grid of row cards, each showing the item label + key metrics
 *   Table  — sortable table with all metric columns (default)
 *   Graph  — horizontal bar chart for a selected metric
 *
 * Filter chips (shown only when `isWinner` predicate is provided):
 *   All | Winners | Losers
 *
 * State persistence: when `viewKey` is set, each state change is debounced
 * and saved to users.view_preferences via PATCH /settings/view-preferences.
 * Initial state is restored from usePage().props.auth.user.view_preferences[viewKey].
 */

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    BarChart as RechartsBarChart,
    LineChart as RechartsLineChart,
    Bar,
    Line,
    XAxis,
    YAxis,
    Tooltip,
    CartesianGrid,
    ResponsiveContainer,
    Cell,
} from 'recharts';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Grid2X2,
    LayoutList,
    BarChart2,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatCurrency, formatNumber, formatPercent } from '@/lib/formatters';
import { MetricSource, SourceBadge } from './MetricCard';
import type { PageProps } from '@/types';

// ---------------------------------------------------------------------------
// Public types — also re-export the stub types so consumers don't change imports
// ---------------------------------------------------------------------------

/** How rows are grouped in the view. */
export type BreakdownDimension = 'product' | 'country' | 'campaign' | 'advertiser' | 'date';

/** Which channel's metrics the cards display. 'all' = multi-source composite. */
export type BreakdownCardData = MetricSource | 'all';

/** Which visual layout is active. */
export type BreakdownViewMode = 'cards' | 'table' | 'graph';

/**
 * A single row in the BreakdownView dataset.
 * Columns are defined by BreakdownColumn[], not hardcoded.
 */
export interface BreakdownRow {
    /** Unique identifier for this row (external ID, country code, date string, etc.). */
    id: string | number;
    /** Display label for the breakdown dimension value. */
    label: string;
    /** Arbitrary metric columns — keyed by metric name, value may be null when no data. */
    metrics: Record<string, number | null>;
    /** Winner/loser classification for the W/L highlight feature. */
    wl_tag?: 'winner' | 'loser' | null;
    /** Optional sub-rows for hierarchical breakdowns (Phase 3+). */
    children?: BreakdownRow[];
}

/**
 * Column definition — tells BreakdownView how to format and display each metric.
 */
export interface BreakdownColumn {
    /** Matches a key in BreakdownRow.metrics. */
    key: string;
    /** Human-readable column header. */
    label: string;
    /**
     * How to format the value for display.
     * - 'percent_plain' renders X.X% without a +/- sign (for share/rate columns, not deltas).
     */
    format: 'currency' | 'number' | 'percent' | 'percent_plain' | 'multiplier' | 'raw';
    /** Currency code — only needed when format='currency'. Defaults to the view's `currency` prop. */
    currency?: string;
    /** Lower is better (e.g. CPO, CPC). Used for trend coloring in table cells. */
    invertTrend?: boolean;
    /** Show this column on row cards in Cards view. Default: true for first 4 columns. */
    showInCards?: boolean;
    /**
     * Mark this column as the "change" column — its value is treated as a % delta
     * and rendered with the TrendBadge color logic (green/red).
     */
    isChangePct?: boolean;
}

/**
 * Optional sort/filter controls. When omitted BreakdownView manages these internally.
 * Phase 1.4: all state is managed internally with view_preferences persistence.
 */
export interface BreakdownControls {
    filter?: 'winners' | 'losers' | 'all';
    orderBy?: string;
    orderDir?: 'asc' | 'desc';
}

export interface BreakdownViewProps {
    /** How rows are grouped (e.g. by campaign, by product, by country). */
    breakdownBy: BreakdownDimension;
    /** Which data source's metrics are shown on cards. */
    cardData: BreakdownCardData;
    /** Column definitions — drive formatting, table headers, and card display. */
    columns: BreakdownColumn[];
    /** The data rows to display. */
    data: BreakdownRow[];
    /** Initial view mode (overridden by saved view_preferences when viewKey is set). */
    defaultView?: BreakdownViewMode;
    /** Key used to persist state in users.view_preferences JSONB. Set to page name. */
    viewKey?: string;
    /**
     * Predicate that determines if a row is a "winner".
     * When provided, Winners / Losers filter chips are shown.
     * Returns true = winner, false/null = loser.
     *
     * Example for campaigns:
     *   isWinner={(row) => (row.metrics.real_roas ?? 0) >= (workspace.target_roas ?? 0)}
     *
     * Example for products (top 10 by revenue delta):
     *   isWinner={(row) => topTenIds.has(row.id)}
     */
    isWinner?: (row: BreakdownRow) => boolean;
    /** Reporting currency — used for currency columns that don't specify their own. */
    currency?: string;
    /** Loading state — render skeletons instead of rows. */
    loading?: boolean;
    /** Called when view mode changes (in addition to view_preferences persistence). */
    onViewChange?: (mode: BreakdownViewMode) => void;
    /** Empty state message when data is empty after filtering. */
    emptyMessage?: string;
    /**
     * Called when a row is clicked in table or cards view.
     * Use for drill-downs or row selection (e.g., select a country to load top products).
     */
    onRowClick?: (row: BreakdownRow) => void;
    /**
     * Highlights the row whose id matches this value.
     * Typically set to the currently-selected drill-down item.
     */
    selectedId?: string | number;
    /**
     * Renders extra `<td>` cells at the end of each table row, after all metric columns.
     * Only rendered in table view. Use for bespoke interactive cells (e.g. inline note editor).
     */
    renderRowSuffix?: (row: BreakdownRow) => React.ReactNode;
    /**
     * Column header label for the suffix cell(s) added by renderRowSuffix.
     * Adds a matching `<th>` in the table header row.
     */
    suffixColumnLabel?: string;
    /**
     * Default sort key (must match a key in BreakdownColumn or a key present in row.metrics).
     * Overrides the default of columns[0].key. Useful for hidden sort keys like `date_ts`.
     */
    defaultSortBy?: string;
    /**
     * Default sort direction. Defaults to 'desc'.
     */
    defaultSortDir?: 'asc' | 'desc';
    /**
     * Returns extra Tailwind classes for a given row — use for per-row colour coding
     * (e.g. winner/loser highlighting). Applied before the selectedId override so
     * selection always wins.
     */
    getRowClassName?: (row: BreakdownRow) => string | undefined;
    /**
     * Content rendered on the left side of the BreakdownView toolbar.
     * Use for page-level filter chips (e.g. All / Winners / Losers) when
     * the isWinner predicate is not provided (server-side filtering).
     */
    leftSlot?: React.ReactNode;
}

// ---------------------------------------------------------------------------
// Value formatter
// ---------------------------------------------------------------------------

function formatValue(
    value: number | null | undefined,
    col: BreakdownColumn,
    fallbackCurrency?: string,
): string {
    if (value === null || value === undefined) return '—';
    const currency = col.currency ?? fallbackCurrency ?? 'EUR';
    switch (col.format) {
        case 'currency':
            return formatCurrency(value, currency, true);
        case 'number':
            return formatNumber(value, true);
        case 'percent':
            return `${value >= 0 ? '+' : ''}${value.toFixed(1)}%`;
        case 'percent_plain':
            return `${value.toFixed(1)}%`;
        case 'multiplier':
            return `${value.toFixed(2)}×`;
        case 'raw':
        default:
            return String(value);
    }
}

// ---------------------------------------------------------------------------
// Sort icon
// ---------------------------------------------------------------------------

function SortIcon({ col, sortBy, sortDir }: { col: string; sortBy: string; sortDir: 'asc' | 'desc' }) {
    if (col !== sortBy) return <ArrowUpDown className="ml-1 h-3 w-3 opacity-30" />;
    return sortDir === 'asc'
        ? <ArrowUp className="ml-1 h-3 w-3 text-primary" />
        : <ArrowDown className="ml-1 h-3 w-3 text-primary" />;
}

// ---------------------------------------------------------------------------
// Row card — used in Cards view
// ---------------------------------------------------------------------------

function RowCard({
    row,
    columns,
    cardData,
    currency,
    isSelected,
    onClick,
    className,
}: {
    row: BreakdownRow;
    columns: BreakdownColumn[];
    cardData: BreakdownCardData;
    currency?: string;
    isSelected?: boolean;
    onClick?: (row: BreakdownRow) => void;
    className?: string;
}) {
    // Show first 4 columns marked showInCards (or all if showInCards is not set)
    const cardCols = columns
        .filter(c => c.showInCards !== false)
        .slice(0, 4);

    return (
        <div
            className={cn(
                'rounded-xl border p-4 space-y-2 transition-shadow',
                onClick && 'cursor-pointer',
                isSelected
                    ? 'border-primary/40 bg-primary/5 shadow-sm'
                    : cn('border-zinc-200 bg-white hover:shadow-sm', className),
            )}
            onClick={() => onClick?.(row)}
        >
            <div className="flex items-start justify-between gap-2 min-w-0">
                <p className="text-sm font-medium text-zinc-900 truncate leading-snug">{row.label}</p>
                {cardData !== 'all' && <SourceBadge source={cardData as MetricSource} />}
            </div>
            <div className={cn('grid gap-x-4 gap-y-2', cardCols.length > 2 ? 'grid-cols-2' : 'grid-cols-1')}>
                {cardCols.map(col => {
                    const val = row.metrics[col.key];
                    const isChange = col.isChangePct;
                    const isPositive = isChange && val !== null && val !== undefined && (col.invertTrend ? val < 0 : val > 0);
                    const isNegative = isChange && val !== null && val !== undefined && (col.invertTrend ? val > 0 : val < 0);
                    return (
                        <div key={col.key}>
                            <p className="text-[10px] uppercase tracking-wide text-zinc-400">{col.label}</p>
                            <p className={cn(
                                'text-sm font-semibold tabular-nums leading-snug',
                                isPositive ? 'text-green-600' : isNegative ? 'text-red-600' : 'text-zinc-900',
                            )}>
                                {formatValue(val, col, currency)}
                            </p>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}


// ---------------------------------------------------------------------------
// Date line chart — multi-series line chart used when breakdownBy='date'
// ---------------------------------------------------------------------------

function DateLineChart({
    rows,
    columns,
    currency,
    highlightWL,
}: {
    rows: BreakdownRow[];
    columns: BreakdownColumn[];
    currency?: string;
    highlightWL?: boolean;
}) {
    const numericCols = columns.filter(c => !c.isChangePct);

    // Default: first two columns active (typically ad_spend + revenue)
    const [visible, setVisible] = useState<Set<string>>(
        () => new Set(numericCols.slice(0, 2).map(c => c.key)),
    );

    function toggle(key: string) {
        setVisible(prev => {
            const next = new Set(prev);
            if (next.has(key)) {
                if (next.size === 1) return prev; // keep at least one line
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    }

    // Sort chronologically — rows may arrive sorted desc by date_ts
    const chartData = useMemo(() => {
        const sorted = [...rows].sort((a, b) => {
            const aTs = (a.metrics.date_ts as number) ?? 0;
            const bTs = (b.metrics.date_ts as number) ?? 0;
            return aTs - bTs;
        });
        return sorted.map(row => ({
            label: row.label,
            wl_tag: row.wl_tag ?? null,
            ...Object.fromEntries(numericCols.map(c => [c.key, row.metrics[c.key]])),
        }));
    }, [rows, numericCols]);

    const visibleCols     = numericCols.filter(c => visible.has(c.key));
    const hasCurrency     = visibleCols.some(c => c.format === 'currency');
    const hasNonCurrency  = visibleCols.some(c => c.format !== 'currency');
    const dualAxis        = hasCurrency && hasNonCurrency;
    // Log scale when multiple currency series are visible — e.g. Revenue (€10k) + AOV (€50)
    // would flatten AOV to near-zero on a linear axis.
    const multipleLeft    = visibleCols.filter(c => c.format === 'currency').length > 1;

    // Representative column for each axis — drives tick formatting
    const leftCol  = dualAxis ? visibleCols.find(c => c.format === 'currency')! : visibleCols[0];
    const rightCol = dualAxis ? visibleCols.find(c => c.format !== 'currency')  : undefined;

    function getAxisId(col: BreakdownColumn): 'left' | 'right' {
        return dualAxis && col.format !== 'currency' ? 'right' : 'left';
    }

    return (
        <div className="space-y-3">
            {/* Series toggle pills */}
            <div className="flex flex-wrap gap-1.5">
                {numericCols.map((col, i) => {
                    const on    = visible.has(col.key);
                    const color = `var(--chart-${i + 1})`;
                    return (
                        <button
                            key={col.key}
                            onClick={() => toggle(col.key)}
                            className={cn(
                                'flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                                !on && 'border-zinc-200 bg-white text-zinc-400 hover:text-zinc-600',
                            )}
                            style={on ? { backgroundColor: color, borderColor: color, color: 'white' } : undefined}
                        >
                            <span
                                className="h-1.5 w-1.5 rounded-full"
                                style={{ backgroundColor: on ? 'white' : color }}
                            />
                            {col.label}
                        </button>
                    );
                })}
            </div>

            <div className="h-64">
                <ResponsiveContainer width="100%" height="100%">
                    <RechartsLineChart
                        data={chartData}
                        margin={{ top: 4, right: dualAxis ? 56 : 8, left: 4, bottom: 0 }}
                    >
                        <CartesianGrid strokeDasharray="3 3" stroke="#f4f4f5" vertical={false} />
                        <XAxis
                            dataKey="label"
                            tickLine={false}
                            axisLine={false}
                            tick={{ fontSize: 11, fill: '#a1a1aa' }}
                            minTickGap={48}
                        />
                        <YAxis
                            yAxisId="left"
                            orientation="left"
                            scale={multipleLeft ? 'log' : 'linear'}
                            domain={multipleLeft ? [1, 'auto'] : [0, 'auto']}
                            allowDataOverflow={multipleLeft}
                            tickLine={false}
                            axisLine={false}
                            tick={{ fontSize: 11, fill: '#a1a1aa' }}
                            tickFormatter={(v: number) => leftCol ? formatValue(v, leftCol, currency) : String(v)}
                            width={60}
                        />
                        {dualAxis && rightCol && (
                            <YAxis
                                yAxisId="right"
                                orientation="right"
                                tickLine={false}
                                axisLine={false}
                                tick={{ fontSize: 11, fill: '#a1a1aa' }}
                                tickFormatter={(v: number) => formatValue(v, rightCol, currency)}
                                width={44}
                            />
                        )}
                        <Tooltip
                            contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e4e4e7' }}
                            formatter={(value: unknown, key: unknown) => {
                                const col = numericCols.find(c => c.key === String(key));
                                if (!col) return [String(value), String(key)];
                                return [formatValue(value as number, col, currency), col.label] as [string, string];
                            }}
                        />
                        {numericCols.map((col, i) =>
                            visible.has(col.key) ? (
                                <Line
                                    key={col.key}
                                    yAxisId={getAxisId(col)}
                                    type="monotone"
                                    dataKey={col.key}
                                    stroke={`var(--chart-${i + 1})`}
                                    strokeWidth={2}
                                    connectNulls
                                    dot={highlightWL
                                        ? (props: { cx?: number; cy?: number; payload?: { wl_tag?: string | null } }) => {
                                            const { cx = 0, cy = 0, payload } = props;
                                            const tag = payload?.wl_tag;
                                            if (tag === 'winner') return <circle key={`${col.key}-${cx}`} cx={cx} cy={cy} r={4} fill="#10b981" stroke="white" strokeWidth={1.5} />;
                                            if (tag === 'loser')  return <circle key={`${col.key}-${cx}`} cx={cx} cy={cy} r={4} fill="#ef4444" stroke="white" strokeWidth={1.5} />;
                                            return <g key={`${col.key}-${cx}`} />;
                                        }
                                        : false
                                    }
                                />
                            ) : null,
                        )}
                    </RechartsLineChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Graph view — horizontal bar chart for a single metric
// ---------------------------------------------------------------------------

function GraphView({
    rows,
    columns,
    currency,
}: {
    rows: BreakdownRow[];
    columns: BreakdownColumn[];
    currency?: string;
}) {
    const numericCols = columns.filter(c => !c.isChangePct);
    const [graphMetric, setGraphMetric] = useState(numericCols[0]?.key ?? '');

    const col = columns.find(c => c.key === graphMetric) ?? columns[0];

    // Take top 20 rows for readability
    const chartData = rows.slice(0, 20).map(row => ({
        name: row.label.length > 24 ? row.label.slice(0, 22) + '…' : row.label,
        value: row.metrics[graphMetric] ?? 0,
    }));

    if (!col) return null;

    return (
        <div className="space-y-3">
            {numericCols.length > 1 && (
                <div className="flex gap-2 flex-wrap">
                    {numericCols.map(c => (
                        <button
                            key={c.key}
                            onClick={() => setGraphMetric(c.key)}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                graphMetric === c.key
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 hover:text-zinc-700',
                            )}
                        >
                            {c.label}
                        </button>
                    ))}
                </div>
            )}
            <div className="h-[320px] w-full">
                <ResponsiveContainer width="100%" height="100%">
                    <RechartsBarChart
                        data={chartData}
                        layout="vertical"
                        margin={{ top: 4, right: 16, bottom: 4, left: 8 }}
                    >
                        <XAxis
                            type="number"
                            tick={{ fontSize: 11 }}
                            tickFormatter={(v: number) => formatValue(v, col, currency)}
                            width={60}
                        />
                        <YAxis
                            type="category"
                            dataKey="name"
                            tick={{ fontSize: 11 }}
                            width={120}
                        />
                        <Tooltip
                            formatter={(value) => [formatValue(value as number, col, currency), col.label]}
                            contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e4e4e7' }}
                        />
                        <Bar dataKey="value" radius={[0, 4, 4, 0]}>
                            {chartData.map((_, i) => (
                                <Cell key={i} fill="#6366f1" fillOpacity={0.8} />
                            ))}
                        </Bar>
                    </RechartsBarChart>
                </ResponsiveContainer>
            </div>
            {rows.length > 20 && (
                <p className="text-xs text-zinc-400 text-center">Showing top 20 of {rows.length} rows</p>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// BreakdownView
// ---------------------------------------------------------------------------

/**
 * Full implementation of the BreakdownView — Cards / Table / Graph triplet.
 * See: PLANNING.md "BreakdownView Component Architecture (Phase 1.4 build)"
 */
export function BreakdownView({
    breakdownBy,
    cardData,
    columns,
    data,
    defaultView = 'table',
    viewKey,
    isWinner,
    currency,
    loading = false,
    onViewChange,
    emptyMessage = 'No data for this period.',
    onRowClick,
    selectedId,
    renderRowSuffix,
    suffixColumnLabel,
    defaultSortBy,
    defaultSortDir,
    getRowClassName,
    leftSlot,
}: BreakdownViewProps) {
    const { auth } = usePage<PageProps>().props;
    const savedPrefs = viewKey ? (auth.user?.view_preferences?.[viewKey] ?? {}) : {};

    // URL ?filter param takes priority over saved prefs (used by sidebar deep-links).
    const urlFilterParam = typeof window !== 'undefined'
        ? (new URLSearchParams(window.location.search).get('filter') as 'all' | 'winners' | 'losers' | null)
        : null;

    // --- State (URL param > view_preferences > defaults) ---
    const [viewMode, setViewMode] = useState<BreakdownViewMode>(
        (savedPrefs.view as BreakdownViewMode) ?? defaultView,
    );
    const [filter, setFilter] = useState<'all' | 'winners' | 'losers'>(
        urlFilterParam ?? (savedPrefs.filter as 'all' | 'winners' | 'losers') ?? 'all',
    );
    const [sortBy, setSortBy] = useState<string>(
        savedPrefs.sort_by ?? defaultSortBy ?? (columns[0]?.key ?? ''),
    );
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>(
        (savedPrefs.sort_dir as 'asc' | 'desc') ?? defaultSortDir ?? 'desc',
    );

    // --- Persist to view_preferences (debounced) ---
    const persistTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);
    const persistPrefs = useCallback(
        (prefs: Record<string, string>) => {
            if (!viewKey) return;
            if (persistTimeout.current) clearTimeout(persistTimeout.current);
            persistTimeout.current = setTimeout(() => {
                // Merge under the viewKey namespace
                axios.patch('/settings/view-preferences', {
                    preferences: { [viewKey]: prefs },
                }).catch(() => {
                    // Silently swallow — preference persistence is best-effort.
                    // The UI state is already correct; only the server persistence failed.
                });
            }, 500);
        },
        [viewKey],
    );

    // Call persistPrefs whenever relevant state changes
    useEffect(() => {
        persistPrefs({ view: viewMode, filter, sort_by: sortBy, sort_dir: sortDir });
    }, [viewMode, filter, sortBy, sortDir, persistPrefs]);

    // --- Sort handler ---
    function toggleSort(col: string) {
        if (col === sortBy) {
            setSortDir(d => (d === 'desc' ? 'asc' : 'desc'));
        } else {
            setSortBy(col);
            setSortDir('desc');
        }
    }

    // --- View mode handler ---
    function changeView(mode: BreakdownViewMode) {
        setViewMode(mode);
        onViewChange?.(mode);
    }

    // --- Filtered + sorted rows ---
    const displayRows = useMemo(() => {
        let rows = [...data];

        // Apply winner/loser filter
        if (filter !== 'all' && isWinner) {
            rows = rows.filter(row => (filter === 'winners' ? isWinner(row) : !isWinner(row)));
        }

        // Sort — nulls always last
        const dir = sortDir === 'desc' ? -1 : 1;
        rows.sort((a, b) => {
            const av = a.metrics[sortBy];
            const bv = b.metrics[sortBy];
            if (av === null || av === undefined) return 1;
            if (bv === null || bv === undefined) return -1;
            return (av - bv) * dir;
        });

        return rows;
    }, [data, filter, sortBy, sortDir, isWinner]);

    // --- Skeleton loading state ---
    if (loading) {
        return (
            <div className="space-y-2">
                {[...Array(5)].map((_, i) => (
                    <div key={i} className="h-10 rounded-lg bg-zinc-100 animate-pulse" />
                ))}
            </div>
        );
    }

    const showFilterChips = !!isWinner;
    const cardColumns = columns.filter(c => c.showInCards !== false);

    return (
        <div className="space-y-3">
            {/* ── Toolbar ─────────────────────────────────────────────────── */}
            <div className="flex items-center justify-between gap-3 flex-wrap">
                {/* Left: filter chips (only when isWinner is provided) */}
                {showFilterChips ? (
                    <div className="flex items-center gap-1">
                        {(['all', 'winners', 'losers'] as const).map(f => (
                            <button
                                key={f}
                                onClick={() => setFilter(f)}
                                className={cn(
                                    'rounded-full border px-3 py-1 text-xs font-medium transition-colors capitalize',
                                    filter === f
                                        ? f === 'winners'
                                            ? 'border-green-300 bg-green-50 text-green-700'
                                            : f === 'losers'
                                            ? 'border-red-300 bg-red-50 text-red-700'
                                            : 'border-primary bg-primary/10 text-primary'
                                        : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 hover:text-zinc-700',
                                )}
                            >
                                {f === 'all' ? 'All' : f === 'winners' ? '🏆 Winners' : '📉 Losers'}
                            </button>
                        ))}
                        {filter !== 'all' && (
                            <span className="ml-1 text-xs text-zinc-400">
                                {displayRows.length} result{displayRows.length !== 1 ? 's' : ''}
                            </span>
                        )}
                    </div>
                ) : leftSlot ? (
                    <div>{leftSlot}</div>
                ) : (
                    <div />
                )}

                {/* Right: view mode toggle */}
                <div className="flex items-center gap-0.5 rounded-lg border border-zinc-200 bg-zinc-50 p-0.5">
                    {(
                        [
                            { mode: 'table' as const, icon: <LayoutList className="h-3.5 w-3.5" />, label: 'Table' },
                            { mode: 'cards' as const, icon: <Grid2X2 className="h-3.5 w-3.5" />, label: 'Cards' },
                            { mode: 'graph' as const, icon: <BarChart2 className="h-3.5 w-3.5" />, label: 'Chart' },
                        ] as const
                    ).map(({ mode, icon, label }) => (
                        <button
                            key={mode}
                            onClick={() => changeView(mode)}
                            title={label}
                            className={cn(
                                'flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium transition-colors',
                                viewMode === mode
                                    ? 'bg-white text-zinc-900 shadow-sm'
                                    : 'text-zinc-400 hover:text-zinc-600',
                            )}
                        >
                            {icon}
                            <span className="hidden sm:inline">{label}</span>
                        </button>
                    ))}
                </div>
            </div>

            {/* ── Empty state ─────────────────────────────────────────────── */}
            {displayRows.length === 0 && (
                <div className="rounded-xl border border-zinc-100 bg-zinc-50 px-6 py-12 text-center text-sm text-zinc-400">
                    {filter !== 'all'
                        ? `No ${filter} found for the current period.`
                        : emptyMessage}
                </div>
            )}

            {/* ── Cards view ──────────────────────────────────────────────── */}
            {viewMode === 'cards' && displayRows.length > 0 && (
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {displayRows.map(row => (
                        <RowCard
                            key={row.id}
                            row={row}
                            columns={cardColumns}
                            cardData={cardData}
                            currency={currency}
                            isSelected={row.id === selectedId}
                            onClick={onRowClick}
                            className={getRowClassName?.(row)}
                        />
                    ))}
                </div>
            )}

            {/* ── Table view ──────────────────────────────────────────────── */}
            {viewMode === 'table' && displayRows.length > 0 && (
                <div className="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 z-10 bg-zinc-50">
                            <tr className="border-b border-zinc-100 text-left">
                                <th className="px-4 py-3 font-medium text-zinc-400 min-w-[160px]">
                                    {breakdownBy.charAt(0).toUpperCase() + breakdownBy.slice(1)}
                                </th>
                                {columns.map(col => (
                                    <th
                                        key={col.key}
                                        className="px-4 py-3 font-medium text-zinc-400 text-right whitespace-nowrap cursor-pointer select-none hover:text-zinc-700 transition-colors"
                                        onClick={() => toggleSort(col.key)}
                                    >
                                        <span className="inline-flex items-center justify-end gap-0.5">
                                            {col.label}
                                            <SortIcon col={col.key} sortBy={sortBy} sortDir={sortDir} />
                                        </span>
                                    </th>
                                ))}
                                {suffixColumnLabel && (
                                    <th className="px-4 py-3 font-medium text-zinc-400 text-left whitespace-nowrap min-w-[180px]">
                                        {suffixColumnLabel}
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-50">
                            {displayRows.map(row => (
                                <tr
                                    key={row.id}
                                    className={cn(
                                        'transition-colors',
                                        onRowClick && 'cursor-pointer',
                                        getRowClassName?.(row),
                                        row.id === selectedId
                                            ? 'bg-primary/10 ring-1 ring-inset ring-primary/20'
                                            : 'hover:bg-zinc-50',
                                    )}
                                    onClick={() => onRowClick?.(row)}
                                >
                                    <td className="px-4 py-3.5 font-medium max-w-[240px] truncate text-zinc-800"
                                        style={{ color: row.id === selectedId ? 'var(--color-primary)' : undefined }}
                                    >
                                        {row.label}
                                    </td>
                                    {columns.map(col => {
                                        const val = row.metrics[col.key];
                                        const isChange = col.isChangePct;
                                        const isPositive = isChange && val !== null && val !== undefined && (col.invertTrend ? val < 0 : val > 0);
                                        const isNegative = isChange && val !== null && val !== undefined && (col.invertTrend ? val > 0 : val < 0);
                                        return (
                                            <td
                                                key={col.key}
                                                className={cn(
                                                    'px-4 py-3.5 text-right tabular-nums whitespace-nowrap',
                                                    isPositive
                                                        ? 'text-green-600'
                                                        : isNegative
                                                        ? 'text-red-600'
                                                        : 'text-zinc-700',
                                                )}
                                            >
                                                {formatValue(val, col, currency)}
                                            </td>
                                        );
                                    })}
                                    {renderRowSuffix && renderRowSuffix(row)}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* ── Graph view ──────────────────────────────────────────────── */}
            {viewMode === 'graph' && displayRows.length > 0 && (
                <div className="rounded-xl border border-zinc-200 bg-white p-4">
                    {breakdownBy === 'date'
                        ? <DateLineChart rows={displayRows} columns={columns} currency={currency} highlightWL={!!getRowClassName} />
                        : <GraphView rows={displayRows} columns={columns} currency={currency} />
                    }
                </div>
            )}
        </div>
    );
}
