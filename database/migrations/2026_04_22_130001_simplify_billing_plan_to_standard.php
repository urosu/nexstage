<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the three-tier billing model (starter / growth / scale) with a
 * single-plan model: 'standard' (€39/mo min + 0.4% GMV) and 'enterprise'
 * (custom, managed outside Stripe).
 *
 * See PLANNING.md §9 for the pricing spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop old constraint first, then remap values, then add new constraint.
        DB::statement('ALTER TABLE workspaces DROP CONSTRAINT workspaces_billing_plan_check');
        DB::statement("UPDATE workspaces SET billing_plan = 'standard' WHERE billing_plan IN ('starter', 'growth', 'scale')");
        DB::statement("ALTER TABLE workspaces ADD CONSTRAINT workspaces_billing_plan_check CHECK (billing_plan IN ('standard', 'enterprise'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workspaces DROP CONSTRAINT workspaces_billing_plan_check');
        DB::statement("ALTER TABLE workspaces ADD CONSTRAINT workspaces_billing_plan_check CHECK (billing_plan IN ('starter', 'growth', 'scale', 'enterprise'))");
    }
};
