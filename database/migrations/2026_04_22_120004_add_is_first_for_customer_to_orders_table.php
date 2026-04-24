<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Precomputed "is this customer's first order" flag.
 *
 * Why: CAC (§F6), First-order ROAS (§F7), Day-30 ROAS (§F8), and Returning-order %
 * (§F30) all need this flag in a per-range aggregation. Computing on the fly via
 * window function (MIN(occurred_at) OVER (PARTITION BY customer_email_hash)) is
 * expensive on large order tables and runs on every page load. Storing it as a
 * boolean column + index makes the queries O(range × index-lookup) instead of
 * O(full-partition × rows-in-range).
 *
 * Write rule: Upsert actions (WC + Shopify) compute this by checking whether any
 * earlier order exists for the same workspace_id + customer_email_hash. For pre-launch
 * with empty DB the default (false) is correct for the very first order of each
 * customer until the upsert code is updated to write true; re-syncing stores after
 * the upsert code change will set the flag correctly.
 *
 * Orders with NULL customer_email_hash are never considered "first" — they're guest
 * checkouts we can't dedupe. The flag stays false (default).
 *
 * Index supports the common "count first orders for workspace in range" query.
 *
 * @see PROGRESS.md §F6 CAC, §F7 First-order ROAS, §F30 Returning-order %
 * @see UpsertWooCommerceOrderAction + UpsertShopifyOrderAction (must be updated)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('is_first_for_customer')->default(false)->after('customer_id');
        });

        // Supports: COUNT(*) WHERE workspace_id=X AND is_first_for_customer=true AND occurred_at IN range
        // Also supports per-customer lookup: "has this email ordered before?" via the hash prefix.
        DB::statement('CREATE INDEX idx_orders_first_customer ON orders (workspace_id, is_first_for_customer, occurred_at) WHERE is_first_for_customer = true');

        // Supports: dedup check during upsert ("does any earlier order exist for this email?")
        DB::statement('CREATE INDEX idx_orders_customer_hash_occurred ON orders (workspace_id, customer_email_hash, occurred_at) WHERE customer_email_hash IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_orders_customer_hash_occurred');
        DB::statement('DROP INDEX IF EXISTS idx_orders_first_customer');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('is_first_for_customer');
        });
    }
};
