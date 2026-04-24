<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promote payment processor fee from raw_meta.fee_lines JSONB to a dedicated column.
 *
 * Why: Contribution Margin (§F3) aggregates fees across thousands of orders in a date
 * range. JSONB path aggregation is 10-50× slower than a DECIMAL column sum. Given CM
 * appears as a hero metric on Home, on every channel row in Acquisition, and on every
 * product row in Store › Products, query speed matters.
 *
 * Pre-launch: column defaults to 0. Upsert actions start writing the value from:
 *   - WooCommerce: SUM of raw_meta.fee_lines[].total where fee_lines[].name indicates
 *     payment processor (Stripe, PayPal, Mollie, etc.)
 *   - Shopify: platform_data.transaction_fees (if present via GraphQL), else 0
 *
 * raw_meta.fee_lines remains the source of truth (holds the original structure incl.
 * non-payment fees like gift-wrapping). This column is a computed projection for speed.
 *
 * @see PROGRESS.md §F3 Contribution Margin formula
 * @see UpsertWooCommerceOrderAction + UpsertShopifyOrderAction (must be updated to write this)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('payment_fee', 12, 4)->default(0)->after('shipping');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('payment_fee');
        });
    }
};
