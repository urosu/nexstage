<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Campaign;
use App\Models\SearchConsoleProperty;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for opportunity badge detection on the /seo page (Phase 3.3).
 *
 * Verifies:
 *   - striking_distance badge fires for position 11–20 + impressions ≥ 100
 *   - leaking badge fires for top-5 position + CTR below benchmark × 0.7
 *   - rising badge fires for impressions WoW ≥ +50% AND current ≥ 100
 *   - paid_organic_overlap badge fires when query matches active campaign target
 *   - Badge precedence is enforced: paid_organic_overlap > leaking > striking_distance > rising
 *   - opportunities panel counts are populated correctly
 *
 * @see app/Http/Controllers/SeoController.php
 * @see PROGRESS.md Phase 3.3 §F14, §F15, §F17
 */
class SeoOpportunityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private SearchConsoleProperty $property;

    /** Today used consistently for all date math in these tests. */
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user      = User::factory()->create();
        $this->workspace = Workspace::factory()->create([
            'owner_id' => $this->user->id,
            'has_gsc'  => true,   // satisfies EnsureOnboardingComplete (GSC-only path)
        ]);

        WorkspaceUser::factory()->owner()->create([
            'user_id'      => $this->user->id,
            'workspace_id' => $this->workspace->id,
        ]);

        $this->property = SearchConsoleProperty::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'property_url' => 'https://example.com',
            'status'       => 'active',
        ]);

        $this->today = Carbon::today()->toDateString();

        // Seed benchmark table — RefreshDatabase wipes it, so insert directly.
        DB::table('gsc_ctr_benchmarks')->insertOrIgnore([
            ['position_bucket' => '1',     'expected_ctr' => 0.2700, 'created_at' => now(), 'updated_at' => now()],
            ['position_bucket' => '2',     'expected_ctr' => 0.1500, 'created_at' => now(), 'updated_at' => now()],
            ['position_bucket' => '3',     'expected_ctr' => 0.1100, 'created_at' => now(), 'updated_at' => now()],
            ['position_bucket' => '4-5',   'expected_ctr' => 0.0750, 'created_at' => now(), 'updated_at' => now()],
            ['position_bucket' => '6-10',  'expected_ctr' => 0.0350, 'created_at' => now(), 'updated_at' => now()],
            ['position_bucket' => '11-20', 'expected_ctr' => 0.0120, 'created_at' => now(), 'updated_at' => now()],
            ['position_bucket' => '21+',   'expected_ctr' => 0.0050, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Insert a GSC query row at a given date (always device='all', country='ZZ').
     */
    private function insertQuery(
        string $query,
        float $position,
        int $clicks,
        int $impressions,
        string $date,
    ): void {
        DB::table('gsc_queries')->insert([
            'property_id'  => $this->property->id,
            'workspace_id' => $this->workspace->id,
            'date'         => $date,
            'query'        => $query,
            'device'       => 'all',
            'country'      => 'ZZ',
            'clicks'       => $clicks,
            'impressions'  => $impressions,
            'ctr'          => $impressions > 0 ? $clicks / $impressions : 0,
            'position'     => $position,
        ]);
    }

    private function visit(array $params = []): \Illuminate\Testing\TestResponse
    {
        $query = $params ? '?' . http_build_query($params) : '';
        return $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/seo{$query}");
    }

    /**
     * Extract Inertia props from the response using the toArray() assertion pattern.
     *
     * @return array<string,mixed>
     */
    private function inertiaProps(\Illuminate\Testing\TestResponse $response): array
    {
        $captured = [];
        $response->assertInertia(function ($page) use (&$captured) {
            $captured = $page->toArray()['props'] ?? [];
            return $page;
        });
        return $captured;
    }

    /**
     * Find the opportunity badge for a query by name.
     *
     * @param  array<array<string,mixed>> $topQueries
     */
    private function opportunityFor(string $queryText, array $topQueries): ?string
    {
        foreach ($topQueries as $row) {
            if ($row['query'] === $queryText) {
                return $row['opportunity'] ?? null;
            }
        }
        return null;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function striking_distance_badge_fires_for_position_11_to_20_with_sufficient_impressions(): void
    {
        $this->insertQuery('best coffee shops', 15.0, 10, 200, $this->today);

        $response = $this->visit(['from' => $this->today, 'to' => $this->today]);
        $response->assertStatus(200);

        $props = $this->inertiaProps($response);
        $this->assertSame('striking_distance', $this->opportunityFor('best coffee shops', $props['top_queries']));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function leaking_badge_fires_for_top5_position_with_ctr_below_benchmark(): void
    {
        // Position 3 → benchmark 0.11. CTR = 2/500 = 0.004 (< 0.11 × 0.7 = 0.077).
        $this->insertQuery('top quality widgets', 3.0, 2, 500, $this->today);

        $response = $this->visit(['from' => $this->today, 'to' => $this->today]);
        $response->assertStatus(200);

        $props = $this->inertiaProps($response);
        $this->assertSame('leaking', $this->opportunityFor('top quality widgets', $props['top_queries']));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function rising_badge_fires_when_impressions_increase_50_percent_wow(): void
    {
        $recentStart = Carbon::today()->subDays(6)->toDateString();
        $prevStart   = Carbon::today()->subDays(13)->toDateString();

        // Prior week: 50 impressions. Recent week: 300 (6× = ≥150% growth).
        $this->insertQuery('buy shoes online', 8.0, 5, 300, $recentStart);
        $this->insertQuery('buy shoes online', 8.0, 2, 50,  $prevStart);

        $response = $this->visit(['from' => $prevStart, 'to' => $this->today]);
        $response->assertStatus(200);

        $props = $this->inertiaProps($response);
        $this->assertSame('rising', $this->opportunityFor('buy shoes online', $props['top_queries']));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paid_organic_overlap_badge_fires_when_query_matches_active_campaign_target(): void
    {
        $this->insertQuery('blue widget', 4.0, 20, 300, $this->today);

        Campaign::factory()->create([
            'workspace_id'      => $this->workspace->id,
            'status'            => 'ACTIVE',
            'parsed_convention' => ['target' => 'blue widget'],
        ]);

        $response = $this->visit(['from' => $this->today, 'to' => $this->today]);
        $response->assertStatus(200);

        $props = $this->inertiaProps($response);
        $this->assertSame('paid_organic_overlap', $this->opportunityFor('blue widget', $props['top_queries']));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paid_organic_overlap_takes_precedence_over_leaking(): void
    {
        // Qualifies as both leaking (top-5, CTR 0.0025 << 0.15×0.7) AND paid-organic overlap.
        // Precedence rule: paid_organic_overlap > leaking.
        $this->insertQuery('red gadget', 2.0, 1, 400, $this->today);

        Campaign::factory()->create([
            'workspace_id'      => $this->workspace->id,
            'status'            => 'ACTIVE',
            'parsed_convention' => ['target' => 'red gadget'],
        ]);

        $response = $this->visit(['from' => $this->today, 'to' => $this->today]);
        $response->assertStatus(200);

        $props = $this->inertiaProps($response);
        $this->assertSame('paid_organic_overlap', $this->opportunityFor('red gadget', $props['top_queries']));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function opportunities_panel_contains_correct_counts(): void
    {
        $this->insertQuery('striking query', 14.0, 5, 150, $this->today);  // striking_distance
        $this->insertQuery('leaking query',   2.0, 1, 500, $this->today);  // leaking: CTR 0.002 < 0.15×0.7

        $response = $this->visit(['from' => $this->today, 'to' => $this->today]);
        $response->assertStatus(200);

        $props         = $this->inertiaProps($response);
        $opportunities = $props['opportunities'];

        $trendingTypes  = array_column($opportunities['trending_up'],    'type');
        $attentionTypes = array_column($opportunities['needs_attention'], 'type');

        $this->assertContains('striking_distance', $trendingTypes);
        $this->assertContains('leaking',           $attentionTypes);

        $sdItem = array_values(
            array_filter($opportunities['trending_up'], fn ($i) => $i['type'] === 'striking_distance')
        )[0] ?? null;
        $this->assertNotNull($sdItem);
        $this->assertGreaterThanOrEqual(1, $sdItem['count']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function page_returns_200_and_required_inertia_props(): void
    {
        $response = $this->visit();
        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('opportunities')
                ->has('top_queries')
                ->has('narrative')
            );
    }
}
