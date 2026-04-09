<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('reporting_currency', 3)->default('EUR');
            $table->string('reporting_timezone', 100)->default('Europe/Berlin');
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('billing_plan', 50)->nullable();
            $table->string('stripe_id')->nullable();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->string('billing_name')->nullable();
            $table->string('billing_email')->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->string('vat_number', 50)->nullable();
            $table->foreignId('billing_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->boolean('is_orphaned')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE workspaces ADD CONSTRAINT workspaces_billing_plan_check CHECK (billing_plan IN ('starter','growth','scale','percentage','enterprise'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
