<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Attribution;

use App\Models\ChannelMapping;
use App\Models\Workspace;
use App\Services\Attribution\ChannelClassifierService;
use Database\Seeders\ChannelMappingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChannelClassifierServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChannelClassifierService $classifier;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChannelMappingsSeeder::class);
        $this->classifier = app(ChannelClassifierService::class);
        $this->workspace  = Workspace::factory()->create();
    }

    protected function tearDown(): void
    {
        // RefreshDatabase rolls back the DB transaction but not the Redis cache.
        // Clear the workspace cache key so tests don't bleed stale data into each other.
        Cache::forget(ChannelClassifierService::cacheKey($this->workspace->id));
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Global seed rows
    // -------------------------------------------------------------------------

    public function test_classifies_klaviyo_email_as_email_channel(): void
    {
        $result = $this->classifier->classify('klaviyo', 'email', $this->workspace->id);

        $this->assertSame('Email — Klaviyo', $result['channel_name']);
        $this->assertSame('email', $result['channel_type']);
    }

    public function test_classifies_facebook_cpc_as_paid_social(): void
    {
        $result = $this->classifier->classify('facebook', 'cpc', $this->workspace->id);

        $this->assertSame('Paid — Facebook', $result['channel_name']);
        $this->assertSame('paid_social', $result['channel_type']);
    }

    public function test_classifies_google_cpc_as_paid_search(): void
    {
        $result = $this->classifier->classify('google', 'cpc', $this->workspace->id);

        $this->assertSame('Paid — Google Ads', $result['channel_name']);
        $this->assertSame('paid_search', $result['channel_type']);
    }

    public function test_classifies_google_organic_as_organic_search(): void
    {
        $result = $this->classifier->classify('google', 'organic', $this->workspace->id);

        $this->assertSame('Organic — Google', $result['channel_name']);
        $this->assertSame('organic_search', $result['channel_type']);
    }

    public function test_classifies_direct_source_as_direct(): void
    {
        $result = $this->classifier->classify('direct', null, $this->workspace->id);

        $this->assertSame('Direct', $result['channel_name']);
        $this->assertSame('direct', $result['channel_type']);
    }

    public function test_classifies_klaviyo_sms_as_sms_channel(): void
    {
        $result = $this->classifier->classify('klaviyo', 'sms', $this->workspace->id);

        $this->assertSame('SMS — Klaviyo', $result['channel_name']);
        $this->assertSame('sms', $result['channel_type']);
    }

    // -------------------------------------------------------------------------
    // Null / unknown source
    // -------------------------------------------------------------------------

    public function test_returns_null_channel_name_when_utm_source_is_null(): void
    {
        $result = $this->classifier->classify(null, 'email', $this->workspace->id);

        $this->assertNull($result['channel_name']);
        $this->assertNull($result['channel_type']);
    }

    public function test_returns_other_type_when_no_mapping_found(): void
    {
        $result = $this->classifier->classify('unknown-platform-xyz', 'unknown-medium', $this->workspace->id);

        $this->assertNull($result['channel_name']);
        $this->assertSame('other', $result['channel_type']);
    }

    // -------------------------------------------------------------------------
    // Case-insensitivity
    // -------------------------------------------------------------------------

    public function test_classify_is_case_insensitive(): void
    {
        $result = $this->classifier->classify('Klaviyo', 'Email', $this->workspace->id);

        $this->assertSame('Email — Klaviyo', $result['channel_name']);
    }

    // -------------------------------------------------------------------------
    // Workspace override beats global row
    // -------------------------------------------------------------------------

    public function test_workspace_row_overrides_global_row(): void
    {
        // Create a workspace-specific override for klaviyo/email
        ChannelMapping::create([
            'workspace_id'        => $this->workspace->id,
            'utm_source_pattern'  => 'klaviyo',
            'utm_medium_pattern'  => 'email',
            'channel_name'        => 'Custom Klaviyo Channel',
            'channel_type'        => 'email',
            'is_global'           => false,
        ]);

        $result = $this->classifier->classify('klaviyo', 'email', $this->workspace->id);

        $this->assertSame('Custom Klaviyo Channel', $result['channel_name']);
    }

    public function test_workspace_override_does_not_affect_other_workspaces(): void
    {
        $otherWorkspace = Workspace::factory()->create();

        ChannelMapping::create([
            'workspace_id'        => $this->workspace->id,
            'utm_source_pattern'  => 'klaviyo',
            'utm_medium_pattern'  => 'email',
            'channel_name'        => 'Custom Klaviyo Channel',
            'channel_type'        => 'email',
            'is_global'           => false,
        ]);

        $result = $this->classifier->classify('klaviyo', 'email', $otherWorkspace->id);

        // Other workspace still sees the global row
        $this->assertSame('Email — Klaviyo', $result['channel_name']);
    }

    // -------------------------------------------------------------------------
    // NULL medium wildcard
    // -------------------------------------------------------------------------

    public function test_null_medium_wildcard_matches_when_no_exact_medium_row_exists(): void
    {
        // Seed a row with NULL medium for a custom source
        ChannelMapping::create([
            'workspace_id'        => $this->workspace->id,
            'utm_source_pattern'  => 'mychannel',
            'utm_medium_pattern'  => null,
            'channel_name'        => 'My Channel',
            'channel_type'        => 'other',
            'is_global'           => false,
        ]);

        $result = $this->classifier->classify('mychannel', 'anything', $this->workspace->id);

        $this->assertSame('My Channel', $result['channel_name']);
    }

    public function test_exact_medium_beats_null_medium_wildcard(): void
    {
        // Wildcard (NULL medium)
        ChannelMapping::create([
            'workspace_id'        => $this->workspace->id,
            'utm_source_pattern'  => 'mychannel',
            'utm_medium_pattern'  => null,
            'channel_name'        => 'My Channel Wildcard',
            'channel_type'        => 'other',
            'is_global'           => false,
        ]);

        // Exact medium match
        ChannelMapping::create([
            'workspace_id'        => $this->workspace->id,
            'utm_source_pattern'  => 'mychannel',
            'utm_medium_pattern'  => 'newsletter',
            'channel_name'        => 'My Channel Newsletter',
            'channel_type'        => 'email',
            'is_global'           => false,
        ]);

        $result = $this->classifier->classify('mychannel', 'newsletter', $this->workspace->id);

        $this->assertSame('My Channel Newsletter', $result['channel_name']);
    }

    // -------------------------------------------------------------------------
    // Regex rows (is_regex=true on global seed rows)
    // -------------------------------------------------------------------------

    public function test_regex_row_matches_google_country_tld(): void
    {
        $result = $this->classifier->classify('google.de', null, $this->workspace->id);

        $this->assertSame('Organic — Google', $result['channel_name']);
        $this->assertSame('organic_search', $result['channel_type']);
    }

    public function test_regex_row_matches_google_co_uk(): void
    {
        $result = $this->classifier->classify('google.co.uk', null, $this->workspace->id);

        $this->assertSame('Organic — Google', $result['channel_name']);
        $this->assertSame('organic_search', $result['channel_type']);
    }

    public function test_regex_row_matches_facebook_link_shrinker(): void
    {
        foreach (['l.facebook.com', 'm.facebook.com', 'lm.facebook.com'] as $source) {
            Cache::forget(ChannelClassifierService::cacheKey($this->workspace->id));
            $result = $this->classifier->classify($source, null, $this->workspace->id);
            $this->assertSame('Social — Facebook', $result['channel_name'], "Failed for source: {$source}");
        }
    }

    public function test_regex_row_matches_instagram_link_shrinker(): void
    {
        $result = $this->classifier->classify('l.instagram.com', null, $this->workspace->id);

        $this->assertSame('Social — Instagram', $result['channel_name']);
        $this->assertSame('organic_social', $result['channel_type']);
    }

    public function test_regex_row_does_not_match_unrelated_source(): void
    {
        // The google TLD regex is anchored with ^…$ so "mygoogle.de" must not match.
        $result = $this->classifier->classify('mygoogle.de', null, $this->workspace->id);

        $this->assertNull($result['channel_name']);
        $this->assertSame('other', $result['channel_type']);
    }

    public function test_literal_google_com_row_still_matches(): void
    {
        // google.com is a literal row that sits alongside the regex row in the seeder.
        // It must still resolve correctly.
        $result = $this->classifier->classify('google.com', null, $this->workspace->id);

        $this->assertSame('Organic — Google', $result['channel_name']);
        $this->assertSame('organic_search', $result['channel_type']);
    }

    // -------------------------------------------------------------------------
    // Cache behaviour
    // -------------------------------------------------------------------------

    public function test_cache_is_populated_after_first_classify_call(): void
    {
        Cache::forget(ChannelClassifierService::cacheKey($this->workspace->id));
        Cache::forget(ChannelClassifierService::GLOBAL_CACHE_KEY);

        $this->classifier->classify('klaviyo', 'email', $this->workspace->id);

        // Global rows (workspace_id IS NULL) are now cached under the shared GLOBAL_CACHE_KEY.
        $globalCached = Cache::get(ChannelClassifierService::GLOBAL_CACHE_KEY);
        $this->assertIsArray($globalCached);
        $this->assertNotEmpty($globalCached);
    }

    public function test_second_classify_call_does_not_hit_the_database(): void
    {
        // Warm the cache with a first call.
        $this->classifier->classify('klaviyo', 'email', $this->workspace->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->classifier->classify('google', 'cpc', $this->workspace->id);

        $this->assertSame(0, $queryCount, 'Expected zero DB queries on cache hit.');
    }
}
