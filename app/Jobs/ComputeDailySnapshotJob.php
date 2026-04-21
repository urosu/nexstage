<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class ComputeDailySnapshotJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout   = 600;
    public int $tries     = 3;
    public int $uniqueFor = 660;

    public function uniqueId(): string
    {
        return "{$this->storeId}:{$this->date->toDateString()}";
    }

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

        // Discard snapshot jobs queued before trial expiry.
        $ws = Workspace::withoutGlobalScopes()
            ->select(['id', 'trial_ends_at', 'billing_plan'])
            ->find($workspaceId);
        if ($ws && $ws->trial_ends_at !== null && $ws->trial_ends_at->lt(now()) && $ws->billing_plan === null) {
            Log::info('ComputeDailySnapshotJob: skipped — workspace trial expired', ['workspace_id' => $workspaceId]);
            return;
        }

        $dayStart = $dateStr . ' 00:00:00';
        $dayEnd   = $dateStr . ' 23:59:59';

        // A. Core order metrics ---------------------------------------------------
        $core = DB::table('orders')
            ->selectRaw('
                COUNT(*)::int                    AS orders_count,
                SUM(total_in_reporting_currency) AS revenue,
                SUM(total)                       AS revenue_native
            ')
            ->where('workspace_id', $workspaceId)
            ->where('store_id', $this->storeId)
            ->whereBetween('occurred_at', [$dayStart, $dayEnd])
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
            ->where('o.workspace_id', $workspaceId)
            ->where('o.store_id', $this->storeId)
            ->whereBetween('o.occurred_at', [$dayStart, $dayEnd])
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
                WHERE workspace_id = ?
                  AND store_id = ?
                  AND occurred_at BETWEEN ? AND ?
                  AND status IN ('completed','processing')
                  AND customer_email_hash IS NOT NULL
            ),
            first_appearances AS (
                SELECT dc.customer_email_hash, MIN(o.occurred_at::date) AS first_date
                FROM day_customers dc
                JOIN orders o
                  ON o.customer_email_hash = dc.customer_email_hash
                 AND o.workspace_id = ?
                 AND o.store_id = ?
                 AND o.status IN ('completed','processing')
                GROUP BY dc.customer_email_hash
            )
            SELECT
                SUM(CASE WHEN first_date = ? THEN 1 ELSE 0 END)::int AS new_customers,
                SUM(CASE WHEN first_date  < ? THEN 1 ELSE 0 END)::int AS returning_customers
            FROM first_appearances
        ", [$workspaceId, $this->storeId, $dayStart, $dayEnd, $workspaceId, $this->storeId, $dateStr, $dateStr]);

        $newCustomers       = (int) ($customerStats->new_customers ?? 0);
        $returningCustomers = (int) ($customerStats->returning_customers ?? 0);

        // D. Top 50 products — written to daily_snapshot_products (normalized table) ----------
        // Why: daily_snapshots.top_products JSONB was dropped in favour of this normalized
        // table so Phase 1 can query with proper filtering, sorting, and trending deltas.
        // See: PLANNING.md "daily_snapshot_products"
        // Related: app/Models/DailySnapshotProduct.php
        $productRows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->selectRaw("
                oi.product_external_id,
                MAX(oi.product_name)                                                       AS product_name,
                SUM(oi.quantity)::int                                                      AS units,
                SUM(oi.line_total * (o.total_in_reporting_currency / NULLIF(o.total, 0))) AS revenue
            ")
            ->where('o.workspace_id', $workspaceId)
            ->where('o.store_id', $this->storeId)
            ->whereBetween('o.occurred_at', [$dayStart, $dayEnd])
            ->whereIn('o.status', ['completed', 'processing'])
            ->whereNotNull('o.total_in_reporting_currency')
            ->groupBy('oi.product_external_id')
            ->orderByDesc('revenue')
            ->limit(50)
            ->get();

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
                'created_at'          => $now,
                'updated_at'          => $now,
            ]],
            ['store_id', 'date'],
            [
                'orders_count', 'revenue', 'revenue_native', 'aov',
                'items_sold', 'items_per_order', 'new_customers', 'returning_customers',
                'updated_at',
            ],
        );

        // Upsert top products into normalized daily_snapshot_products ----------------
        if ($productRows->isNotEmpty()) {
            $productUpsertRows = $productRows->values()->map(function ($row, int $idx) use ($workspaceId, $dateStr, $now): array {
                return [
                    'workspace_id'        => $workspaceId,
                    'store_id'            => $this->storeId,
                    'snapshot_date'       => $dateStr,
                    'product_external_id' => $row->product_external_id,
                    'product_name'        => mb_substr((string) $row->product_name, 0, 500),
                    'revenue'             => round((float) $row->revenue, 4),
                    'units'               => (int) $row->units,
                    'rank'                => $idx + 1,
                    'created_at'          => $now,
                ];
            })->all();

            DB::table('daily_snapshot_products')->upsert(
                $productUpsertRows,
                ['store_id', 'snapshot_date', 'product_external_id'],
                ['product_name', 'revenue', 'units', 'rank'],
            );

            // Populate stock state from the current products row.
            // Why: daily_snapshot_products records historical stock per day so
            // DetectStockTransitionsJob (Phase 1.6) can diff consecutive days and
            // DaysOfCover computations can weight by stock availability.
            // stock_status / stock_quantity reflect the product's state at snapshot
            // time (i.e. "end of day" as of the nightly job run).
            // A separate UPDATE is used rather than embedding in the upsert so we
            // don't need to fetch stock values into PHP — the DB join is cheaper.
            DB::statement("
                UPDATE daily_snapshot_products dsp
                SET stock_status   = p.stock_status,
                    stock_quantity = p.stock_quantity
                FROM products p
                WHERE p.store_id             = dsp.store_id
                  AND p.external_id          = dsp.product_external_id
                  AND dsp.store_id           = ?
                  AND dsp.snapshot_date      = ?
            ", [$this->storeId, $dateStr]);
        }

        Log::info('ComputeDailySnapshotJob: snapshot computed', [
            'store_id'     => $this->storeId,
            'date'         => $dateStr,
            'orders_count' => $ordersCount,
        ]);
    }
}
