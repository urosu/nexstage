<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Performance indexes identified during audit (2026-04-19).
 *
 * Covers: attribution queries, product analytics, admin logs, channel mapping lookups.
 * All indexes are partial or covering — chosen to match the actual WHERE/JOIN/ORDER BY
 * patterns used in controllers and jobs. See PLANNING.md for relevant section references.
 */
return new class extends Migration
{
    public function up(): void
    {
        // orders: covering index for attribution + time-range queries
        // Used by RevenueAttributionService, DashboardController, CampaignsController, SeoController.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_attribution_occurred
            ON orders (workspace_id, attribution_source, occurred_at)');

        // orders: functional index for JSONB attribution campaign match
        // Used by RevenueAttributionService::getCampaignAttributedRevenue and
        // ReclassifyOrdersForMappingJob (LOWER(attribution_last_touch->>\'source\')).
        DB::statement("CREATE INDEX IF NOT EXISTS idx_orders_attr_lt_source
            ON orders ((LOWER(attribution_last_touch->>'source')), workspace_id)
            WHERE attribution_last_touch IS NOT NULL");

        DB::statement("CREATE INDEX IF NOT EXISTS idx_orders_attr_lt_campaign
            ON orders ((attribution_last_touch->>'campaign'), workspace_id)
            WHERE attribution_last_touch IS NOT NULL");

        // order_items: direct product lookup without going through orders
        // Used when querying product-level aggregates grouped by product_external_id.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_order_items_product_external_id
            ON order_items (product_external_id)');

        // daily_snapshot_products: product analytics queries filter/group by product across date ranges
        // Used by AnalyticsController::products, StoreController::products.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_dsp_ws_product_date
            ON daily_snapshot_products (workspace_id, product_external_id, snapshot_date)');

        // sync_logs: admin logs page filters by workspace + time; currently only has (status, created_at)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sync_logs_workspace_created
            ON sync_logs (workspace_id, created_at DESC)');

        // webhook_logs: admin logs page and IntegrationsController filter by workspace + time
        DB::statement('CREATE INDEX IF NOT EXISTS idx_webhook_logs_workspace_created
            ON webhook_logs (workspace_id, created_at DESC)');

        DB::statement("CREATE INDEX IF NOT EXISTS idx_webhook_logs_status
            ON webhook_logs (status)");

        // channel_mappings: global rows (workspace_id IS NULL) are fetched separately
        // and shared across all workspaces. Partial index avoids a full-table scan.
        DB::statement("CREATE INDEX IF NOT EXISTS idx_channel_mappings_global
            ON channel_mappings (utm_source_pattern)
            WHERE workspace_id IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_orders_attribution_occurred');
        DB::statement('DROP INDEX IF EXISTS idx_orders_attr_lt_source');
        DB::statement('DROP INDEX IF EXISTS idx_orders_attr_lt_campaign');
        DB::statement('DROP INDEX IF EXISTS idx_order_items_product_external_id');
        DB::statement('DROP INDEX IF EXISTS idx_dsp_ws_product_date');
        DB::statement('DROP INDEX IF EXISTS idx_sync_logs_workspace_created');
        DB::statement('DROP INDEX IF EXISTS idx_webhook_logs_workspace_created');
        DB::statement('DROP INDEX IF EXISTS idx_webhook_logs_status');
        DB::statement('DROP INDEX IF EXISTS idx_channel_mappings_global');
    }
};
