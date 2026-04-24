<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for GET /{workspace}/performance — Phase 3.4 revenue-impact additions.
 *
 * Verifies:
 *   - New Inertia props (revenue_at_risk, performance_audits, performance_alerts, narrative)
 *   - §F19 revenue-at-risk computation (positive when CVR degrades)
 *   - §F18 monthly orders per URL
 *   - Alert detection: score drop, LCP regression
 *   - Audit list sorting by score impact
 *   - Non-members are redirected
 *
 * @see app/Http/Controllers/PerformanceController.php
 * @see app/Services/PerformanceMonitoring/PerformanceRevenueService.php
 * @see PROGRESS.md Phase 3.4 §F18, §F19
 */
class PerformancePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private Store $store;
    private int $storeUrlId;

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

        $this->storeUrlId = DB::table('store_urls')->insertGetId([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'url'          => 'https://example.com/collections/shoes',
            'label'        => 'Shoes',
            'is_homepage'  => false,
            'is_active'    => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Seed a basic Lighthouse snapshot so the page renders in "has data" mode.
        $this->insertSnapshot(['performance_score' => 80, 'lcp_ms' => 3000]);
    }

    private function visit(array $params = []): \Illuminate\Testing\TestResponse
    {
        $query = $params ? '?' . http_build_query($params) : '';

        return $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/performance{$query}");
    }

    /**
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

    /** Insert a Lighthouse snapshot for the test store URL. */
    private function insertSnapshot(array $overrides = []): void
    {
        DB::table('lighthouse_snapshots')->insert(array_merge([
            'workspace_id'   => $this->workspace->id,
            'store_id'       => $this->store->id,
            'store_url_id'   => $this->storeUrlId,
            'strategy'       => 'mobile',
            'performance_score' => 75,
            'lcp_ms'         => 2800,
            'cls_score'      => 0.05,
            'inp_ms'         => 180,
            'seo_score'      => 90,
            'accessibility_score' => 85,
            'best_practices_score' => 92,
            'raw_response'   => null,
            'checked_at'     => now()->subHours(2),
            'created_at'     => now()->subHours(2),
        ], $overrides));
    }

    /** Insert a GSC page row (device='all', country='ZZ' sentinels). Uses insertOrIgnore. */
    private function insertGscPage(
        SearchConsoleProperty $property,
        string $page,
        int $clicks,
        string $date,
    ): void {
        DB::table('gsc_pages')->insertOrIgnore([
            'property_id'  => $property->id,
            'workspace_id' => $this->workspace->id,
            'date'         => $date,
            'page'         => $page,
            'page_hash'    => hash('sha256', $page),
            'device'       => 'all',
            'country'      => 'ZZ',
            'clicks'       => $clicks,
            'impressions'  => $clicks * 10,
            'ctr'          => 0.10,
            'position'     => 5.0,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** Insert an organic order attributed to the test URL path. */
    private function insertOrganicOrder(float $total, \DateTimeInterface $occurredAt): int
    {
        return DB::table('orders')->insertGetId([
            'workspace_id'                => $this->workspace->id,
            'store_id'                    => $this->store->id,
            'external_id'                 => (string) random_int(1_000, 9_999_999),
            'external_number'             => '100',
            'status'                      => 'completed',
            'currency'                    => 'EUR',
            'total'                       => $total,
            'subtotal'                    => $total,
            'tax'                         => 0,
            'shipping'                    => 0,
            'discount'                    => 0,
            'total_in_reporting_currency' => $total,
            'attribution_last_touch'      => json_encode([
                'channel_type' => 'organic_search',
                'landing_page' => 'https://example.com/collections/shoes?ref=google',
            ]),
            'occurred_at' => $occurredAt,
            'synced_at'   => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function page_renders_200_for_workspace_member(): void
    {
        $response = $this->visit();
        $response->assertStatus(200);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function required_inertia_props_are_present(): void
    {
        $response = $this->visit();
        $response->assertStatus(200);

        $props = $this->inertiaProps($response);

        $this->assertArrayHasKey('revenue_at_risk',    $props);
        $this->assertArrayHasKey('performance_audits', $props);
        $this->assertArrayHasKey('performance_alerts', $props);
        $this->assertArrayHasKey('narrative',          $props);
        $this->assertIsNumeric($props['revenue_at_risk']);
        $this->assertIsArray($props['performance_audits']);
        $this->assertIsArray($props['performance_alerts']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function revenue_at_risk_is_zero_when_no_gsc_property(): void
    {
        // No GSC property → service returns early with 0.
        $props = $this->inertiaProps($this->visit());
        $this->assertSame(0.0, (float) $props['revenue_at_risk']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function revenue_at_risk_is_positive_when_cvr_degrades(): void
    {
        $property = SearchConsoleProperty::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'property_url' => 'https://example.com',
            'status'       => 'active',
        ]);

        $urlPath = '/collections/shoes';

        // Baseline window: days -35 to -8.
        // 28 organic orders across 7 days (4/day), 100 GSC clicks (one row/day × 100 clicks/day) → 28% CVR.
        foreach (range(20, 26) as $daysAgo) {
            $this->insertOrganicOrder(100.0, now()->subDays($daysAgo));
            $this->insertOrganicOrder(100.0, now()->subDays($daysAgo));
            $this->insertOrganicOrder(100.0, now()->subDays($daysAgo));
            $this->insertOrganicOrder(100.0, now()->subDays($daysAgo));
            // One GSC row per day, each carrying ~14 clicks → 7 days × 14 ≈ 98 clicks total.
            $this->insertGscPage($property, "https://example.com{$urlPath}", 14, now()->subDays($daysAgo)->toDateString());
        }

        // Current window: last 7 days.
        // 7 organic orders across 7 days (1/day), ~98 GSC clicks → ~7% CVR.
        foreach (range(1, 7) as $daysAgo) {
            $this->insertOrganicOrder(100.0, now()->subDays($daysAgo));
            $this->insertGscPage($property, "https://example.com{$urlPath}", 14, now()->subDays($daysAgo)->toDateString());
        }

        $props = $this->inertiaProps($this->visit());
        $risk  = (float) $props['revenue_at_risk'];

        // CVR baseline ≈ 28/98 ≈ 28.6%, current ≈ 7/98 ≈ 7.1%. Sessions = 98. AOV = 100.
        // Expected risk ≈ (0.286 - 0.071) × 98 × 100 ≈ €2,107. Allow wide range for boundary effects.
        $this->assertGreaterThan(1_000, $risk, 'Revenue at risk should be significantly positive');
        $this->assertLessThan(4_000, $risk, 'Revenue at risk should be in expected range');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function revenue_at_risk_is_zero_when_cvr_improves(): void
    {
        $property = SearchConsoleProperty::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'property_url' => 'https://example.com',
            'status'       => 'active',
        ]);

        $urlPath = '/collections/shoes';

        // Baseline: 5% CVR. Current: 20% CVR (improvement → no risk).
        foreach (range(1, 5) as $_) {
            $this->insertOrganicOrder(100.0, now()->subDays(20));
        }
        $this->insertGscPage($property, "https://example.com{$urlPath}", 100, now()->subDays(20)->toDateString());

        foreach (range(1, 20) as $_) {
            $this->insertOrganicOrder(100.0, now()->subDays(3));
        }
        $this->insertGscPage($property, "https://example.com{$urlPath}", 100, now()->subDays(3)->toDateString());

        $props = $this->inertiaProps($this->visit());
        $this->assertSame(0.0, (float) $props['revenue_at_risk']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function monthly_orders_count_is_correct(): void
    {
        // 3 organic orders this month for the shoes URL.
        foreach (range(1, 3) as $_) {
            $this->insertOrganicOrder(100.0, now()->subDays(2));
        }
        // 1 order last month (should not count).
        $this->insertOrganicOrder(100.0, now()->subMonths(1)->startOfMonth()->addDays(5));

        $props      = $this->inertiaProps($this->visit());
        $urlSummary = $props['url_summary'] ?? [];

        // The test store URL should appear in the summary.
        $row = collect($urlSummary)->firstWhere('id', $this->storeUrlId);
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row['monthly_orders']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function score_drop_alert_fires_when_drop_exceeds_10_pts(): void
    {
        // Prior snapshot (7–14 days ago): performance_score = 96.
        // Latest (setUp): performance_score = 80. Drop = 16 pts (> 10 threshold).
        DB::table('lighthouse_snapshots')->insert([
            'workspace_id'        => $this->workspace->id,
            'store_id'            => $this->store->id,
            'store_url_id'        => $this->storeUrlId,
            'strategy'            => 'mobile',
            'performance_score'   => 96,
            'lcp_ms'              => 2000,
            'cls_score'           => 0.05,
            'inp_ms'              => 150,
            'seo_score'           => 90,
            'accessibility_score' => 85,
            'best_practices_score'=> 92,
            'raw_response'        => null,
            'checked_at'          => now()->subDays(10),
            'created_at'          => now()->subDays(10),
        ]);

        $props  = $this->inertiaProps($this->visit());
        $alerts = $props['performance_alerts'] ?? [];

        $scoreDrop = collect($alerts)->firstWhere('type', 'score_drop');
        $this->assertNotNull($scoreDrop, 'Expected a score_drop alert');
        $this->assertSame($this->storeUrlId, (int) $scoreDrop['url_id']);
        $this->assertSame(16, (int) $scoreDrop['delta']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function lcp_regression_alert_fires_when_regression_exceeds_500ms(): void
    {
        // Prior snapshot (7–14 days ago): lcp_ms = 2000.
        // Latest (setUp): lcp_ms = 3000. Regression = 1000ms (> 500 threshold).
        DB::table('lighthouse_snapshots')->insert([
            'workspace_id'        => $this->workspace->id,
            'store_id'            => $this->store->id,
            'store_url_id'        => $this->storeUrlId,
            'strategy'            => 'mobile',
            'performance_score'   => 80,
            'lcp_ms'              => 2000,
            'cls_score'           => 0.05,
            'inp_ms'              => 150,
            'seo_score'           => 90,
            'accessibility_score' => 85,
            'best_practices_score'=> 92,
            'raw_response'        => null,
            'checked_at'          => now()->subDays(10),
            'created_at'          => now()->subDays(10),
        ]);

        $props  = $this->inertiaProps($this->visit());
        $alerts = $props['performance_alerts'] ?? [];

        $lcpAlert = collect($alerts)->firstWhere('type', 'lcp_regression');
        $this->assertNotNull($lcpAlert, 'Expected an lcp_regression alert');
        $this->assertSame($this->storeUrlId, (int) $lcpAlert['url_id']);
        $this->assertSame(1000, (int) $lcpAlert['delta']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function no_alerts_when_metrics_are_stable(): void
    {
        // Prior snapshot with same scores as latest (score=75, lcp=2800).
        DB::table('lighthouse_snapshots')->insert([
            'workspace_id'        => $this->workspace->id,
            'store_id'            => $this->store->id,
            'store_url_id'        => $this->storeUrlId,
            'strategy'            => 'mobile',
            'performance_score'   => 75,
            'lcp_ms'              => 2800,
            'cls_score'           => 0.05,
            'inp_ms'              => 150,
            'seo_score'           => 90,
            'accessibility_score' => 85,
            'best_practices_score'=> 92,
            'raw_response'        => null,
            'checked_at'          => now()->subDays(10),
            'created_at'          => now()->subDays(10),
        ]);

        $props  = $this->inertiaProps($this->visit());
        $alerts = $props['performance_alerts'] ?? [];

        // Drop = 0 pts (< 10 threshold), regression = 0ms (< 500 threshold).
        $this->assertEmpty($alerts);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function audit_list_sorted_by_impact(): void
    {
        // Build a raw_response with two failing audits:
        //   audit-A: weight=10, score=0.5  → impact = 10 × 0.5 = 5.0
        //   audit-B: weight=3,  score=0.1  → impact = 3  × 0.9 = 2.7
        // audit-A should appear first.
        $rawResponse = [
            'lighthouseResult' => [
                'categories' => [
                    'performance' => [
                        'auditRefs' => [
                            ['id' => 'audit-a', 'weight' => 10],
                            ['id' => 'audit-b', 'weight' => 3],
                        ],
                    ],
                ],
                'audits' => [
                    'audit-a' => ['score' => 0.5, 'title' => 'Audit A', 'displayValue' => '500ms'],
                    'audit-b' => ['score' => 0.1, 'title' => 'Audit B', 'displayValue' => '1.5s'],
                ],
            ],
        ];

        // Replace the snapshot inserted in setUp with one that has raw_response.
        DB::table('lighthouse_snapshots')->where('store_url_id', $this->storeUrlId)->delete();
        DB::table('lighthouse_snapshots')->insert([
            'workspace_id'        => $this->workspace->id,
            'store_id'            => $this->store->id,
            'store_url_id'        => $this->storeUrlId,
            'strategy'            => 'mobile',
            'performance_score'   => 75,
            'lcp_ms'              => 2800,
            'cls_score'           => 0.05,
            'inp_ms'              => 150,
            'seo_score'           => 90,
            'accessibility_score' => 85,
            'best_practices_score'=> 92,
            'raw_response'        => json_encode($rawResponse),
            'checked_at'          => now()->subHours(1),
            'created_at'          => now()->subHours(1),
        ]);

        $props  = $this->inertiaProps($this->visit());
        $audits = $props['performance_audits'] ?? [];

        $this->assertCount(2, $audits, 'Both failing audits should be returned');
        $this->assertSame('audit-a', $audits[0]['id'], 'Higher-impact audit should be first');
        $this->assertSame('audit-b', $audits[1]['id'], 'Lower-impact audit should be second');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function non_member_is_redirected(): void
    {
        $other = User::factory()->create();
        $response = $this->actingAs($other)
            ->get("/{$this->workspace->slug}/performance");

        $response->assertRedirect();
    }
}
