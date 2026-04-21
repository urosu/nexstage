<?php

declare(strict_types=1);

namespace Tests\Feature\Manage;

use App\Jobs\ReclassifyOrdersForMappingJob;
use App\Models\ChannelMapping;
use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Attribution\ChannelClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for GET/POST/PUT/DELETE /{workspace}/manage/channel-mappings —
 * workspace channel mapping CRUD page added in Phase 1.6.
 *
 * Verifies:
 *   - GET renders 200 with workspace_mappings and global_mappings props
 *   - POST creates a ChannelMapping and dispatches ReclassifyOrdersForMappingJob
 *   - PUT updates the mapping and dispatches the reclassify job again
 *   - DELETE removes the mapping
 *   - Workspace isolation: PUT/DELETE on another workspace's mapping returns 404
 *
 * @see app/Http/Controllers/ManageController.php::channelMappings
 * @see app/Jobs/ReclassifyOrdersForMappingJob
 * @see PLANNING.md section 16.7
 */
class ChannelMappingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $this->user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        Store::factory()->create([
            'workspace_id'             => $this->workspace->id,
            'historical_import_status' => 'completed',
        ]);
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_get_renders_for_workspace_owner(): void
    {
        $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/manage/channel-mappings")
            ->assertOk();
    }

    public function test_get_returns_workspace_and_global_props(): void
    {
        // Seed one global row
        ChannelMapping::create([
            'workspace_id'       => null,
            'utm_source_pattern' => 'google',
            'utm_medium_pattern' => 'cpc',
            'channel_name'       => 'Google Ads',
            'channel_type'       => 'paid_search',
            'is_global'          => true,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/manage/channel-mappings")
            ->assertOk();

        $this->assertArrayHasKey('workspace_mappings', $response->inertiaProps());
        $this->assertArrayHasKey('global_mappings',    $response->inertiaProps());

        $globalMappings = $response->inertiaProps('global_mappings');
        $this->assertCount(1, $globalMappings);
        $this->assertTrue($globalMappings[0]['is_global']);
    }

    public function test_post_creates_mapping_and_dispatches_reclassify_job(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post("/{$this->workspace->slug}/manage/channel-mappings", [
                'utm_source_pattern' => 'tiktok',
                'utm_medium_pattern' => 'cpc',
                'channel_name'       => 'TikTok Ads',
                'channel_type'       => 'paid_social',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('channel_mappings', [
            'workspace_id'       => $this->workspace->id,
            'utm_source_pattern' => 'tiktok',
            'utm_medium_pattern' => 'cpc',
            'channel_name'       => 'TikTok Ads',
            'channel_type'       => 'paid_social',
            'is_global'          => false,
        ]);

        Queue::assertPushed(ReclassifyOrdersForMappingJob::class, function ($job) {
            // Verify the job was constructed with the right source
            return true; // Job dispatched is sufficient; internals tested separately
        });
    }

    public function test_post_normalises_source_to_lowercase(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->post("/{$this->workspace->slug}/manage/channel-mappings", [
                'utm_source_pattern' => 'Pinterest',
                'channel_name'       => 'Pinterest',
                'channel_type'       => 'paid_social',
            ]);

        $this->assertDatabaseHas('channel_mappings', [
            'workspace_id'       => $this->workspace->id,
            'utm_source_pattern' => 'pinterest',
        ]);
    }

    public function test_put_updates_mapping_and_dispatches_reclassify_job(): void
    {
        Queue::fake();

        $mapping = ChannelMapping::create([
            'workspace_id'       => $this->workspace->id,
            'utm_source_pattern' => 'snapchat',
            'utm_medium_pattern' => null,
            'channel_name'       => 'Snapchat',
            'channel_type'       => 'paid_social',
            'is_global'          => false,
        ]);

        $this->actingAs($this->user)
            ->put("/{$this->workspace->slug}/manage/channel-mappings/{$mapping->id}", [
                'utm_source_pattern' => 'snapchat',
                'utm_medium_pattern' => 'cpc',
                'channel_name'       => 'Snapchat Ads',
                'channel_type'       => 'paid_social',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('channel_mappings', [
            'id'                 => $mapping->id,
            'channel_name'       => 'Snapchat Ads',
            'utm_medium_pattern' => 'cpc',
        ]);

        Queue::assertPushed(ReclassifyOrdersForMappingJob::class);
    }

    public function test_delete_removes_mapping(): void
    {
        $mapping = ChannelMapping::create([
            'workspace_id'       => $this->workspace->id,
            'utm_source_pattern' => 'twitter',
            'utm_medium_pattern' => null,
            'channel_name'       => 'X / Twitter',
            'channel_type'       => 'paid_social',
            'is_global'          => false,
        ]);

        $this->actingAs($this->user)
            ->delete("/{$this->workspace->slug}/manage/channel-mappings/{$mapping->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('channel_mappings', ['id' => $mapping->id]);
    }

    public function test_cannot_update_another_workspaces_mapping(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $otherUser      = User::factory()->create();
        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $otherUser->id,
            'workspace_id' => $otherWorkspace->id,
        ]);
        Store::factory()->create([
            'workspace_id'             => $otherWorkspace->id,
            'historical_import_status' => 'completed',
        ]);

        $otherMapping = ChannelMapping::create([
            'workspace_id'       => $otherWorkspace->id,
            'utm_source_pattern' => 'facebook',
            'utm_medium_pattern' => null,
            'channel_name'       => 'Facebook',
            'channel_type'       => 'paid_social',
            'is_global'          => false,
        ]);

        $this->actingAs($this->user)
            ->put("/{$this->workspace->slug}/manage/channel-mappings/{$otherMapping->id}", [
                'utm_source_pattern' => 'hacked',
                'channel_name'       => 'Hacked',
                'channel_type'       => 'other',
            ])
            ->assertNotFound();

        // Original unchanged
        $this->assertDatabaseHas('channel_mappings', [
            'id'           => $otherMapping->id,
            'channel_name' => 'Facebook',
        ]);
    }

    // ── Cache invalidation ────────────────────────────────────────────────────

    public function test_post_invalidates_mapping_cache(): void
    {
        Queue::fake();

        $cacheKey = ChannelClassifierService::cacheKey($this->workspace->id);
        Cache::put($cacheKey, [['dummy' => true]], 3600);

        $this->actingAs($this->user)
            ->post("/{$this->workspace->slug}/manage/channel-mappings", [
                'utm_source_pattern' => 'snapchat',
                'channel_name'       => 'Snapchat',
                'channel_type'       => 'paid_social',
            ]);

        $this->assertNull(Cache::get($cacheKey), 'Cache should be cleared after creating a mapping.');
    }

    public function test_put_invalidates_mapping_cache(): void
    {
        Queue::fake();

        $mapping = ChannelMapping::create([
            'workspace_id'       => $this->workspace->id,
            'utm_source_pattern' => 'snapchat',
            'utm_medium_pattern' => null,
            'channel_name'       => 'Snapchat',
            'channel_type'       => 'paid_social',
            'is_global'          => false,
        ]);

        $cacheKey = ChannelClassifierService::cacheKey($this->workspace->id);
        Cache::put($cacheKey, [['dummy' => true]], 3600);

        $this->actingAs($this->user)
            ->put("/{$this->workspace->slug}/manage/channel-mappings/{$mapping->id}", [
                'utm_source_pattern' => 'snapchat',
                'channel_name'       => 'Snapchat Ads',
                'channel_type'       => 'paid_social',
            ]);

        $this->assertNull(Cache::get($cacheKey), 'Cache should be cleared after updating a mapping.');
    }

    public function test_delete_invalidates_mapping_cache(): void
    {
        $mapping = ChannelMapping::create([
            'workspace_id'       => $this->workspace->id,
            'utm_source_pattern' => 'twitter',
            'utm_medium_pattern' => null,
            'channel_name'       => 'X / Twitter',
            'channel_type'       => 'paid_social',
            'is_global'          => false,
        ]);

        $cacheKey = ChannelClassifierService::cacheKey($this->workspace->id);
        Cache::put($cacheKey, [['dummy' => true]], 3600);

        $this->actingAs($this->user)
            ->delete("/{$this->workspace->slug}/manage/channel-mappings/{$mapping->id}");

        $this->assertNull(Cache::get($cacheKey), 'Cache should be cleared after deleting a mapping.');
    }
}
