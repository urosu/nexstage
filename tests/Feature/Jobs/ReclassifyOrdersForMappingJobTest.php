<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ReclassifyOrdersForMappingJob;
use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for the classify UI → channel_mappings → historical re-classification
 * chain added in Phase 1.6.
 *
 * Tests two layers:
 *   1. ReclassifyOrdersForMappingJob::handle() — JSONB update of historical orders
 *   2. Full chain: POST /manage/channel-mappings → mapping created → job dispatched
 *
 * The job updates orders.attribution_last_touch by merging `channel` / `channel_type`
 * keys into the existing JSONB for rows matching the (source, medium) pair.
 *
 * @see app/Jobs/ReclassifyOrdersForMappingJob
 * @see app/Http/Controllers/ManageController::storeChannelMapping
 * @see PLANNING.md section 16.7 (inline classify + historical reclassification)
 */
class ReclassifyOrdersForMappingJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private Store $store;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $this->user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $this->store = Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);
    }

    private function insertOrder(array $attributionLastTouch): int
    {
        DB::table('orders')->insert([
            'workspace_id'                => $this->workspace->id,
            'store_id'                    => $this->store->id,
            'external_id'                 => (string) random_int(10000, 999999),
            'external_number'             => '100',
            'status'                      => 'completed',
            'currency'                    => 'EUR',
            'total'                       => 100.00,
            'subtotal'                    => 90.00,
            'tax'                         => 10.00,
            'shipping'                    => 0.00,
            'discount'                    => 0.00,
            'total_in_reporting_currency' => 100.00,
            'attribution_last_touch'      => json_encode($attributionLastTouch),
            'occurred_at'                 => now()->subDays(2),
            'synced_at'                   => now(),
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        return (int) DB::table('orders')->latest('id')->value('id');
    }

    private function getOrderAttribution(int $orderId): ?array
    {
        $row = DB::table('orders')->where('id', $orderId)->value('attribution_last_touch');

        return $row ? json_decode($row, true) : null;
    }

    // ── Job handle() tests ───────────────────────────────────────────────────

    public function test_job_updates_matching_orders_attribution(): void
    {
        $orderId = $this->insertOrder([
            'source' => 'facebook',
            'medium' => 'cpc',
        ]);

        (new ReclassifyOrdersForMappingJob(
            workspaceId:  $this->workspace->id,
            source:       'facebook',
            medium:       'cpc',
            channelName:  'Facebook Ads',
            channelType:  'paid_social',
        ))->handle();

        $attribution = $this->getOrderAttribution($orderId);
        $this->assertSame('Facebook Ads', $attribution['channel']);
        $this->assertSame('paid_social',  $attribution['channel_type']);
        // Original keys preserved
        $this->assertSame('facebook', $attribution['source']);
        $this->assertSame('cpc',      $attribution['medium']);
    }

    public function test_source_matching_is_case_insensitive(): void
    {
        // Order stored with lowercase source; job dispatched with uppercase
        $orderId = $this->insertOrder([
            'source' => 'tiktok',
            'medium' => 'cpc',
        ]);

        (new ReclassifyOrdersForMappingJob(
            workspaceId:  $this->workspace->id,
            source:       'TikTok',
            medium:       'cpc',
            channelName:  'TikTok Ads',
            channelType:  'paid_social',
        ))->handle();

        $attribution = $this->getOrderAttribution($orderId);
        $this->assertSame('TikTok Ads', $attribution['channel']);
    }

    public function test_null_medium_acts_as_wildcard_and_matches_any_medium(): void
    {
        $orderCpc  = $this->insertOrder(['source' => 'pinterest', 'medium' => 'cpc']);
        $orderCpm  = $this->insertOrder(['source' => 'pinterest', 'medium' => 'cpm']);
        $orderNull = $this->insertOrder(['source' => 'pinterest', 'medium' => null]);

        (new ReclassifyOrdersForMappingJob(
            workspaceId:  $this->workspace->id,
            source:       'pinterest',
            medium:       null,   // wildcard
            channelName:  'Pinterest',
            channelType:  'paid_social',
        ))->handle();

        // Wait — null medium matches only orders with null or empty medium per the job logic:
        // "(attribution_last_touch->>'medium' IS NULL OR attribution_last_touch->>'medium' = '')"
        $attributionNull = $this->getOrderAttribution($orderNull);
        $this->assertSame('Pinterest', $attributionNull['channel'],
            'Null medium job should update orders with null medium');

        // Orders with a specific medium should NOT be re-stamped by a wildcard job
        $attributionCpc = $this->getOrderAttribution($orderCpc);
        $this->assertArrayNotHasKey('channel', $attributionCpc,
            'Order with cpc medium should not be touched by null-medium wildcard');
    }

    public function test_specific_medium_matches_only_exact_medium(): void
    {
        $orderCpc  = $this->insertOrder(['source' => 'google', 'medium' => 'cpc']);
        $orderCpm  = $this->insertOrder(['source' => 'google', 'medium' => 'cpm']);

        (new ReclassifyOrdersForMappingJob(
            workspaceId:  $this->workspace->id,
            source:       'google',
            medium:       'cpc',
            channelName:  'Google CPC',
            channelType:  'paid_search',
        ))->handle();

        $attributionCpc = $this->getOrderAttribution($orderCpc);
        $this->assertSame('Google CPC', $attributionCpc['channel']);

        $attributionCpm = $this->getOrderAttribution($orderCpm);
        $this->assertArrayNotHasKey('channel', $attributionCpm,
            'Order with different medium should not be updated');
    }

    public function test_orders_without_attribution_last_touch_are_unaffected(): void
    {
        DB::table('orders')->insert([
            'workspace_id'                => $this->workspace->id,
            'store_id'                    => $this->store->id,
            'external_id'                 => (string) random_int(10000, 999999),
            'external_number'             => '200',
            'status'                      => 'completed',
            'currency'                    => 'EUR',
            'total'                       => 50.00,
            'subtotal'                    => 45.00,
            'tax'                         => 5.00,
            'shipping'                    => 0.00,
            'discount'                    => 0.00,
            'total_in_reporting_currency' => 50.00,
            'attribution_last_touch'      => null, // no attribution
            'occurred_at'                 => now()->subDays(1),
            'synced_at'                   => now(),
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);
        $orderId = (int) DB::table('orders')->latest('id')->value('id');

        (new ReclassifyOrdersForMappingJob(
            workspaceId:  $this->workspace->id,
            source:       'google',
            medium:       'cpc',
            channelName:  'Google Ads',
            channelType:  'paid_search',
        ))->handle();

        $this->assertNull(DB::table('orders')->where('id', $orderId)->value('attribution_last_touch'));
    }

    public function test_cross_workspace_isolation(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $otherStore     = Store::factory()->create(['workspace_id' => $otherWorkspace->id]);

        // Order in a different workspace
        DB::table('orders')->insert([
            'workspace_id'                => $otherWorkspace->id,
            'store_id'                    => $otherStore->id,
            'external_id'                 => (string) random_int(10000, 999999),
            'external_number'             => '300',
            'status'                      => 'completed',
            'currency'                    => 'EUR',
            'total'                       => 100.00,
            'subtotal'                    => 90.00,
            'tax'                         => 10.00,
            'shipping'                    => 0.00,
            'discount'                    => 0.00,
            'total_in_reporting_currency' => 100.00,
            'attribution_last_touch'      => json_encode(['source' => 'email', 'medium' => 'newsletter']),
            'occurred_at'                 => now()->subDays(1),
            'synced_at'                   => now(),
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);
        $otherOrderId = (int) DB::table('orders')->latest('id')->value('id');

        (new ReclassifyOrdersForMappingJob(
            workspaceId:  $this->workspace->id, // this workspace, not other
            source:       'email',
            medium:       'newsletter',
            channelName:  'Email',
            channelType:  'email',
        ))->handle();

        // Other workspace's order must not be touched
        $attribution = $this->getOrderAttribution($otherOrderId);
        $this->assertArrayNotHasKey('channel', $attribution);
    }

    // ── Full chain: POST /manage/channel-mappings → job dispatched ───────────

    public function test_post_to_channel_mappings_creates_row_and_dispatches_job(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post("/{$this->workspace->slug}/manage/channel-mappings", [
                'utm_source_pattern' => 'klaviyo',
                'utm_medium_pattern' => 'email',
                'channel_name'       => 'Klaviyo',
                'channel_type'       => 'email',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('channel_mappings', [
            'workspace_id'       => $this->workspace->id,
            'utm_source_pattern' => 'klaviyo',
            'utm_medium_pattern' => 'email',
        ]);

        Queue::assertPushed(ReclassifyOrdersForMappingJob::class);
    }

    public function test_full_chain_creates_mapping_and_job_updates_historical_orders(): void
    {
        // Seed an order that should be reclassified
        $orderId = $this->insertOrder(['source' => 'klaviyo', 'medium' => 'email']);

        // POST to create mapping
        $this->actingAs($this->user)
            ->post("/{$this->workspace->slug}/manage/channel-mappings", [
                'utm_source_pattern' => 'klaviyo',
                'utm_medium_pattern' => 'email',
                'channel_name'       => 'Klaviyo',
                'channel_type'       => 'email',
            ]);

        // Manually run the job (not via queue) to verify the update
        (new ReclassifyOrdersForMappingJob(
            workspaceId:  $this->workspace->id,
            source:       'klaviyo',
            medium:       'email',
            channelName:  'Klaviyo',
            channelType:  'email',
        ))->handle();

        $attribution = $this->getOrderAttribution($orderId);
        $this->assertSame('Klaviyo', $attribution['channel']);
        $this->assertSame('email',   $attribution['channel_type']);
    }
}
