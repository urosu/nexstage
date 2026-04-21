<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature test for GET /{workspace}/analytics/products/{product} — the per-product
 * detail page added in Phase 1.6.
 *
 * Verifies:
 *   - Page renders 200 for a product belonging to the workspace
 *   - Required Inertia props are present
 *   - FBT pairs from product_affinities are surfaced when present
 *   - A product from a different workspace returns 404
 *
 * @see app/Http/Controllers/AnalyticsController::productShow
 * @see PLANNING.md section 19 (Frequently-Bought-Together)
 */
class ProductDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private Store $store;
    private Product $product;

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

        $this->product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => 'p1',
            'name'         => 'Blue Hoodie',
            'slug'         => 'blue-hoodie',
            'status'       => 'publish',
        ]);

        // WorkspaceContext must be set before any HTTP request so SubstituteBindings
        // can resolve {product} through WorkspaceScope (runs before SetActiveWorkspace).
        app(WorkspaceContext::class)->set($this->workspace->id);
    }

    private function visit(Product $product): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/analytics/products/{$product->id}");
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_page_renders_for_workspace_product(): void
    {
        $this->visit($this->product)->assertOk();
    }

    public function test_required_inertia_props_are_present(): void
    {
        $response = $this->visit($this->product)->assertOk();

        $this->assertArrayHasKey('product',       $response->inertiaProps());
        $this->assertArrayHasKey('hero',          $response->inertiaProps());
        $this->assertArrayHasKey('variants',      $response->inertiaProps());
        $this->assertArrayHasKey('sources',       $response->inertiaProps());
        $this->assertArrayHasKey('recent_orders', $response->inertiaProps());
        $this->assertArrayHasKey('fbt',           $response->inertiaProps());
    }

    public function test_product_name_appears_in_product_prop(): void
    {
        $product = $this->visit($this->product)->assertOk()->inertiaProps('product');

        $this->assertSame('Blue Hoodie', $product['name']);
    }

    public function test_fbt_pairs_populated_from_product_affinities(): void
    {
        // Create a sibling product and seed a product_affinities row
        $siblingProduct = Product::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => 'p2',
            'name'         => 'Red Cap',
            'slug'         => 'red-cap',
            'status'       => 'publish',
        ]);

        DB::table('product_affinities')->insert([
            'workspace_id'  => $this->workspace->id,
            'store_id'      => $this->store->id,
            'product_a_id'  => $this->product->id,
            'product_b_id'  => $siblingProduct->id,
            'support'       => 0.75,
            'confidence'    => 0.90,
            'lift'          => 1.50,
            'margin_lift'   => null,
            'calculated_at' => now(),
        ]);

        $fbt = $this->visit($this->product)->assertOk()->inertiaProps('fbt');

        $this->assertNotEmpty($fbt);
        $this->assertSame($siblingProduct->id, $fbt[0]['product_id']);
    }

    public function test_product_from_other_workspace_returns_404(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $otherStore     = Store::factory()->create([
            'workspace_id'             => $otherWorkspace->id,
            'historical_import_status' => 'completed',
        ]);
        $otherProduct = Product::withoutGlobalScopes()->create([
            'workspace_id' => $otherWorkspace->id,
            'store_id'     => $otherStore->id,
            'external_id'  => 'x1',
            'name'         => 'Other Product',
            'slug'         => 'other-product',
            'status'       => 'publish',
        ]);

        $this->actingAs($this->user)
            ->get("/{$this->workspace->slug}/analytics/products/{$otherProduct->id}")
            ->assertNotFound();
    }
}
