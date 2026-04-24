<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NarrativeTemplateService;
use Tests\TestCase;

/**
 * Verifies that NarrativeTemplateService produces terse, action-oriented narratives
 * from pre-computed metrics. Each method must return null when insufficient data is
 * available, and a non-empty string when it can say something meaningful.
 *
 * @see PROGRESS.md Phase 3.1 — NarrativeTemplateService
 */
class NarrativeTemplateServiceTest extends TestCase
{
    private NarrativeTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NarrativeTemplateService();
    }

    // -------------------------------------------------------------------------
    // forDashboard
    // -------------------------------------------------------------------------

    public function test_dashboard_narrative_with_revenue_and_comparison(): void
    {
        $result = $this->service->forDashboard(
            revenue:         4230.0,
            compareRevenue:  3777.0,
            comparisonLabel: 'last Wednesday',
            roas:            2.4,
            hasAds:          true,
            hasGsc:          false,
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('€4,230', $result);
        $this->assertStringContainsString('last Wednesday', $result);
        $this->assertStringContainsString('ROAS 2.4x', $result);
    }

    public function test_dashboard_narrative_without_comparison(): void
    {
        $result = $this->service->forDashboard(
            revenue:         4230.0,
            compareRevenue:  null,
            comparisonLabel: null,
            roas:            null,
            hasAds:          false,
            hasGsc:          true,
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('€4,230', $result);
        $this->assertStringNotContainsString('ROAS', $result);
        // Store-only with GSC → lever note about organic
        $this->assertStringContainsString('organic', $result);
    }

    public function test_dashboard_narrative_null_when_no_revenue(): void
    {
        $result = $this->service->forDashboard(
            revenue:         null,
            compareRevenue:  null,
            comparisonLabel: null,
            roas:            null,
            hasAds:          false,
            hasGsc:          false,
        );

        $this->assertNull($result);
    }

    public function test_dashboard_narrative_low_roas_shows_lever(): void
    {
        $result = $this->service->forDashboard(
            revenue:         3000.0,
            compareRevenue:  null,
            comparisonLabel: null,
            roas:            1.2,
            hasAds:          true,
            hasGsc:          false,
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('ROAS below breakeven', $result);
    }

    // -------------------------------------------------------------------------
    // forCampaigns
    // -------------------------------------------------------------------------

    public function test_campaigns_narrative_with_top_spender(): void
    {
        $result = $this->service->forCampaigns(
            aboveTarget:       4,
            belowTarget:       3,
            topSpenderName:    'Spring Sale',
            topSpenderRoas:    1.8,
            topSpenderStatus:  'active',
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('4 campaigns above target', $result);
        $this->assertStringContainsString('3 below', $result);
        $this->assertStringContainsString('Spring Sale', $result);
        $this->assertStringContainsString('1.8x', $result);
        $this->assertStringContainsString('active', $result);
    }

    public function test_campaigns_narrative_null_when_no_campaigns(): void
    {
        $result = $this->service->forCampaigns(0, 0, null, null, null);

        $this->assertNull($result);
    }

    public function test_campaigns_narrative_without_top_spender(): void
    {
        $result = $this->service->forCampaigns(2, 1, null, null, null);

        $this->assertNotNull($result);
        $this->assertStringContainsString('2 campaigns above target, 1 below', $result);
    }

    // -------------------------------------------------------------------------
    // forSeo
    // -------------------------------------------------------------------------

    public function test_seo_narrative_with_all_data(): void
    {
        $result = $this->service->forSeo(
            clicksDeltaPct:   18.0,
            strikingDistance: 3,
            atRisk:           2,
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('+18%', $result);
        $this->assertStringContainsString('striking distance', $result);
        $this->assertStringContainsString('at risk', $result);
    }

    public function test_seo_narrative_negative_delta(): void
    {
        $result = $this->service->forSeo(-5.2, 0, 0);

        $this->assertNotNull($result);
        $this->assertStringContainsString('-5%', $result);
    }

    public function test_seo_narrative_null_when_no_data(): void
    {
        $result = $this->service->forSeo(null, 0, 0);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // forPerformance
    // -------------------------------------------------------------------------

    public function test_performance_narrative_amber_band(): void
    {
        $result = $this->service->forPerformance(72, 3100, null);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Lighthouse 72 (amber)', $result);
        $this->assertStringContainsString('LCP median 3.1s', $result);
    }

    public function test_performance_narrative_good_band(): void
    {
        $result = $this->service->forPerformance(95, 1800, 340.0);

        $this->assertNotNull($result);
        $this->assertStringContainsString('(good)', $result);
        $this->assertStringContainsString('€340', $result);
    }

    public function test_performance_narrative_null_when_no_score(): void
    {
        $result = $this->service->forPerformance(null, null, null);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // forProducts
    // -------------------------------------------------------------------------

    public function test_products_narrative_with_stockout_risk(): void
    {
        $result = $this->service->forProducts(5, 3, 2);

        $this->assertNotNull($result);
        $this->assertStringContainsString('5 winners', $result);
        $this->assertStringContainsString('3 losers', $result);
        $this->assertStringContainsString('2 products at stockout risk', $result);
    }

    public function test_products_narrative_singular_winner(): void
    {
        $result = $this->service->forProducts(1, 1, 0);

        $this->assertNotNull($result);
        $this->assertStringContainsString('1 winner,', $result);
        $this->assertStringContainsString('1 loser', $result);
        $this->assertStringNotContainsString('stockout', $result);
    }

    public function test_products_narrative_null_when_no_products(): void
    {
        $result = $this->service->forProducts(0, 0, 0);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // forCustomers
    // -------------------------------------------------------------------------

    public function test_customers_narrative_with_returning_pct(): void
    {
        $result = $this->service->forCustomers(12, 4, 38.5);

        $this->assertNotNull($result);
        $this->assertStringContainsString('12 Champions', $result);
        $this->assertStringContainsString('4 At-Risk', $result);
        $this->assertStringContainsString('38.5%', $result);
    }

    public function test_customers_narrative_null_when_no_data(): void
    {
        $result = $this->service->forCustomers(0, 0, null);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // forAcquisition
    // -------------------------------------------------------------------------

    public function test_acquisition_narrative_with_top_and_worst(): void
    {
        $result = $this->service->forAcquisition(
            topChannel:   'Meta',
            topRevenue:   2100.0,
            topRoas:      2.8,
            worstChannel: 'Google',
            worstRoas:    1.2,
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('Meta leads', $result);
        $this->assertStringContainsString('€2,100', $result);
        $this->assertStringContainsString('2.8x ROAS', $result);
        $this->assertStringContainsString('Google dragging at 1.2x', $result);
    }

    public function test_acquisition_narrative_no_worst_when_same_channel(): void
    {
        $result = $this->service->forAcquisition('Meta', 2100.0, 2.8, 'Meta', 2.8);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('dragging', $result);
    }

    public function test_acquisition_narrative_null_when_no_channel(): void
    {
        $result = $this->service->forAcquisition(null, null, null, null, null);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // forInbox
    // -------------------------------------------------------------------------

    public function test_inbox_narrative_all_clear(): void
    {
        $result = $this->service->forInbox(0);

        $this->assertStringContainsString('All clear', $result);
    }

    public function test_inbox_narrative_plural(): void
    {
        $result = $this->service->forInbox(3);

        $this->assertStringContainsString('3 items need', $result);
    }

    public function test_inbox_narrative_singular(): void
    {
        $result = $this->service->forInbox(1);

        $this->assertStringContainsString('1 item needs', $result);
    }
}
