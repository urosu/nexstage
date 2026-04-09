<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ComputeDailySnapshotJob;
use App\Models\DailySnapshot;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComputeDailySnapshotJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private Store $store;
    private Carbon $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create(['reporting_currency' => 'EUR']);
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->date      = Carbon::today();

        app(WorkspaceContext::class)->set($this->workspace->id);
    }

    private function insertOrder(array $overrides = []): void
    {
        DB::table('orders')->insert(array_merge([
            'workspace_id'                => $this->workspace->id,
            'store_id'                    => $this->store->id,
            'external_id'                 => uniqid('order-', true),
            'status'                      => 'completed',
            'currency'                    => 'EUR',
            'total'                       => 100.00,
            'subtotal'                    => 90.00,
            'tax'                         => 10.00,
            'shipping'                    => 5.00,
            'discount'                    => 0.00,
            'total_in_reporting_currency' => 100.00,
            'customer_email_hash'         => hash('sha256', uniqid('email-', true)),
            'customer_country'            => 'DE',
            'occurred_at'                 => $this->date->copy()->midDay(),
            'synced_at'                   => now(),
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ], $overrides));
    }

    private function runJob(): void
    {
        (new ComputeDailySnapshotJob($this->store->id, $this->date))->handle();
    }

    public function test_counts_only_completed_and_processing_orders(): void
    {
        $this->insertOrder(['status' => 'completed']);
        $this->insertOrder(['status' => 'processing']);
        $this->insertOrder(['status' => 'refunded']);
        $this->insertOrder(['status' => 'cancelled']);

        $this->runJob();

        $snapshot = DailySnapshot::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertSame(2, $snapshot->orders_count);
    }

    public function test_revenue_sums_total_in_reporting_currency(): void
    {
        $this->insertOrder(['total' => 999.00, 'total_in_reporting_currency' => 200.00]);
        $this->insertOrder(['total' => 999.00, 'total_in_reporting_currency' => 300.00]);

        $this->runJob();

        $snapshot = DailySnapshot::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertEqualsWithDelta(500.00, (float) $snapshot->revenue, 0.01);
    }

    public function test_null_total_in_reporting_currency_excluded(): void
    {
        $this->insertOrder(['total_in_reporting_currency' => 100.00]);
        $this->insertOrder(['total_in_reporting_currency' => null]);

        $this->runJob();

        $snapshot = DailySnapshot::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertEqualsWithDelta(100.00, (float) $snapshot->revenue, 0.01);
        $this->assertSame(2, $snapshot->orders_count); // both counted
    }

    public function test_aov_is_null_when_zero_orders(): void
    {
        // No orders inserted

        $this->runJob();

        $snapshot = DailySnapshot::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertSame(0, $snapshot->orders_count);
        $this->assertNull($snapshot->aov);
    }

    public function test_aov_calculated_correctly(): void
    {
        $this->insertOrder(['total_in_reporting_currency' => 150.00]);
        $this->insertOrder(['total_in_reporting_currency' => 250.00]);

        $this->runJob();

        $snapshot = DailySnapshot::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertEqualsWithDelta(200.00, (float) $snapshot->aov, 0.01);
    }

    public function test_new_vs_returning_customers_counted(): void
    {
        $emailHash = hash('sha256', 'repeat@example.com');

        // First order: YESTERDAY (makes this customer "returning" on today's snapshot)
        $this->insertOrder([
            'customer_email_hash' => $emailHash,
            'occurred_at'         => $this->date->copy()->subDay()->midDay(),
        ]);

        // Second order: TODAY
        $this->insertOrder([
            'customer_email_hash' => $emailHash,
            'occurred_at'         => $this->date->copy()->midDay(),
        ]);

        // New customer only today
        $this->insertOrder([
            'customer_email_hash' => hash('sha256', 'new@example.com'),
            'occurred_at'         => $this->date->copy()->midDay(),
        ]);

        $this->runJob();

        $snapshot = DailySnapshot::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertSame(1, $snapshot->new_customers);
        $this->assertSame(1, $snapshot->returning_customers);
    }

    public function test_revenue_by_country_aggregated(): void
    {
        $this->insertOrder(['customer_country' => 'DE', 'total_in_reporting_currency' => 200.00]);
        $this->insertOrder(['customer_country' => 'DE', 'total_in_reporting_currency' => 100.00]);
        $this->insertOrder(['customer_country' => 'AT', 'total_in_reporting_currency' => 50.00]);

        $this->runJob();

        $snapshot = DailySnapshot::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $byCountry = $snapshot->revenue_by_country;

        $this->assertEqualsWithDelta(300.00, $byCountry['DE'], 0.01);
        $this->assertEqualsWithDelta(50.00, $byCountry['AT'], 0.01);
    }

    public function test_top_products_limited_to_10(): void
    {
        // Insert 12 orders each with a different product via order_items
        for ($i = 1; $i <= 12; $i++) {
            $extId = uniqid('order-', true);
            DB::table('orders')->insert([
                'workspace_id'                => $this->workspace->id,
                'store_id'                    => $this->store->id,
                'external_id'                 => $extId,
                'status'                      => 'completed',
                'currency'                    => 'EUR',
                'total'                       => 10.00,
                'subtotal'                    => 9.00,
                'tax'                         => 1.00,
                'shipping'                    => 0.00,
                'discount'                    => 0.00,
                'total_in_reporting_currency' => 10.00,
                'occurred_at'                 => $this->date->copy()->midDay(),
                'synced_at'                   => now(),
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ]);
            $orderId = DB::table('orders')->latest('id')->value('id');

            DB::table('order_items')->insert([
                'order_id'            => $orderId,
                'workspace_id'        => $this->workspace->id,
                'store_id'            => $this->store->id,
                'product_external_id' => "prod-{$i}",
                'product_name'        => "Product {$i}",
                'quantity'            => 1,
                'unit_price'          => 10.00,
                'line_total'          => 10.00,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        $this->runJob();

        $snapshot = DailySnapshot::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertCount(10, $snapshot->top_products);
    }

    public function test_idempotent_upsert(): void
    {
        $this->insertOrder(['total_in_reporting_currency' => 100.00]);

        $this->runJob();
        $this->runJob(); // second run

        $this->assertDatabaseCount('daily_snapshots', 1);
    }

    public function test_second_run_overwrites_first(): void
    {
        $this->insertOrder(['total_in_reporting_currency' => 100.00]);
        $this->runJob();

        // Add another order and re-run
        $this->insertOrder(['total_in_reporting_currency' => 200.00]);
        $this->runJob();

        $snapshot = DailySnapshot::withoutGlobalScopes()->where('store_id', $this->store->id)->first();
        $this->assertEqualsWithDelta(300.00, (float) $snapshot->revenue, 0.01);
        $this->assertSame(2, $snapshot->orders_count);
    }
}
