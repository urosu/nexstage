<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('external_number')->nullable();
            $table->string('status', 100);
            $table->char('currency', 3);
            $table->decimal('total', 12, 4);
            $table->decimal('subtotal', 12, 4);
            $table->decimal('tax', 12, 4)->default(0);
            $table->decimal('shipping', 12, 4)->default(0);
            $table->decimal('discount', 12, 4)->default(0);
            $table->decimal('total_in_reporting_currency', 12, 4)->nullable();
            $table->char('customer_email_hash', 64)->nullable();
            $table->char('customer_country', 2)->nullable();

            // WooCommerce per-store user ID — NOT globally unique.
            // Always query with store_id. customer_email_hash is the cross-store dedup key.
            $table->string('customer_id', 255)->nullable();

            $table->string('payment_method', 100)->nullable();
            $table->string('payment_method_title', 255)->nullable();

            // CHAR(2): ISO 3166-1 alpha-2 country code for the shipping destination.
            // WooCommerce returns alpha-2 codes; used for the countries analytics page.
            $table->char('shipping_country', 2)->nullable();

            // Denormalized from refunds table for fast querying. Updated by SyncRecentRefundsJob.
            // Related: app/Jobs/SyncRecentRefundsJob.php
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->timestamp('last_refunded_at')->nullable();

            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term', 500)->nullable();

            // WooCommerce 8.5+ Order Attribution source type.
            // Values: organic_search, direct, utm, referral, admin, typein, link.
            // NULL for orders from WC < 8.5 or where attribution was not captured.
            // Why: WC native attribution gives us the channel for non-UTM traffic
            // (organic, direct) that UTM parameters alone can't identify.
            $table->string('source_type', 50)->nullable();

            // Normalised attribution columns — written by AttributionParserService.
            // RevenueAttributionService continues reading utm_* columns until Phase 1.5
            // Step 14 cutover — these are parallel, not replacements.
            // Values for attribution_source: pys / wc_native / shopify_journey / shopify_landing / referrer / none
            // first_touch/last_touch shape: {source, medium, campaign, content, term, landing_page, timestamp}
            // click_ids shape: {fbc, fbp, gclid, msclkid} — Phase 4 CAPI enabler.
            // @see PLANNING.md section 5, 6
            $table->string('attribution_source', 32)->nullable();
            $table->jsonb('attribution_first_touch')->nullable();
            $table->jsonb('attribution_last_touch')->nullable();
            $table->jsonb('attribution_click_ids')->nullable();
            $table->timestamp('attribution_parsed_at')->nullable();

            // fee_lines, order_notes, and other rarely-accessed fields.
            // See: PLANNING.md "Data Capture Strategy — What to JSONB"
            $table->jsonb('raw_meta')->nullable();
            $table->string('raw_meta_api_version', 20)->nullable();

            $table->timestamp('occurred_at');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['store_id', 'external_id']);
            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['workspace_id', 'store_id', 'occurred_at']);
            $table->index(['workspace_id', 'status', 'occurred_at']);
            $table->index(['workspace_id', 'customer_country', 'occurred_at']);
            $table->index(['workspace_id', 'shipping_country']);
            $table->index(['store_id', 'customer_id']);
            $table->index(['store_id', 'synced_at']);
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('completed','processing','refunded','cancelled','other'))");

        // Supports channel-based filtering in attribution-aware queries.
        DB::statement("CREATE INDEX idx_orders_attribution_source ON orders (workspace_id, attribution_source)");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
