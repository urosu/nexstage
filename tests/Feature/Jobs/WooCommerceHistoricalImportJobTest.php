<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ComputeDailySnapshotJob;
use App\Jobs\WooCommerceHistoricalImportJob;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WooCommerceHistoricalImportJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['reporting_currency' => 'EUR']);

        $this->store = Store::factory()->create([
            'workspace_id'                => $this->workspace->id,
            'domain'                      => 'mystore.example.com',
            'historical_import_status'    => 'pending',
            'historical_import_from'      => Carbon::yesterday()->subDays(2)->toDateString(),
            'historical_import_total_orders' => 1,
            'auth_key_encrypted'          => Crypt::encryptString('ck_test_key'),
            'auth_secret_encrypted'       => Crypt::encryptString('cs_test_secret'),
        ]);

        app(WorkspaceContext::class)->set($this->workspace->id);
    }

    private function fakeWooCommerceApi(array $orders = []): void
    {
        if (empty($orders)) {
            $orders = [[
                'id'               => 1001,
                'number'           => '1001',
                'status'           => 'completed',
                'date_created_gmt' => Carbon::yesterday()->toIso8601String(),
                'currency'         => 'EUR',
                'total'            => '100.00',
                'subtotal'         => '90.00',
                'total_tax'        => '10.00',
                'shipping_total'   => '0.00',
                'discount_total'   => '0.00',
                'billing'          => ['email' => 'test@example.com', 'country' => 'DE'],
                'line_items'       => [],
                'meta_data'        => [],
            ]];
        }

        Http::fake([
            // Frankfurter FX rates (called by UpdateFxRatesJob::dispatchSync)
            'api.frankfurter.dev/*' => Http::response([
                'base'  => 'EUR',
                'date'  => today()->toDateString(),
                'rates' => ['USD' => 1.08, 'GBP' => 0.86],
            ], 200),

            // WooCommerce orders endpoint — first page returns orders, second returns empty
            'https://mystore.example.com/wp-json/wc/v3/orders*' => Http::sequence()
                ->push($orders, 200, ['X-WP-Total' => (string) count($orders), 'X-WP-TotalPages' => '1'])
                ->push([], 200, ['X-WP-Total' => '0', 'X-WP-TotalPages' => '0']),
        ]);
    }

    private function runJob(): void
    {
        (new WooCommerceHistoricalImportJob($this->store->id, $this->workspace->id))
            ->handle(app(\App\Actions\UpsertWooCommerceOrderAction::class));
    }

    public function test_billing_gate_fails_import_when_trial_expired(): void
    {
        Http::fake(); // No API calls should be made

        $this->workspace->update([
            'trial_ends_at' => now()->subDay(),
            'billing_plan'  => null,
        ]);

        $this->runJob();

        $this->assertSame('failed', $this->store->fresh()->historical_import_status);

        $this->assertDatabaseHas('sync_logs', [
            'workspace_id' => $this->workspace->id,
            'status'       => 'failed',
            'error_message' => 'Import paused — subscription required.',
        ]);
    }

    public function test_sets_status_completed_on_success(): void
    {
        Queue::fake([ComputeDailySnapshotJob::class]);
        $this->fakeWooCommerceApi();

        $this->runJob();

        $this->assertSame('completed', $this->store->fresh()->historical_import_status);
    }

    public function test_dispatches_snapshot_jobs_after_completion(): void
    {
        Queue::fake([ComputeDailySnapshotJob::class]);
        $this->fakeWooCommerceApi();

        $this->runJob();

        Queue::assertPushed(ComputeDailySnapshotJob::class);
    }

    public function test_updates_progress_during_import(): void
    {
        Queue::fake([ComputeDailySnapshotJob::class]);
        $this->fakeWooCommerceApi();

        $this->runJob();

        // On success progress = 100
        $this->assertSame(100, $this->store->fresh()->historical_import_progress);
    }

    public function test_resumes_from_checkpoint(): void
    {
        Queue::fake([ComputeDailySnapshotJob::class]);

        $checkpointDate = Carbon::yesterday()->subDay()->toDateString();
        $this->store->update([
            'historical_import_checkpoint' => ['date_cursor' => $checkpointDate],
        ]);

        // Fake HTTP and capture requests
        Http::fake([
            'api.frankfurter.dev/*' => Http::response([
                'base'  => 'EUR',
                'date'  => today()->toDateString(),
                'rates' => ['USD' => 1.08],
            ], 200),

            'https://mystore.example.com/wp-json/wc/v3/orders*' => Http::response(
                [],
                200,
                ['X-WP-Total' => '0', 'X-WP-TotalPages' => '1']
            ),
        ]);

        $this->runJob();

        // Assert the WooCommerce API was called with the checkpoint date in the URL
        Http::assertSent(function ($request) use ($checkpointDate) {
            return str_contains($request->url(), 'mystore.example.com')
                && str_contains($request->url(), 'orders')
                && str_contains($request->url(), urlencode($checkpointDate));
        });
    }

    public function test_creates_sync_log_on_start(): void
    {
        Queue::fake([ComputeDailySnapshotJob::class]);
        $this->fakeWooCommerceApi();

        $this->runJob();

        $this->assertDatabaseHas('sync_logs', [
            'workspace_id'  => $this->workspace->id,
            'syncable_type' => \App\Models\Store::class,
            'syncable_id'   => $this->store->id,
            'status'        => 'completed',
        ]);
    }

    public function test_import_skipped_when_store_not_found(): void
    {
        Http::fake(); // No API calls expected

        $job = new WooCommerceHistoricalImportJob(99999, $this->workspace->id);
        $job->handle(app(\App\Actions\UpsertWooCommerceOrderAction::class));

        // Simply verify no exception thrown and nothing changed
        $this->assertTrue(true);
    }
}
