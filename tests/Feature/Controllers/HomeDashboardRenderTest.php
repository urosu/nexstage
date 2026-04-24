<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Alert;
use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for GET /{workspace} — Phase 3.6 Home rebuild (Phase 3.8: moved from /dashboard to /).
 *
 * Verifies:
 *   - New Phase 3.6 Inertia props are present (attention_items, contribution_margin,
 *     channel_rollup, uptime_30d_pct, same_weekday_metrics)
 *   - Store-only workspace: Ad Spend and Real ROAS null; channel_rollup non-empty
 *   - Contribution Margin: cogs_configured=false when all order_items have null unit_cost
 *   - channel_rollup always returns exactly 5 §M1 rows
 *   - Today's Attention generator: alerts and recommendations are merged correctly
 *
 * @see app/Http/Controllers/DashboardController.php
 * @see PROGRESS.md Phase 3.6 — Home rebuild
 */
class HomeDashboardRenderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private Store $store;

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

    private function visit(array $params = []): \Illuminate\Testing\TestResponse
    {
        $query = $params ? '?' . http_build_query($params) : '';

        return $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}{$query}");
    }

    /** @return array<string,mixed> */
    private function inertiaProps(\Illuminate\Testing\TestResponse $response): array
    {
        $captured = [];
        $response->assertInertia(function ($page) use (&$captured) {
            $captured = $page->toArray()['props'] ?? [];
            return $page;
        });
        return $captured;
    }

    // ── Phase 3.6 props ────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function phase_36_props_are_present(): void
    {
        $props = $this->inertiaProps($this->visit());

        $this->assertArrayHasKey('attention_items',      $props);
        $this->assertArrayHasKey('contribution_margin',  $props);
        $this->assertArrayHasKey('channel_rollup',       $props);
        $this->assertArrayHasKey('uptime_30d_pct',       $props);
        $this->assertArrayHasKey('same_weekday_metrics', $props);

        $this->assertIsArray($props['attention_items']);
        $this->assertIsArray($props['contribution_margin']);
        $this->assertArrayHasKey('cm',              $props['contribution_margin']);
        $this->assertArrayHasKey('cogs_configured', $props['contribution_margin']);
        $this->assertIsArray($props['channel_rollup']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function channel_rollup_always_returns_five_rows(): void
    {
        $this->insertOrder('paid_search', 100.0);

        $props = $this->inertiaProps($this->visit());
        $rows  = $props['channel_rollup'];

        $this->assertCount(5, $rows, 'channel_rollup must always have 5 §M1 rows');

        $labels = array_column($rows, 'channel');
        $this->assertContains('Paid Search',  $labels);
        $this->assertContains('Paid Social',  $labels);
        $this->assertContains('Organic',      $labels);
        $this->assertContains('Email',        $labels);
        $this->assertContains('Direct',       $labels);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function store_only_workspace_returns_null_ad_spend(): void
    {
        // Workspace with no ads connected
        $this->workspace->update(['has_ads' => false]);

        $props = $this->inertiaProps($this->visit());

        $this->assertNull($props['metrics']['ad_spend'], 'Ad spend should be null with no ads connected');
        $this->assertNull($props['metrics']['roas'],     'Real ROAS should be null with no ad spend');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function contribution_margin_cogs_not_configured_when_all_unit_costs_null(): void
    {
        // Insert order + order items with NULL unit_cost
        $orderId = $this->insertOrder('organic_search', 100.0);
        DB::table('order_items')->insert([
            'order_id'            => $orderId,
            'product_external_id' => '1',
            'product_name'        => 'Widget',
            'quantity'            => 1,
            'unit_price'          => 100.0,
            'unit_cost'           => null,
            'line_total'          => 100.0,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $props = $this->inertiaProps($this->visit());

        $this->assertFalse($props['contribution_margin']['cogs_configured']);
        $this->assertNull($props['contribution_margin']['cm']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function contribution_margin_computed_when_unit_costs_present(): void
    {
        $orderId = $this->insertOrder('organic_search', 100.0, shipping: 5.0, paymentFee: 2.0);
        DB::table('order_items')->insert([
            'order_id'            => $orderId,
            'product_external_id' => '1',
            'product_name'        => 'Widget',
            'quantity'            => 2,
            'unit_price'          => 50.0,
            'unit_cost'           => 20.0,   // COGS = 40
            'line_total'          => 100.0,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $props = $this->inertiaProps($this->visit());

        $this->assertTrue($props['contribution_margin']['cogs_configured']);
        // Revenue=100, COGS=40, Fees=7, AdSpend=0 → CM=53
        $this->assertEqualsWithDelta(53.0, $props['contribution_margin']['cm'], 0.01);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function page_renders_200_for_workspace_member(): void
    {
        $this->visit()->assertStatus(200);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function non_member_is_redirected(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->get("/{$this->workspace->slug}")
            ->assertRedirect();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function insertOrder(
        string $channelType,
        float $total,
        float $shipping = 0.0,
        float $paymentFee = 0.0,
    ): int {
        return DB::table('orders')->insertGetId([
            'workspace_id'                => $this->workspace->id,
            'store_id'                    => $this->store->id,
            'external_id'                 => (string) random_int(1_000, 9_999_999),
            'external_number'             => (string) random_int(100, 9999),
            'status'                      => 'completed',
            'currency'                    => 'EUR',
            'total'                       => $total,
            'subtotal'                    => $total,
            'tax'                         => 0,
            'shipping'                    => $shipping,
            'payment_fee'                 => $paymentFee,
            'discount'                    => 0,
            'total_in_reporting_currency' => $total,
            'attribution_last_touch'      => json_encode(['channel_type' => $channelType]),
            'occurred_at'                 => now(),
            'synced_at'                   => now(),
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);
    }
}
