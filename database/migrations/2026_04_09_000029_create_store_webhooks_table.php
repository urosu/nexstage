<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replaces stores.platform_webhook_ids JSONB — proper table for webhook tracking.
        // Used by ConnectStoreAction (register) and disconnect logic (remove).
        // soft-deleted via deleted_at rather than hard-delete for audit trail.
        Schema::create('store_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('platform_webhook_id', 255);
            $table->string('topic', 255);

            // Updated by the webhook controller on every successfully processed delivery.
            // PollStoreOrdersJob reads this to decide whether to poll: if a webhook arrived
            // recently, polling is skipped. NULL = no confirmed delivery ever — always poll.
            // @see PLANNING.md section 5, 21
            $table->timestamp('last_successful_delivery_at')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->unique(['store_id', 'platform_webhook_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_webhooks');
    }
};
