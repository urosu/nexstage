<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_console_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('property_url', 500);
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 50)->default('active');
            $table->smallInteger('consecutive_sync_failures')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'property_url']);
            $table->index('workspace_id');
        });

        DB::statement("ALTER TABLE search_console_properties ADD CONSTRAINT search_console_properties_status_check CHECK (status IN ('active','error','token_expired','disconnected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('search_console_properties');
    }
};
