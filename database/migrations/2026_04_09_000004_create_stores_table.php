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
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 255);
            $table->string('type', 50);

            // Connector routing key — replaces the looser `type` column for any code added
            // after Phase 1.5. DEFAULT covers existing WooCommerce stores.
            // @see PLANNING.md section 5, 5.7
            $table->string('platform', 32)->default('woocommerce');

            // ISO 3166-1 alpha-2. NULL is valid — multi-country stores leave it blank.
            // Three-tier fallback for ad-spend country attribution:
            // COALESCE(campaigns.parsed_convention->>'country', stores.primary_country_code, 'UNKNOWN')
            // @see PLANNING.md section 5.7
            $table->char('primary_country_code', 2)->nullable();

            $table->string('domain');

            // Main store domain — used for country detection from ccTLD, PSI homepage
            // auto-creation, and webhook URL base. NOT the same as store_urls which are
            // specific pages to monitor. See: PLANNING.md "stores.website_url"
            $table->string('website_url', 500)->nullable();

            $table->char('currency', 3);
            $table->string('timezone', 100)->default('Europe/Berlin');
            $table->string('platform_store_id')->nullable();
            $table->string('status', 50)->default('connecting');
            $table->smallInteger('consecutive_sync_failures')->default(0);
            $table->text('auth_key_encrypted')->nullable();
            $table->text('auth_secret_encrypted')->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('webhook_secret_encrypted')->nullable();

            // Webhook IDs are now stored in the store_webhooks table.
            // See: PLANNING.md "store_webhooks"

            // Per-store performance targets — override workspace-level defaults.
            // Useful for multi-store workspaces with different margins per country.
            // See: PLANNING.md "stores — add target columns (Phase 1.1)"
            $table->decimal('target_roas', 5, 2)->nullable();
            $table->decimal('target_cpo', 10, 2)->nullable();
            $table->decimal('target_marketing_pct', 5, 2)->nullable();

            // Cost settings for profit calculations: tax deduction, shipping cost mode,
            // and fixed monthly overheads. Schema is managed by StoreCostSettings VO —
            // no migrations needed when adding/removing keys.
            $table->jsonb('cost_settings')->nullable();

            $table->string('historical_import_status', 50)->nullable();
            $table->date('historical_import_from')->nullable();
            $table->jsonb('historical_import_checkpoint')->nullable();
            $table->smallInteger('historical_import_progress')->nullable();
            $table->integer('historical_import_total_orders')->nullable();
            $table->timestamp('historical_import_started_at')->nullable();
            $table->timestamp('historical_import_completed_at')->nullable();
            $table->integer('historical_import_duration_seconds')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'domain']);
            $table->unique(['workspace_id', 'slug']);
            $table->index('workspace_id');
            $table->index(['workspace_id', 'platform_store_id']);
        });

        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_type_check CHECK (type IN ('woocommerce','shopify','magento','bigcommerce','prestashop','opencart'))");
        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_platform_check CHECK (platform IN ('woocommerce','shopify','magento','bigcommerce','prestashop','opencart'))");
        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_status_check CHECK (status IN ('connecting','active','error','disconnected'))");
        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_historical_import_status_check CHECK (historical_import_status IN ('pending','running','completed','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
