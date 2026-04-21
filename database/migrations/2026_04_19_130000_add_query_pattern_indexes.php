<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Additional indexes for the hottest query patterns found during performance audit.
 *
 * Partial indexes are used throughout so the planner can exploit exact WHERE shapes:
 *   - orders: status IN ('completed','processing') is present in every analytics query
 *   - ad_insights: level='campaign' AND hour IS NULL is the standard aggregation level
 * See PLANNING.md for relevant section references.
 */
return new class extends Migration
{
    public function up(): void
    {
        // orders: partial index matching the universal analytics filter.
        // Replaces the broader (workspace_id, occurred_at) index for all queries that
        // include status IN ('completed','processing'), which is every analytics path.
        DB::statement("CREATE INDEX IF NOT EXISTS idx_orders_ws_occurred_real
            ON orders (workspace_id, occurred_at)
            WHERE status IN ('completed', 'processing')");

        // orders: index for the new-customers NOT EXISTS subquery in DashboardController.
        // The subquery joins orders prev WHERE prev.customer_id = o.customer_id AND
        // prev.occurred_at < $from. Without this, Postgres scans the whole orders table
        // per customer.
        DB::statement("CREATE INDEX IF NOT EXISTS idx_orders_ws_customer_occurred
            ON orders (workspace_id, customer_id, occurred_at)
            WHERE customer_id IS NOT NULL");

        // orders: partial covering index for RevenueAttributionService::getAttributedRevenue.
        // Matches the exact WHERE shape: workspace_id + attribution_source + occurred_at +
        // status IN (...) + total_in_reporting_currency IS NOT NULL.
        // Supersedes the broader idx_orders_attribution_occurred added in the previous migration.
        DB::statement("CREATE INDEX IF NOT EXISTS idx_orders_attribution_occurred_real
            ON orders (workspace_id, attribution_source, occurred_at)
            WHERE status IN ('completed', 'processing')
              AND total_in_reporting_currency IS NOT NULL");

        // ad_insights: partial index for the campaign-level daily aggregation pattern.
        // Used by DashboardController, AnalyticsController, AcquisitionController,
        // GenerateAiSummaryJob, and every controller that sums ad spend.
        DB::statement("CREATE INDEX IF NOT EXISTS idx_ad_insights_ws_campaign_daily
            ON ad_insights (workspace_id, date)
            WHERE level = 'campaign' AND hour IS NULL");

        // order_items: covering index for the order→product join pattern used in
        // ComputeDailySnapshotJob, AnalyticsController::products, and AcquisitionController.
        DB::statement("CREATE INDEX IF NOT EXISTS idx_order_items_order_product
            ON order_items (order_id, product_external_id)");

        // holidays: country+date composite for Dashboard and PerformanceController holiday lookups.
        DB::statement("CREATE INDEX IF NOT EXISTS idx_holidays_country_date
            ON holidays (country_code, date)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_orders_ws_occurred_real');
        DB::statement('DROP INDEX IF EXISTS idx_orders_ws_customer_occurred');
        DB::statement('DROP INDEX IF EXISTS idx_orders_attribution_occurred_real');
        DB::statement('DROP INDEX IF EXISTS idx_ad_insights_ws_campaign_daily');
        DB::statement('DROP INDEX IF EXISTS idx_order_items_order_product');
        DB::statement('DROP INDEX IF EXISTS idx_holidays_country_date');
    }
};
