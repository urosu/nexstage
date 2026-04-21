<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add platform_data JSONB column to orders.
 *
 * Used by Shopify (Phase 2) to store platform-specific fields that have no
 * equivalent column in the platform-agnostic schema:
 *   - customer_journey_summary  (raw Shopify customerJourneySummary node, read by ShopifyCustomerJourneySource)
 *   - order_name                (#1001 display name)
 *   - cogs_note                 ('pre_snapshot' when no inventory snapshot found for COGS lookup)
 *
 * This is a Nexstage-owned JSONB shape (not raw platform API data), so it does NOT
 * get a paired platform_data_api_version column per CLAUDE.md gotchas.
 *
 * WooCommerce orders: platform_data stays NULL — all WC-specific data already has columns.
 *
 * See: PLANNING.md "Phase 2 — Shopify" Step 3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->jsonb('platform_data')->nullable()->after('raw_meta_api_version');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('platform_data');
        });
    }
};
