<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\DetectStockTransitionsJob;
use App\Models\Alert;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DetectStockTransitionsJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private Store $store;
    private Carbon $today;
    private Carbon $yesterday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->today     = Carbon::parse('2026-04-14');
        $this->yesterday = $this->today->copy()->subDay();

        app(WorkspaceContext::class)->set($this->workspace->id);
    }

    private function insertSnapshot(Carbon $date, string $externalId, ?string $stockStatus, ?int $stockQty = null): void
    {
        DB::table('daily_snapshot_products')->insert([
            'workspace_id'        => $this->workspace->id,
            'store_id'            => $this->store->id,
            'snapshot_date'       => $date->toDateString(),
            'product_external_id' => $externalId,
            'product_name'        => "Product {$externalId}",
            'revenue'             => 100,
            'units'               => 1,
            'rank'                => 1,
            'stock_status'        => $stockStatus,
            'stock_quantity'      => $stockQty,
            'created_at'          => now(),
        ]);
    }

    private function runJob(): void
    {
        (new DetectStockTransitionsJob(
            $this->store->id,
            $this->workspace->id,
            $this->today->toDateString(),
        ))->handle();
    }

    public function test_instock_to_outofstock_creates_warning_alert(): void
    {
        $this->insertSnapshot($this->yesterday, 'p1', 'instock', 5);
        $this->insertSnapshot($this->today,     'p1', 'outofstock', 0);

        $this->runJob();

        $alert = Alert::withoutGlobalScopes()->where('type', 'product_out_of_stock')->first();
        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert->severity);
        $this->assertSame('p1', $alert->data['product_external_id']);
        $this->assertSame($this->store->id, $alert->store_id);
    }

    public function test_outofstock_to_instock_creates_info_alert(): void
    {
        $this->insertSnapshot($this->yesterday, 'p1', 'outofstock');
        $this->insertSnapshot($this->today,     'p1', 'instock', 12);

        $this->runJob();

        $alert = Alert::withoutGlobalScopes()->where('type', 'product_back_in_stock')->first();
        $this->assertNotNull($alert);
        $this->assertSame('info', $alert->severity);
    }

    public function test_no_transition_produces_no_alert(): void
    {
        $this->insertSnapshot($this->yesterday, 'p1', 'instock', 5);
        $this->insertSnapshot($this->today,     'p1', 'instock', 4);

        $this->runJob();

        $this->assertSame(0, Alert::withoutGlobalScopes()->count());
    }

    public function test_dedup_within_seven_days(): void
    {
        // Simulate yesterday's run already having alerted on this product.
        Alert::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'type'         => 'product_out_of_stock',
            'severity'     => 'warning',
            'source'       => 'system',
            'data'         => ['product_external_id' => 'p1'],
            'created_at'   => now()->subDays(3),
            'updated_at'   => now()->subDays(3),
        ]);

        $this->insertSnapshot($this->yesterday, 'p1', 'instock', 5);
        $this->insertSnapshot($this->today,     'p1', 'outofstock', 0);

        $this->runJob();

        $this->assertSame(
            1,
            Alert::withoutGlobalScopes()->where('type', 'product_out_of_stock')->count(),
            'Second alert should be suppressed by dedup window.',
        );
    }

    public function test_dedup_expires_after_seven_days(): void
    {
        // Insert via DB::table so we can backdate created_at past the dedup window.
        // Alert::create() overwrites timestamps via Eloquent.
        DB::table('alerts')->insert([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'type'         => 'product_out_of_stock',
            'severity'     => 'warning',
            'source'       => 'system',
            'data'         => json_encode(['product_external_id' => 'p1']),
            'created_at'   => now()->subDays(10),
            'updated_at'   => now()->subDays(10),
        ]);

        $this->insertSnapshot($this->yesterday, 'p1', 'instock', 5);
        $this->insertSnapshot($this->today,     'p1', 'outofstock', 0);

        $this->runJob();

        $this->assertSame(
            2,
            Alert::withoutGlobalScopes()->where('type', 'product_out_of_stock')->count(),
            'Alert older than dedup window should not suppress a new one.',
        );
    }

    public function test_only_runs_for_target_store(): void
    {
        $otherStore = Store::factory()->create(['workspace_id' => $this->workspace->id]);

        // Other store has a transition — should be ignored.
        DB::table('daily_snapshot_products')->insert([
            'workspace_id'        => $this->workspace->id,
            'store_id'            => $otherStore->id,
            'snapshot_date'       => $this->yesterday->toDateString(),
            'product_external_id' => 'x',
            'product_name'        => 'x',
            'revenue'             => 1, 'units' => 1, 'rank' => 1,
            'stock_status'        => 'instock',
            'created_at'          => now(),
        ]);
        DB::table('daily_snapshot_products')->insert([
            'workspace_id'        => $this->workspace->id,
            'store_id'            => $otherStore->id,
            'snapshot_date'       => $this->today->toDateString(),
            'product_external_id' => 'x',
            'product_name'        => 'x',
            'revenue'             => 1, 'units' => 1, 'rank' => 1,
            'stock_status'        => 'outofstock',
            'created_at'          => now(),
        ]);

        $this->runJob();

        $this->assertSame(0, Alert::withoutGlobalScopes()->count());
    }
}
