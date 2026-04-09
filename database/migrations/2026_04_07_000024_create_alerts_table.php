<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->foreignId('ad_account_id')->nullable()->constrained('ad_accounts')->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('severity', 50);
            $table->jsonb('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'resolved_at', 'created_at']);
        });

        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_severity_check CHECK (severity IN ('info','warning','critical'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
