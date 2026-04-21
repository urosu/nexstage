<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AdHistoricalImportJob;
use App\Jobs\GscHistoricalImportJob;
use App\Jobs\TriggerReactivationBackfillJob;
use App\Jobs\WooCommerceHistoricalImportJob;
use App\Models\AdAccount;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for Phase 1.5 Step 15 — Trial freeze + reactivation backfill.
 *
 * Covers TriggerReactivationBackfillJob:
 *   - Dispatches WooCommerceHistoricalImportJob for each previously-imported store
 *   - Dispatches AdHistoricalImportJob for each previously-imported ad account
 *   - Dispatches GscHistoricalImportJob for each previously-imported GSC property
 *   - Resets historical_import_status to 'pending' and clears checkpoint
 *   - Skips integrations that were never imported (historical_import_status = null)
 *   - Aborts when workspace is deleted or still has no billing_plan
 */
class TriggerReactivationBackfillJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private string $gapStart;

    protected function setUp(): void
    {
        parent::setUp();

        // Workspace that was frozen: trial expired, no billing plan yet.
        $this->workspace = Workspace::factory()->create([
            'billing_plan'  => 'starter', // reactivated — must have a plan for the job to proceed
            'trial_ends_at' => now()->subDays(5),
        ]);

        $this->gapStart = now()->subDays(5)->toDateString();

        app(WorkspaceContext::class)->set($this->workspace->id);
    }

    // =========================================================================
    // Store backfill
    // =========================================================================

    public function test_dispatches_woocommerce_import_for_previously_imported_store(): void
    {
        Queue::fake();

        Store::factory()->create([
            'workspace_id'            => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);

        (new TriggerReactivationBackfillJob($this->workspace->id, $this->gapStart))->handle();

        Queue::assertPushed(WooCommerceHistoricalImportJob::class);
    }

    public function test_resets_store_import_fields_for_gap_period(): void
    {
        Queue::fake();

        $store = Store::factory()->create([
            'workspace_id'                       => $this->workspace->id,
            'historical_import_status'           => 'completed',
            'historical_import_checkpoint'       => '2026-01-15',
            'historical_import_progress'         => 100,
        ]);

        (new TriggerReactivationBackfillJob($this->workspace->id, $this->gapStart))->handle();

        $store->refresh();

        $this->assertSame('pending', $store->historical_import_status);
        $this->assertSame($this->gapStart, $store->historical_import_from->toDateString());
        $this->assertNull($store->historical_import_checkpoint);
        $this->assertNull($store->historical_import_progress);
    }

    public function test_skips_store_never_imported(): void
    {
        Queue::fake();

        Store::factory()->create([
            'workspace_id'            => $this->workspace->id,
            'historical_import_status' => null,   // never started
        ]);

        (new TriggerReactivationBackfillJob($this->workspace->id, $this->gapStart))->handle();

        Queue::assertNotPushed(WooCommerceHistoricalImportJob::class);
    }

    // =========================================================================
    // Ad account backfill
    // =========================================================================

    public function test_dispatches_ad_import_for_previously_imported_ad_account(): void
    {
        Queue::fake();

        AdAccount::factory()->create([
            'workspace_id'            => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);

        (new TriggerReactivationBackfillJob($this->workspace->id, $this->gapStart))->handle();

        Queue::assertPushed(AdHistoricalImportJob::class);
    }

    public function test_skips_ad_account_never_imported(): void
    {
        Queue::fake();

        AdAccount::factory()->create([
            'workspace_id'            => $this->workspace->id,
            'historical_import_status' => null,
        ]);

        (new TriggerReactivationBackfillJob($this->workspace->id, $this->gapStart))->handle();

        Queue::assertNotPushed(AdHistoricalImportJob::class);
    }

    // =========================================================================
    // GSC backfill
    // =========================================================================

    public function test_dispatches_gsc_import_for_previously_imported_property(): void
    {
        Queue::fake();

        DB::table('search_console_properties')->insert([
            'workspace_id'            => $this->workspace->id,
            'property_url'            => 'https://example.com/',
            'status'                  => 'active',
            'historical_import_status' => 'completed',
            'created_at'              => now()->toDateTimeString(),
            'updated_at'              => now()->toDateTimeString(),
        ]);

        (new TriggerReactivationBackfillJob($this->workspace->id, $this->gapStart))->handle();

        Queue::assertPushed(GscHistoricalImportJob::class);
    }

    // =========================================================================
    // Safety guards
    // =========================================================================

    public function test_aborts_when_workspace_still_has_no_billing_plan(): void
    {
        Queue::fake();

        // Simulate race condition: plan not yet committed when job fires.
        $this->workspace->update(['billing_plan' => null]);

        Store::factory()->create([
            'workspace_id'            => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);

        (new TriggerReactivationBackfillJob($this->workspace->id, $this->gapStart))->handle();

        // Must not dispatch anything — workspace is still frozen.
        Queue::assertNotPushed(WooCommerceHistoricalImportJob::class);
    }

    public function test_aborts_silently_when_workspace_not_found(): void
    {
        Queue::fake();

        // Non-existent workspace ID.
        (new TriggerReactivationBackfillJob(999_999, $this->gapStart))->handle();

        Queue::assertNotPushed(WooCommerceHistoricalImportJob::class);
        Queue::assertNotPushed(AdHistoricalImportJob::class);
        Queue::assertNotPushed(GscHistoricalImportJob::class);
    }

    public function test_dispatches_imports_for_multiple_stores(): void
    {
        Queue::fake();

        Store::factory()->count(3)->create([
            'workspace_id'            => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);

        (new TriggerReactivationBackfillJob($this->workspace->id, $this->gapStart))->handle();

        Queue::assertPushed(WooCommerceHistoricalImportJob::class, 3);
    }
}
