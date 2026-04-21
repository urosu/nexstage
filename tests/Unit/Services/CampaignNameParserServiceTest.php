<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Campaign;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\CampaignNameParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignNameParserServiceTest extends TestCase
{
    use RefreshDatabase;

    private CampaignNameParserService $parser;
    private Workspace $workspace;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser    = app(CampaignNameParserService::class);
        $this->workspace = Workspace::factory()->create();
        $this->store     = Store::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    // -------------------------------------------------------------------------
    // Shape detection
    // -------------------------------------------------------------------------

    public function test_full_shape_country_campaign_target(): void
    {
        Product::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => '1',
            'name'         => 'Nike Air Max',
            'slug'         => 'nike-air-max',
        ]);

        $result = $this->parser->parse('HR | Black Friday | nike-air-max', $this->workspace->id);

        $this->assertSame('HR', $result['country']);
        $this->assertSame('Black Friday', $result['campaign']);
        $this->assertSame('product', $result['target_type']);
        $this->assertSame('nike-air-max', $result['target_slug']);
        $this->assertSame('full', $result['shape']);
        $this->assertSame('clean', $result['parse_status']);
    }

    public function test_campaign_target_shape(): void
    {
        ProductCategory::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => '10',
            'name'         => 'Sneakers',
            'slug'         => 'sneakers',
        ]);

        $result = $this->parser->parse('Summer Sale | sneakers', $this->workspace->id);

        $this->assertNull($result['country']);
        $this->assertSame('Summer Sale', $result['campaign']);
        $this->assertSame('category', $result['target_type']);
        $this->assertSame('sneakers', $result['target_slug']);
        $this->assertSame('campaign_target', $result['shape']);
        $this->assertSame('clean', $result['parse_status']);
    }

    public function test_minimal_shape_single_field(): void
    {
        $result = $this->parser->parse('Brand Awareness', $this->workspace->id);

        $this->assertNull($result['country']);
        $this->assertSame('Brand Awareness', $result['campaign']);
        $this->assertNull($result['target_type']);
        $this->assertNull($result['raw_target']);
        $this->assertSame('minimal', $result['shape']);
        $this->assertSame('minimal', $result['parse_status']);
    }

    // -------------------------------------------------------------------------
    // Country detection
    // -------------------------------------------------------------------------

    public function test_country_code_detected_from_two_uppercase_letters(): void
    {
        $result = $this->parser->parse('DE | Winter Campaign | unknown-target', $this->workspace->id);

        $this->assertSame('DE', $result['country']);
        $this->assertSame('full', $result['shape']);
    }

    public function test_lowercase_first_field_not_treated_as_country(): void
    {
        $result = $this->parser->parse('de | Winter Campaign', $this->workspace->id);

        $this->assertNull($result['country']);
        $this->assertSame('de', $result['campaign']);
        $this->assertSame('campaign_target', $result['shape']);
    }

    public function test_three_letter_first_field_not_treated_as_country(): void
    {
        $result = $this->parser->parse('USA | Summer | shoes', $this->workspace->id);

        $this->assertNull($result['country']);
        $this->assertSame('USA', $result['campaign']);
    }

    // -------------------------------------------------------------------------
    // Target matching priority
    // -------------------------------------------------------------------------

    public function test_product_slug_takes_priority_over_category_slug(): void
    {
        Product::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => '1',
            'name'         => 'Sneakers Pro',
            'slug'         => 'sneakers',
        ]);

        ProductCategory::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => '10',
            'name'         => 'Sneakers Category',
            'slug'         => 'sneakers',
        ]);

        $result = $this->parser->parse('Spring | sneakers', $this->workspace->id);

        $this->assertSame('product', $result['target_type']);
        $this->assertSame('clean', $result['parse_status']);
    }

    public function test_unmatched_target_falls_back_to_raw(): void
    {
        $result = $this->parser->parse('HR | Retargeting | nonexistent-product', $this->workspace->id);

        $this->assertSame('HR', $result['country']);
        $this->assertNull($result['target_type']);
        $this->assertNull($result['target_id']);
        $this->assertSame('nonexistent-product', $result['raw_target']);
        $this->assertSame('partial', $result['parse_status']);
    }

    // -------------------------------------------------------------------------
    // Slug normalisation
    // -------------------------------------------------------------------------

    public function test_target_normalised_to_slug_format(): void
    {
        Product::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => '1',
            'name'         => 'Nike Air Max',
            'slug'         => 'nike-air-max',
        ]);

        // Spaces and underscores in campaign name converted to hyphens for matching
        $result = $this->parser->parse('UK | Promo | Nike Air Max', $this->workspace->id);

        $this->assertSame('product', $result['target_type']);
        $this->assertSame('nike-air-max', $result['target_slug']);
        $this->assertSame('clean', $result['parse_status']);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function test_empty_string_returns_minimal(): void
    {
        $result = $this->parser->parse('', $this->workspace->id);

        $this->assertSame('minimal', $result['shape']);
        $this->assertSame('minimal', $result['parse_status']);
    }

    public function test_pipe_only_returns_minimal(): void
    {
        $result = $this->parser->parse('| | |', $this->workspace->id);

        $this->assertSame('minimal', $result['shape']);
    }

    public function test_extra_pipes_ignored(): void
    {
        Product::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'store_id'     => $this->store->id,
            'external_id'  => '1',
            'name'         => 'Boots',
            'slug'         => 'boots',
        ]);

        $result = $this->parser->parse('FR | Winter | boots | extra | fields', $this->workspace->id);

        $this->assertSame('FR', $result['country']);
        $this->assertSame('Winter', $result['campaign']);
        $this->assertSame('boots', $result['raw_target']);
        $this->assertSame('product', $result['target_type']);
        $this->assertSame('full', $result['shape']);
    }

    public function test_whitespace_around_pipes_trimmed(): void
    {
        $result = $this->parser->parse('  NL  |  Spring Sale  |  some-target  ', $this->workspace->id);

        $this->assertSame('NL', $result['country']);
        $this->assertSame('Spring Sale', $result['campaign']);
        $this->assertSame('some-target', $result['raw_target']);
    }

    // -------------------------------------------------------------------------
    // parseAndSave
    // -------------------------------------------------------------------------

    public function test_parse_and_save_writes_to_campaign(): void
    {
        $campaign = Campaign::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name'         => 'DE | Holiday Sale | unknown-target',
        ]);

        $result = $this->parser->parseAndSave($campaign);

        $campaign->refresh();
        $this->assertSame('DE', $campaign->parsed_convention['country']);
        $this->assertSame('Holiday Sale', $campaign->parsed_convention['campaign']);
        $this->assertSame('partial', $campaign->parsed_convention['parse_status']);
    }

    // -------------------------------------------------------------------------
    // Cross-workspace isolation
    // -------------------------------------------------------------------------

    public function test_product_from_different_workspace_not_matched(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $otherStore     = Store::factory()->create(['workspace_id' => $otherWorkspace->id]);

        Product::withoutGlobalScopes()->create([
            'workspace_id' => $otherWorkspace->id,
            'store_id'     => $otherStore->id,
            'external_id'  => '1',
            'name'         => 'Secret Product',
            'slug'         => 'secret-product',
        ]);

        $result = $this->parser->parse('UK | Promo | secret-product', $this->workspace->id);

        $this->assertNull($result['target_type']);
        $this->assertSame('partial', $result['parse_status']);
    }
}
