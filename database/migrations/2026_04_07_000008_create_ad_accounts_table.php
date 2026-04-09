<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('platform', 50);
            $table->string('external_id');
            $table->string('name');
            $table->char('currency', 3);
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 50)->default('active');
            $table->smallInteger('consecutive_sync_failures')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'platform', 'external_id']);
            $table->index('workspace_id');
        });

        DB::statement("ALTER TABLE ad_accounts ADD CONSTRAINT ad_accounts_platform_check CHECK (platform IN ('facebook','google'))");
        DB::statement("ALTER TABLE ad_accounts ADD CONSTRAINT ad_accounts_status_check CHECK (status IN ('active','error','token_expired','disconnected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_accounts');
    }
};
