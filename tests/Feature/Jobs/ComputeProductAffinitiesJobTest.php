<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ComputeProductAffinitiesJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for ComputeProductAffinitiesJob — Frequently-Bought-Together
 * algorithm added in Phase 1.6.
 *
 * The job uses an Apriori-style Postgres CTE over the last 90 days of
 * completed/processing orders. Each test seeds minimal order_items via DB::table
 * (order_items has no workspace_id/store_id — linked through orders.id).
 *
 * Key math for the 3-order fixture (all 3 orders contain A+B):
 *   total_orders = 3, a_orders = 3, b_orders = 3, pair_orders = 3
 *   support     = 3/3 = 1.0
 *   confidence  = 3/3 = 1.0  (asymmetric P(B|A))
 *   lift        = 1.0 / (3/3) = 1.0
 *
 * @see app/Jobs/ComputeProductAffinitiesJob
 * @see PLANNING.md section 19 (Frequently-Bought-Together)
 */
class ComputeProductAffinitiesJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private Store $store;
    private Product $productA;
    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);

        app(WorkspaceContext::class)->set($this->workspace->id);

        $this->productA = Product::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => 'pa1',
            'name'         => 'Product A',
            'slug'         => 'product-a',
            'status'       => 'publish',
        ]);

        $this->productB = Product::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => 'pb1',
            'name'         => 'Product B',
            'slug'         => 'product-b',
            'status'       => 'publish',
        ]);
    }

    private function insertOrder(
        string $status = 'completed',
        ?Carbon $occurredAt = null,
    ): int {
        $at = $occurredAt ?? now()->subDays(5);

        DB::table('orders')->insert([
            'workspace_id'                => $this->workspace->id,
            'store_id'                    => $this->store->id,
            'external_id'                 => (string) random_int(10000, 999999),
            'external_number'             => '100',
            'status'                      => $status,
            'currency'                    => 'EUR',
            'total'                       => 50.00,
            'subtotal'                    => 45.00,
            'tax'                         => 5.00,
            'shipping'                    => 0.00,
            'discount'                    => 0.00,
            'total_in_reporting_currency' => 50.00,
            'occurred_at'                 => $at,
            'synced_at'                   => now(),
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        return (int) DB::table('orders')->latest('id')->value('id');
    }

    private function attachProduct(int $orderId, string $externalId, ?float $unitCost = null): void
    {
        DB::table('order_items')->insert([
            'order_id'            => $orderId,
            'product_external_id' => $externalId,
            'product_name'        => "Product {$externalId}",
            'quantity'            => 1,
            'unit_price'          => 50.00,
            'unit_cost'           => $unitCost,
            'line_total'          => 50.00,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    private function runJob(): void
    {
        (new ComputeProductAffinitiesJob($this->workspace->id))->handle();
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_pair_in_three_orders_creates_affinity_rows_both_directions(): void
    {
        // 3 orders each containing A and B
        for ($i = 0; $i < 3; $i++) {
            $orderId = $this->insertOrder();
            $this->attachProduct($orderId, 'pa1');
            $this->attachProduct($orderId, 'pb1');
        }

        $this->runJob();

        // Both directions inserted
        $this->assertDatabaseHas('product_affinities', [
            'workspace_id' => $this->workspace->id,
            'product_a_id' => $this->productA->id,
            'product_b_id' => $this->productB->id,
        ]);
        $this->assertDatabaseHas('product_affinities', [
            'workspace_id' => $this->workspace->id,
            'product_a_id' => $this->productB->id,
            'product_b_id' => $this->productA->id,
        ]);
    }

    public function test_pair_in_only_two_orders_is_below_min_threshold_and_not_inserted(): void
    {
        // 2 orders — below MIN_PAIR_ORDERS (3)
        for ($i = 0; $i < 2; $i++) {
            $orderId = $this->insertOrder();
            $this->attachProduct($orderId, 'pa1');
            $this->attachProduct($orderId, 'pb1');
        }

        $this->runJob();

        $this->assertDatabaseCount('product_affinities', 0);
    }

    public function test_support_confidence_and_lift_are_calculated_correctly(): void
    {
        // 3/3: all orders contain both products → support=1, confidence=1, lift=1
        for ($i = 0; $i < 3; $i++) {
            $orderId = $this->insertOrder();
            $this->attachProduct($orderId, 'pa1');
            $this->attachProduct($orderId, 'pb1');
        }

        $this->runJob();

        $row = DB::table('product_affinities')
            ->where('workspace_id', $this->workspace->id)
            ->where('product_a_id', $this->productA->id)
            ->where('product_b_id', $this->productB->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(1.0, (float) $row->support,    0.001);
        $this->assertEqualsWithDelta(1.0, (float) $row->confidence, 0.001);
        $this->assertEqualsWithDelta(1.0, (float) $row->lift,       0.001);
    }

    public function test_orders_older_than_90_days_are_excluded(): void
    {
        // 2 recent + 1 ancient (past the 90d window) — pair_orders = 2 → below threshold
        $oldDate = now()->subDays(95);
        for ($i = 0; $i < 2; $i++) {
            $orderId = $this->insertOrder('completed', now()->subDays(5));
            $this->attachProduct($orderId, 'pa1');
            $this->attachProduct($orderId, 'pb1');
        }
        $oldOrderId = $this->insertOrder('completed', $oldDate);
        $this->attachProduct($oldOrderId, 'pa1');
        $this->attachProduct($oldOrderId, 'pb1');

        $this->runJob();

        // Only 2 recent orders counted → below threshold of 3
        $this->assertDatabaseCount('product_affinities', 0);
    }

    public function test_only_completed_and_processing_orders_are_included(): void
    {
        // 3 cancelled orders (wrong status) → should not contribute
        for ($i = 0; $i < 3; $i++) {
            $orderId = $this->insertOrder('cancelled');
            $this->attachProduct($orderId, 'pa1');
            $this->attachProduct($orderId, 'pb1');
        }

        $this->runJob();

        $this->assertDatabaseCount('product_affinities', 0);
    }

    public function test_processing_status_counts_alongside_completed(): void
    {
        // Mix of completed and processing — both valid
        $this->attachProduct($this->insertOrder('completed'),   'pa1');
        $this->attachProduct($this->insertOrder('completed'),   'pb1'); // A only once more
        $this->attachProduct($this->insertOrder('processing'),  'pa1');
        $this->attachProduct($this->insertOrder('processing'),  'pb1');

        // Need all 3 orders to have BOTH products for pair threshold
        $orderId1 = $this->insertOrder('completed');
        $this->attachProduct($orderId1, 'pa1');
        $this->attachProduct($orderId1, 'pb1');

        $orderId2 = $this->insertOrder('processing');
        $this->attachProduct($orderId2, 'pa1');
        $this->attachProduct($orderId2, 'pb1');

        $orderId3 = $this->insertOrder('completed');
        $this->attachProduct($orderId3, 'pa1');
        $this->attachProduct($orderId3, 'pb1');

        $this->runJob();

        // 3 orders with both A and B → threshold met
        $this->assertDatabaseHas('product_affinities', [
            'workspace_id' => $this->workspace->id,
            'product_a_id' => $this->productA->id,
            'product_b_id' => $this->productB->id,
        ]);
    }

    public function test_reruns_replace_old_rows_atomically(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $orderId = $this->insertOrder();
            $this->attachProduct($orderId, 'pa1');
            $this->attachProduct($orderId, 'pb1');
        }

        $this->runJob();
        $this->runJob(); // Second run

        // Should still be exactly 2 rows (A→B and B→A), not 4
        $this->assertDatabaseCount('product_affinities', 2);
    }

    public function test_margin_lift_is_null_when_no_unit_cost(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $orderId = $this->insertOrder();
            $this->attachProduct($orderId, 'pa1', null); // no unit_cost
            $this->attachProduct($orderId, 'pb1', null);
        }

        $this->runJob();

        $row = DB::table('product_affinities')
            ->where('workspace_id', $this->workspace->id)
            ->where('product_a_id', $this->productA->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->margin_lift);
    }

    public function test_margin_lift_is_populated_when_unit_cost_present(): void
    {
        // unit_price = 50, unit_cost = 20 → margin = 30
        for ($i = 0; $i < 3; $i++) {
            $orderId = $this->insertOrder();
            $this->attachProduct($orderId, 'pa1', 20.00);
            $this->attachProduct($orderId, 'pb1', 20.00);
        }

        $this->runJob();

        $row = DB::table('product_affinities')
            ->where('workspace_id', $this->workspace->id)
            ->where('product_a_id', $this->productA->id)
            ->where('product_b_id', $this->productB->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->margin_lift);
        // confidence=1.0 × avg_margin(B)=30 → margin_lift = 30
        $this->assertEqualsWithDelta(30.0, (float) $row->margin_lift, 0.1);
    }

    public function test_workspace_isolation(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $otherStore     = Store::factory()->create(['workspace_id' => $otherWorkspace->id]);
        $otherProductA  = Product::withoutGlobalScopes()->create([
            'workspace_id' => $otherWorkspace->id,
            'store_id'     => $otherStore->id,
            'external_id'  => 'pa1',
            'name'         => 'Other A',
            'slug'         => 'other-a',
            'status'       => 'publish',
        ]);
        $otherProductB  = Product::withoutGlobalScopes()->create([
            'workspace_id' => $otherWorkspace->id,
            'store_id'     => $otherStore->id,
            'external_id'  => 'pb1',
            'name'         => 'Other B',
            'slug'         => 'other-b',
            'status'       => 'publish',
        ]);

        // 3 orders in the OTHER workspace
        for ($i = 0; $i < 3; $i++) {
            DB::table('orders')->insert([
                'workspace_id'                => $otherWorkspace->id,
                'store_id'                    => $otherStore->id,
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
                'occurred_at'                 => now()->subDays(3),
                'synced_at'                   => now(),
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ]);
            $orderId = (int) DB::table('orders')->latest('id')->value('id');
            $this->attachProduct($orderId, 'pa1');
            $this->attachProduct($orderId, 'pb1');
        }

        // Run job for THIS workspace — should not produce rows for other workspace
        $this->runJob();

        $this->assertDatabaseCount('product_affinities', 0);
    }
}
