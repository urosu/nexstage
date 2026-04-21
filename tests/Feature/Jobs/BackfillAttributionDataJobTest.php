<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\BackfillAttributionDataJob;
use App\Models\Order;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature tests for Phase 1.5 Step 8 — BackfillAttributionDataJob.
 *
 * Covers:
 *   - Job writes attribution_* columns on every order for the workspace
 *   - Cache progress key reaches 'completed' status
 *   - Idempotent: re-running overwrites without error
 *   - Job only touches its own workspace's orders
 */
class BackfillAttributionDataJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['reporting_currency' => 'EUR']);
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);

        app(WorkspaceContext::class)->set($this->workspace->id);
    }

    public function test_backfill_writes_attribution_columns_for_all_orders(): void
    {
        // Seed orders with WC-native UTMs so the parser has something to match.
        $orders = Order::factory()->count(3)->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'utm_source'   => 'facebook',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'retarget',
            'source_type'  => 'utm',
        ]);

        (new BackfillAttributionDataJob($this->workspace->id))->handle(
            app(\App\Services\Attribution\AttributionParserService::class),
        );

        foreach ($orders as $order) {
            $row = \Illuminate\Support\Facades\DB::table('orders')
                ->where('id', $order->id)
                ->first();

            $this->assertNotNull($row->attribution_source, "attribution_source should be set for order {$order->id}");
            $this->assertNotNull($row->attribution_last_touch, "attribution_last_touch should be set for order {$order->id}");
            $this->assertNotNull($row->attribution_parsed_at, "attribution_parsed_at should be set for order {$order->id}");
        }
    }

    public function test_cache_key_reaches_completed_status(): void
    {
        Order::factory()->count(2)->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'source_type'  => 'utm',
        ]);

        (new BackfillAttributionDataJob($this->workspace->id))->handle(
            app(\App\Services\Attribution\AttributionParserService::class),
        );

        $progress = Cache::get(BackfillAttributionDataJob::cacheKey($this->workspace->id));

        $this->assertNotNull($progress);
        $this->assertSame('completed', $progress['status']);
        $this->assertSame(2, $progress['processed']);
        $this->assertSame(2, $progress['total']);
        $this->assertNotNull($progress['completed_at']);
    }

    public function test_backfill_is_idempotent(): void
    {
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'utm_source'   => 'klaviyo',
            'utm_medium'   => 'email',
            'source_type'  => 'utm',
        ]);

        $parser = app(\App\Services\Attribution\AttributionParserService::class);

        // Run twice — second run must overwrite cleanly without error.
        (new BackfillAttributionDataJob($this->workspace->id))->handle($parser);
        (new BackfillAttributionDataJob($this->workspace->id))->handle($parser);

        $row = \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->first();

        $this->assertNotNull($row->attribution_source);
    }

    public function test_backfill_does_not_touch_other_workspace_orders(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $otherStore     = Store::factory()->create(['workspace_id' => $otherWorkspace->id]);

        $otherOrder = Order::factory()->create([
            'workspace_id'           => $otherWorkspace->id,
            'store_id'               => $otherStore->id,
            'utm_source'             => 'facebook',
            'utm_medium'             => 'cpc',
            'source_type'            => 'utm',
            'attribution_source'     => null,
            'attribution_parsed_at'  => null,
        ]);

        (new BackfillAttributionDataJob($this->workspace->id))->handle(
            app(\App\Services\Attribution\AttributionParserService::class),
        );

        // Other workspace order must be untouched.
        $this->assertDatabaseHas('orders', [
            'id'                 => $otherOrder->id,
            'attribution_source' => null,
        ]);
    }

    public function test_empty_workspace_completes_with_zero_processed(): void
    {
        // No orders for this workspace.
        (new BackfillAttributionDataJob($this->workspace->id))->handle(
            app(\App\Services\Attribution\AttributionParserService::class),
        );

        $progress = Cache::get(BackfillAttributionDataJob::cacheKey($this->workspace->id));

        $this->assertNotNull($progress);
        $this->assertSame('completed', $progress['status']);
        $this->assertSame(0, $progress['processed']);
        $this->assertSame(0, $progress['total']);
    }
}
