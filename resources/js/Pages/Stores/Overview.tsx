import { useEffect, useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/utils';
import AppLayout from '@/Components/layouts/AppLayout';
import { StoreLayout } from '@/Components/layouts/StoreLayout';
import type { StoreData } from '@/Components/layouts/StoreLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { MetricCard } from '@/Components/shared/MetricCard';
import { LineChart } from '@/Components/charts/LineChart';
import { formatCurrency, formatNumber, type Granularity } from '@/lib/formatters';
import type { PageProps } from '@/types';

interface StoreMetrics {
    revenue: number;
    orders: number;
    aov: number | null;
    items_per_order: number | null;
    items_sold: number;
    new_customers: number;
}

interface ChartPoint {
    date: string;
    value: number;
}

interface NotePoint {
    date: string;
    note: string;
}

interface Props extends PageProps {
    store: StoreData;
    metrics: StoreMetrics;
    compare_metrics: StoreMetrics | null;
    chart_data: ChartPoint[];
    compare_chart_data: ChartPoint[] | null;
    has_null_fx: boolean;
    granularity: Granularity;
    notes: NotePoint[];
}

function pctChange(current: number | null, previous: number | null): number | null {
    if (current === null || previous === null || previous === 0) return null;
    return ((current - previous) / previous) * 100;
}

export default function StoreOverview({
    store,
    metrics,
    compare_metrics,
    chart_data,
    compare_chart_data,
    has_null_fx,
    granularity,
    notes,
}: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';
    const timezone = workspace?.reporting_timezone;

    const [navigating, setNavigating] = useState(false);
    const [showNotes, setShowNotes] = useState(true);

    useEffect(() => {
        const removeStart  = router.on('start',  () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));
        return () => { removeStart(); removeFinish(); };
    }, []);

    const changes = useMemo(() => ({
        revenue:         pctChange(metrics?.revenue         ?? null, compare_metrics?.revenue         ?? null),
        orders:          pctChange(metrics?.orders          ?? null, compare_metrics?.orders          ?? null),
        aov:             pctChange(metrics?.aov             ?? null, compare_metrics?.aov             ?? null),
        items_per_order: pctChange(metrics?.items_per_order ?? null, compare_metrics?.items_per_order ?? null),
        items_sold:      pctChange(metrics?.items_sold      ?? null, compare_metrics?.items_sold      ?? null),
        new_customers:   pctChange(metrics?.new_customers   ?? null, compare_metrics?.new_customers   ?? null),
    }), [metrics, compare_metrics]);

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title={`${store.name} — Overview`} />
            <StoreLayout store={store} activeTab="overview">
                {has_null_fx && (
                    <div className="mb-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            Some revenue figures may be incomplete — exchange rates were unavailable
                            for certain orders in this period. Affected orders are excluded from totals.
                        </span>
                    </div>
                )}

                {/* Metric cards */}
                <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                    <MetricCard
                        label="Revenue"
                        value={metrics ? formatCurrency(metrics.revenue, currency) : null}
                        change={changes.revenue}
                        loading={navigating}
                    />
                    <MetricCard
                        label="Orders"
                        value={metrics ? formatNumber(metrics.orders) : null}
                        change={changes.orders}
                        loading={navigating}
                    />
                    <MetricCard
                        label="AOV"
                        value={metrics?.aov != null ? formatCurrency(metrics.aov, currency) : null}
                        change={changes.aov}
                        loading={navigating}
                        tooltip="Average Order Value. Total revenue divided by number of completed and processing orders."
                    />
                    <MetricCard
                        label="Items / Order"
                        value={metrics?.items_per_order != null
                            ? metrics.items_per_order.toFixed(1)
                            : null}
                        change={changes.items_per_order}
                        loading={navigating}
                        tooltip="Average number of line items per order in the selected period."
                    />
                    <MetricCard
                        label="Items Sold"
                        value={metrics ? formatNumber(metrics.items_sold) : null}
                        change={changes.items_sold}
                        loading={navigating}
                        tooltip="Total quantity of individual items sold across all orders in the selected period."
                    />
                    <MetricCard
                        label="New Customers"
                        value={metrics ? formatNumber(metrics.new_customers) : null}
                        change={changes.new_customers}
                        loading={navigating}
                        tooltip="Customers whose email hash appears for the first time in this store. Based on SHA-256 hashed email addresses — no raw emails are stored."
                    />
                </div>

                {/* Revenue chart */}
                <div className="rounded-xl border border-zinc-200 bg-white p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <span className="text-sm font-medium text-zinc-500">Revenue over time</span>
                        {notes.length > 0 && (
                            <button
                                onClick={() => setShowNotes((v) => !v)}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
                                    showNotes
                                        ? 'border-amber-300 bg-amber-50 text-amber-700'
                                        : 'border-zinc-200 bg-white text-zinc-400 hover:text-zinc-600',
                                )}
                            >
                                <span className="h-2 w-2 rounded-full bg-amber-400" />
                                Notes
                            </button>
                        )}
                    </div>
                    {navigating ? (
                        <div className="h-64 w-full animate-pulse rounded-lg bg-zinc-100" />
                    ) : chart_data.length === 0 ? (
                        <div className="flex h-64 flex-col items-center justify-center gap-2 text-center">
                            <p className="text-sm text-zinc-400">No revenue data for this period.</p>
                            <p className="text-xs text-zinc-400">
                                Data appears once the nightly snapshot job has run.
                            </p>
                        </div>
                    ) : (
                        <LineChart
                            data={chart_data}
                            granularity={granularity}
                            currency={currency}
                            timezone={timezone}
                            comparisonData={compare_chart_data ?? undefined}
                            notes={showNotes ? notes : undefined}
                            seriesLabel="Revenue"
                            compareLabel="Previous period"
                            valueType="currency"
                            className="h-64 w-full"
                        />
                    )}
                </div>
            </StoreLayout>
        </AppLayout>
    );
}
