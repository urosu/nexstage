<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->string('domain');
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
            $table->jsonb('platform_webhook_ids')->nullable();
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
        });

        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_type_check CHECK (type IN ('woocommerce','shopify','magento','bigcommerce','prestashop','opencart'))");
        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_status_check CHECK (status IN ('connecting','active','error','disconnected'))");
        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_historical_import_status_check CHECK (historical_import_status IN ('pending','running','completed','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
