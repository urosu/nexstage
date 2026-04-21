<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature test for GET /{workspace}/orders/{order} — the per-order attribution
 * detail page added in Phase 1.6.
 *
 * Verifies:
 *   - Page renders 200 for an order belonging to the workspace
 *   - The `order` Inertia prop exposes attribution columns
 *   - An order from a different workspace returns 404
 *
 * @see app/Http/Controllers/OrdersController::show
 * @see PLANNING.md section 6 (attribution columns)
 */
class OrderDetailPageTest extends TestCase
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

        // WorkspaceContext must be set before any HTTP request so SubstituteBindings
        // can resolve {order} through WorkspaceScope (runs before SetActiveWorkspace).
        app(WorkspaceContext::class)->set($this->workspace->id);
    }

    private function visit(Order $order): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/orders/{$order->id}");
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_page_renders_for_workspace_order(): void
    {
        $order = Order::factory()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
        ]);

        $this->visit($order)->assertOk();
    }

    public function test_order_prop_contains_attribution_fields(): void
    {
        $order = Order::factory()->create([
            'workspace_id'           => $this->workspace->id,
            'store_id'               => $this->store->id,
            'utm_source'             => 'facebook',
            'utm_medium'             => 'cpc',
            'attribution_source'     => 'pys',
            'attribution_last_touch' => [
                'source'       => 'facebook',
                'medium'       => 'cpc',
                'channel'      => 'Facebook',
                'channel_type' => 'paid_social',
            ],
        ]);

        $response   = $this->visit($order)->assertOk();
        $orderProp  = $response->inertiaProps('order');

        $this->assertArrayHasKey('attribution_source',     $orderProp);
        $this->assertArrayHasKey('attribution_last_touch', $orderProp);
        $this->assertArrayHasKey('utm_source',             $orderProp);
        $this->assertSame('pys', $orderProp['attribution_source']);
        $this->assertSame('facebook', $orderProp['utm_source']);
    }

    public function test_order_from_other_workspace_returns_404(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $otherStore     = Store::factory()->create([
            'workspace_id'             => $otherWorkspace->id,
            'historical_import_status' => 'completed',
        ]);
        $otherOrder = Order::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'store_id'     => $otherStore->id,
        ]);

        $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/orders/{$otherOrder->id}")
            ->assertNotFound();
    }
}
