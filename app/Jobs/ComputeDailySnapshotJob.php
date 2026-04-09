<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computes (or recomputes) the daily_snapshots row for a single store + date.
 *
 * Queue:   low
 * Timeout: 600 s
 * Tries:   3
 * Backoff: default [60, 300, 900] s
 *
 * Idempotent: uses INSERT … ON CONFLICT (store_id, date) DO UPDATE so it is
 * safe to dispatch multiple times for the same store + date.
 *
 * Dispatched by:
 *  - DispatchDailySnapshots (nightly 00:30 UTC) — one job per active store for yesterday
 *  - WooCommerceHistoricalImportJob — one job per imported date after import completes
 *  - RecomputeReportingCurrencyJob — one job per existing snapshot date when currency changes
 *
 * Constructor params: (int $storeId, Carbon $date)
 */
class ComputeDailySnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 3;

    public function __construct(
        private readonly int    $storeId,
        private readonly Carbon $date,
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        $store = DB::table('stores')
            ->where('id', $this->storeId)
            ->select(['id', 'workspace_id'])
            ->first();

        if ($store === null) {
            Log::warning('ComputeDailySnapshotJob: store not found', [
                'store_id' => $this->storeId,
            ]);
            return;
        }

        $workspaceId = (int) $store->workspace_id;
        $dateStr     = $this->date->toDateString();

        app(WorkspaceContext::class)->set($workspaceId);

        // A. Core order metrics ---------------------------------------------------
        $core = DB::table('orders')
            ->selectRaw('
                COUNT(*)::int                    AS orders_count,
                SUM(total_in_reporting_currency) AS revenue,
                SUM(total)                       AS revenue_native
            ')
            ->where('store_id', $this->storeId)
            ->whereRaw("occurred_at::date = ?", [$dateStr])
            ->whereIn('status', ['completed', 'processing'])
            ->first();

        $ordersCount   = (int) ($core->orders_count ?? 0);
        $revenue       = $core->revenue !== null ? (float) $core->revenue : null;
        $revenueNative = (float) ($core->revenue_native ?? 0);
        $aov           = ($ordersCount > 0 && $revenue !== null)
            ? round($revenue / $ordersCount, 4)
            : null;

        // B. Items sold -----------------------------------------------------------
        $items = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->selectRaw('SUM(oi.quantity)::int AS items_sold')
            ->where('o.store_id', $this->storeId)
            ->whereRaw("o.occurred_at::date = ?", [$dateStr])
            ->whereIn('o.status', ['completed', 'processing'])
            ->first();

        $itemsSold      = (int) ($items->items_sold ?? 0);
        $itemsPerOrder  = ($ordersCount > 0)
            ? round($itemsSold / $ordersCount, 2)
            : null;

        // C. New vs returning customers -------------------------------------------
        $customerStats = DB::selectOne("
            WITH day_customers AS (
                SELECT DISTINCT customer_email_hash
                FROM orders
                WHERE store_id = ?
                  AND occurred_at::date = ?
                  AND status IN ('completed','processing')
                  AND customer_email_hash IS NOT NULL
            ),
            first_appearances AS (
                SELECT dc.customer_email_hash, MIN(o.occurred_at::date) AS first_date
                FROM day_customers dc
                JOIN orders o
                  ON o.customer_email_hash = dc.customer_email_hash
                 AND o.store_id = ?
                 AND o.status IN ('completed','processing')
                GROUP BY dc.customer_email_hash
            )
            SELECT
                SUM(CASE WHEN first_date = ? THEN 1 ELSE 0 END)::int AS new_customers,
                SUM(CASE WHEN first_date  < ? THEN 1 ELSE 0 END)::int AS returning_customers
            FROM first_appearances
        ", [$this->storeId, $dateStr, $this->storeId, $dateStr, $dateStr]);

        $newCustomers       = (int) ($customerStats->new_customers ?? 0);
        $returningCustomers = (int) ($customerStats->returning_customers ?? 0);

        // D. Revenue by country ---------------------------------------------------
        $countryRows = DB::table('orders')
            ->selectRaw('customer_country, SUM(total_in_reporting_currency) AS revenue')
            ->where('store_id', $this->storeId)
            ->whereRaw("occurred_at::date = ?", [$dateStr])
            ->whereIn('status', ['completed', 'processing'])
            ->whereNotNull('customer_country')
            ->whereNotNull('total_in_reporting_currency')
            ->groupBy('customer_country')
            ->orderByDesc('revenue')
            ->get();

        $revenueByCountry = $countryRows->isEmpty()
            ? null
            : $countryRows->mapWithKeys(fn ($row) => [
                $row->customer_country => round((float) $row->revenue, 4),
            ])->all();

        // E. Top 10 products ------------------------------------------------------
        $productRows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->selectRaw("
                oi.product_external_id,
                MAX(oi.product_name) AS product_name,
                SUM(oi.quantity)::int AS units,
                SUM(oi.line_total * (o.total_in_reporting_currency / NULLIF(o.total, 0))) AS revenue
            ")
            ->where('o.store_id', $this->storeId)
            ->whereRaw("o.occurred_at::date = ?", [$dateStr])
            ->whereIn('o.status', ['completed', 'processing'])
            ->whereNotNull('o.total_in_reporting_currency')
            ->groupBy('oi.product_external_id')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        $topProducts = $productRows->isEmpty()
            ? null
            : $productRows->map(fn ($row) => [
                'external_id' => $row->product_external_id,
                'name'        => $row->product_name,
                'units'       => (int) $row->units,
                'revenue'     => round((float) $row->revenue, 4),
            ])->values()->all();

        // Upsert ------------------------------------------------------------------
        $now = now()->toDateTimeString();

        DB::table('daily_snapshots')->upsert(
            [[
                'workspace_id'        => $workspaceId,
                'store_id'            => $this->storeId,
                'date'                => $dateStr,
                'orders_count'        => $ordersCount,
                'revenue'             => $revenue ?? 0,
                'revenue_native'      => $revenueNative,
                'aov'                 => $aov,
                'items_sold'          => $itemsSold,
                'items_per_order'     => $itemsPerOrder,
                'new_customers'       => $newCustomers,
                'returning_customers' => $returningCustomers,
                'revenue_by_country'  => $revenueByCountry !== null ? json_encode($revenueByCountry) : null,
                'top_products'        => $topProducts !== null ? json_encode($topProducts) : null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]],
            ['store_id', 'date'],
            [
                'orders_count', 'revenue', 'revenue_native', 'aov',
                'items_sold', 'items_per_order', 'new_customers', 'returning_customers',
                'revenue_by_country', 'top_products', 'updated_at',
            ],
        );

        Log::info('ComputeDailySnapshotJob: snapshot computed', [
            'store_id'     => $this->storeId,
            'date'         => $dateStr,
            'orders_count' => $ordersCount,
        ]);
    }
}
