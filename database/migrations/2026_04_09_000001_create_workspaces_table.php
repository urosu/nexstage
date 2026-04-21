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
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('reporting_currency', 3)->default('EUR');
            $table->string('reporting_timezone', 100)->default('Europe/Berlin');

            // Integration flags — set when integrations connect/disconnect.
            // Consumed by: AppLayout sidebar conditional rendering, billing basis auto-derivation.
            // See: PLANNING.md "Workspace integration flags"
            $table->boolean('has_store')->default(false);
            $table->boolean('has_ads')->default(false);
            $table->boolean('has_gsc')->default(false);
            $table->boolean('has_psi')->default(false);

            // Country/region for holiday context and quiet-hours scheduling.
            // Auto-detected from store TLD → IP geolocation → Stripe billing address.
            // See: PLANNING.md "Onboarding Flow — country auto-detection"
            $table->char('country', 2)->nullable();
            $table->string('region', 100)->nullable();

            // Separate from reporting_timezone: controls quiet hours + schedule display.
            // reporting_timezone controls data aggregation granularity.
            $table->string('timezone', 50)->nullable();

            // Workspace-level performance targets — Phase 1.1.
            // Per-store overrides live on the stores table (multi-store workspaces with
            // different margins per country). Campaign-level overrides live on campaigns.
            // See: PLANNING.md "Source-Tagged MetricCard — UI Primitive"
            $table->decimal('target_roas', 5, 2)->nullable();
            $table->decimal('target_cpo', 10, 2)->nullable();
            $table->decimal('target_marketing_pct', 5, 2)->nullable();

            $table->timestamp('trial_ends_at')->nullable();
            $table->string('billing_plan', 50)->nullable();

            // Catch-all for structured UI config that doesn't warrant dedicated columns:
            // naming convention shape, dashboard preferences, dismissed banner timestamps.
            // Access only via WorkspaceSettings value object — never raw array access.
            // Queries must never filter on JSONB subkeys; promote to a column if needed.
            // @see PLANNING.md section 5.6
            $table->jsonb('workspace_settings')->nullable();

            $table->string('stripe_id')->nullable();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->string('billing_name')->nullable();
            $table->string('billing_email')->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->string('vat_number', 50)->nullable();

            // Phase 3+: consolidated agency billing. Child workspaces share billing owner's subscription.
            $table->foreignId('billing_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();

            // UTM coverage health check — Phase 1.3.
            // Computed by ComputeUtmCoverageJob (runs on store/ad connect and nightly).
            // Drives the green/amber/red indicator near attribution metrics on the Dashboard.
            // utm_unrecognized_sources: JSON array [{source, order_count, revenue_pct}, ...] for
            //   orders in the "other_tagged" bucket not matching Facebook or Google aliases.
            // See: PLANNING.md "UTM Coverage Health Check + Tag Generator"
            $table->decimal('utm_coverage_pct', 5, 2)->nullable();
            $table->string('utm_coverage_status', 10)->nullable();
            $table->timestamp('utm_coverage_checked_at')->nullable();
            $table->jsonb('utm_unrecognized_sources')->nullable();

            $table->boolean('is_orphaned')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE workspaces ADD CONSTRAINT workspaces_billing_plan_check CHECK (billing_plan IN ('starter','growth','scale','enterprise'))");
        DB::statement("ALTER TABLE workspaces ADD CONSTRAINT workspaces_utm_coverage_status_check CHECK (utm_coverage_status IN ('green','amber','red'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
