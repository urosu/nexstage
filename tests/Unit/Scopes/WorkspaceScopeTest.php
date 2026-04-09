<?php

declare(strict_types=1);

namespace Tests\Unit\Scopes;

use App\Models\Order;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset context between tests using reflection to set private property
        $ctx = app(WorkspaceContext::class);
        $ref = new \ReflectionProperty($ctx, 'workspaceId');
        $ref->setAccessible(true);
        $ref->setValue($ctx, null);
    }

    public function test_throws_when_context_not_set(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WorkspaceContext not set');

        Order::all();
    }

    public function test_filters_by_active_workspace(): void
    {
        $ws1   = Workspace::factory()->create();
        $ws2   = Workspace::factory()->create();
        $store1 = Store::factory()->create(['workspace_id' => $ws1->id]);
        $store2 = Store::factory()->create(['workspace_id' => $ws2->id]);

        // Insert orders bypassing the scope
        \Illuminate\Support\Facades\DB::table('orders')->insert([
            [
                'workspace_id' => $ws1->id,
                'store_id'     => $store1->id,
                'external_id'  => 'order-ws1',
                'status'       => 'completed',
                'currency'     => 'EUR',
                'total'        => 100,
                'subtotal'     => 90,
                'tax'          => 10,
                'shipping'     => 5,
                'discount'     => 0,
                'occurred_at'  => now(),
                'synced_at'    => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
            [
                'workspace_id' => $ws2->id,
                'store_id'     => $store2->id,
                'external_id'  => 'order-ws2',
                'status'       => 'completed',
                'currency'     => 'EUR',
                'total'        => 200,
                'subtotal'     => 180,
                'tax'          => 20,
                'shipping'     => 5,
                'discount'     => 0,
                'occurred_at'  => now(),
                'synced_at'    => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
        ]);

        app(WorkspaceContext::class)->set($ws1->id);

        $orders = Order::all();

        $this->assertCount(1, $orders);
        $this->assertSame($ws1->id, $orders->first()->workspace_id);
    }

    public function test_does_not_return_other_workspace_data(): void
    {
        $ws1    = Workspace::factory()->create();
        $ws2    = Workspace::factory()->create();
        $store1 = Store::factory()->create(['workspace_id' => $ws1->id]);
        $store2 = Store::factory()->create(['workspace_id' => $ws2->id]);

        \Illuminate\Support\Facades\DB::table('orders')->insert([
            [
                'workspace_id' => $ws2->id,
                'store_id'     => $store2->id,
                'external_id'  => 'ws2-only-order',
                'status'       => 'completed',
                'currency'     => 'EUR',
                'total'        => 100,
                'subtotal'     => 90,
                'tax'          => 10,
                'shipping'     => 5,
                'discount'     => 0,
                'occurred_at'  => now(),
                'synced_at'    => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
        ]);

        // Set context to ws1 — should see 0 orders
        app(WorkspaceContext::class)->set($ws1->id);

        $this->assertCount(0, Order::all());
    }
}
