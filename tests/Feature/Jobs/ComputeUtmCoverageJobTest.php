<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ComputeUtmCoverageJob;
use App\Models\Order;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for Phase 1.5 Step 15 — UTM parsing coverage calculation.
 *
 * Covers ComputeUtmCoverageJob:
 *   - Computes correct coverage percentage from last 30 days of orders
 *   - Maps pct → status (green ≥80%, amber 50–79%, red <50%)
 *   - Writes utm_coverage_pct, utm_coverage_status, utm_coverage_checked_at to workspace
 *   - Skips workspace when has_store=false or has_ads=false
 *   - No orders → green (nothing to misattribute)
 *   - Orders outside 30-day window are excluded from calculation
 */
class ComputeUtmCoverageJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        // Workspace with both store and ads so coverage is meaningful.
        $this->workspace = Workspace::factory()->create([
            'has_store' => true,
            'has_ads'   => true,
            'reporting_currency' => 'EUR',
        ]);

        $this->store = Store::factory()->create(['workspace_id' => $this->workspace->id]);

        app(WorkspaceContext::class)->set($this->workspace->id);
    }

    // =========================================================================
    // Status thresholds
    // =========================================================================

    public function test_100_percent_coverage_is_green(): void
    {
        // All orders tagged via PYS or WC native.
        $this->insertOrders(5, 'pys');

        (new ComputeUtmCoverageJob($this->workspace->id))->handle(
            app(\App\Services\RevenueAttributionService::class),
        );

        $this->workspace->refresh();

        $this->assertEqualsWithDelta(100.0, (float) $this->workspace->utm_coverage_pct, 0.01);
        $this->assertSame('green', $this->workspace->utm_coverage_status);
    }

    public function test_80_percent_coverage_is_green(): void
    {
        $this->insertOrders(8, 'pys');
        $this->insertOrders(2, 'referrer'); // not tagged

        (new ComputeUtmCoverageJob($this->workspace->id))->handle(
            app(\App\Services\RevenueAttributionService::class),
        );

        $this->workspace->refresh();

        $this->assertEqualsWithDelta(80.0, (float) $this->workspace->utm_coverage_pct, 0.01);
        $this->assertSame('green', $this->workspace->utm_coverage_status);
    }

    public function test_60_percent_coverage_is_amber(): void
    {
        $this->insertOrders(6, 'wc_native');
        $this->insertOrders(4, 'referrer');

        (new ComputeUtmCoverageJob($this->workspace->id))->handle(
            app(\App\Services\RevenueAttributionService::class),
        );

        $this->workspace->refresh();

        $this->assertEqualsWithDelta(60.0, (float) $this->workspace->utm_coverage_pct, 0.01);
        $this->assertSame('amber', $this->workspace->utm_coverage_status);
    }

    public function test_30_percent_coverage_is_red(): void
    {
        $this->insertOrders(3, 'pys');
        $this->insertOrders(7, 'referrer');

        (new ComputeUtmCoverageJob($this->workspace->id))->handle(
            app(\App\Services\RevenueAttributionService::class),
        );

        $this->workspace->refresh();

        $this->assertEqualsWithDelta(30.0, (float) $this->workspace->utm_coverage_pct, 0.01);
        $this->assertSame('red', $this->workspace->utm_coverage_status);
    }

    // =========================================================================
    // Skip conditions
    // =========================================================================

    public function test_skips_workspace_without_store(): void
    {
        $this->workspace->update(['has_store' => false, 'has_ads' => true]);

        $this->insertOrders(5, 'referrer');

        (new ComputeUtmCoverageJob($this->workspace->id))->handle(
            app(\App\Services\RevenueAttributionService::class),
        );

        $this->workspace->refresh();

        // Coverage fields must remain untouched.
        $this->assertNull($this->workspace->utm_coverage_pct);
        $this->assertNull($this->workspace->utm_coverage_status);
    }

    public function test_skips_workspace_without_ads(): void
    {
        $this->workspace->update(['has_store' => true, 'has_ads' => false]);

        $this->insertOrders(5, 'pys');

        (new ComputeUtmCoverageJob($this->workspace->id))->handle(
            app(\App\Services\RevenueAttributionService::class),
        );

        $this->workspace->refresh();

        $this->assertNull($this->workspace->utm_coverage_pct);
        $this->assertNull($this->workspace->utm_coverage_status);
    }

    public function test_no_orders_returns_green(): void
    {
        // No orders seeded — nothing to misattribute.
        (new ComputeUtmCoverageJob($this->workspace->id))->handle(
            app(\App\Services\RevenueAttributionService::class),
        );

        $this->workspace->refresh();

        $this->assertEqualsWithDelta(100.0, (float) $this->workspace->utm_coverage_pct, 0.01);
        $this->assertSame('green', $this->workspace->utm_coverage_status);
        $this->assertNotNull($this->workspace->utm_coverage_checked_at);
    }

    // =========================================================================
    // Date window
    // =========================================================================

    public function test_excludes_orders_older_than_30_days(): void
    {
        // Order older than 30 days with no attribution — must not reduce coverage.
        $this->insertOrders(1, 'referrer', occurredAt: now()->subDays(35));

        // Recent order tagged via PYS.
        $this->insertOrders(1, 'pys', occurredAt: now()->subDays(5));

        (new ComputeUtmCoverageJob($this->workspace->id))->handle(
            app(\App\Services\RevenueAttributionService::class),
        );

        $this->workspace->refresh();

        // Only the recent order counts → 100% coverage.
        $this->assertEqualsWithDelta(100.0, (float) $this->workspace->utm_coverage_pct, 0.01);
        $this->assertSame('green', $this->workspace->utm_coverage_status);
    }

    public function test_writes_checked_at_timestamp(): void
    {
        (new ComputeUtmCoverageJob($this->workspace->id))->handle(
            app(\App\Services\RevenueAttributionService::class),
        );

        $this->workspace->refresh();

        $this->assertNotNull($this->workspace->utm_coverage_checked_at);
        $this->assertTrue($this->workspace->utm_coverage_checked_at->isToday());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function insertOrders(int $count, string $attributionSource, ?\Carbon\Carbon $occurredAt = null): void
    {
        $at = ($occurredAt ?? now()->subDays(5))->toDateTimeString();

        for ($i = 0; $i < $count; $i++) {
            DB::table('orders')->insert([
                'workspace_id'       => $this->workspace->id,
                'store_id'           => $this->store->id,
                'external_id'        => uniqid('order-', true),
                'status'             => 'completed',
                'currency'           => 'EUR',
                'total'              => 99.00,
                'subtotal'           => 90.00,
                'tax'                => 9.00,
                'shipping'           => 0,
                'discount'           => 0,
                'attribution_source' => $attributionSource,
                'occurred_at'        => $at,
                'synced_at'          => now()->toDateTimeString(),
                'created_at'         => now()->toDateTimeString(),
                'updated_at'         => now()->toDateTimeString(),
            ]);
        }
    }
}
