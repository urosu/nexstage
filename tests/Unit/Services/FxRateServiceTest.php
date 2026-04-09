<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\FxRateNotFoundException;
use App\Models\FxRate;
use App\Services\Fx\FxRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FxRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private FxRateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FxRateService::class);
    }

    public function test_same_currency_returns_original_amount(): void
    {
        $result = $this->service->convert(100.0, 'EUR', 'EUR', now());
        $this->assertSame(100.0, $result);

        $result = $this->service->convert(250.0, 'USD', 'USD', now());
        $this->assertSame(250.0, $result);
    }

    public function test_order_currency_is_eur_converts_to_reporting(): void
    {
        FxRate::factory()->create([
            'base_currency'   => 'EUR',
            'target_currency' => 'GBP',
            'rate'            => 0.86,
            'date'            => today(),
        ]);

        $result = $this->service->convert(100.0, 'EUR', 'GBP', today());
        $this->assertEqualsWithDelta(86.0, $result, 0.0001);
    }

    public function test_reporting_currency_is_eur_converts_from_order(): void
    {
        FxRate::factory()->create([
            'base_currency'   => 'EUR',
            'target_currency' => 'GBP',
            'rate'            => 0.86,
            'date'            => today(),
        ]);

        $result = $this->service->convert(86.0, 'GBP', 'EUR', today());
        $this->assertEqualsWithDelta(100.0, $result, 0.001);
    }

    public function test_cross_rate_conversion(): void
    {
        FxRate::factory()->create([
            'base_currency'   => 'EUR',
            'target_currency' => 'USD',
            'rate'            => 1.08,
            'date'            => today(),
        ]);
        FxRate::factory()->create([
            'base_currency'   => 'EUR',
            'target_currency' => 'GBP',
            'rate'            => 0.86,
            'date'            => today(),
        ]);

        // GBP → USD: amount × (rate(EUR→USD) / rate(EUR→GBP))
        $expected = 100.0 * (1.08 / 0.86);
        $result   = $this->service->convert(100.0, 'GBP', 'USD', today());
        $this->assertEqualsWithDelta($expected, $result, 0.001);
    }

    public function test_exact_date_match_used_first(): void
    {
        FxRate::factory()->create([
            'base_currency'   => 'EUR',
            'target_currency' => 'USD',
            'rate'            => 1.05,
            'date'            => today()->subDay(),
        ]);
        FxRate::factory()->create([
            'base_currency'   => 'EUR',
            'target_currency' => 'USD',
            'rate'            => 1.08,
            'date'            => today(),
        ]);

        $result = $this->service->convert(100.0, 'EUR', 'USD', today());
        $this->assertEqualsWithDelta(108.0, $result, 0.001);
    }

    public function test_falls_back_to_prior_day_when_exact_date_missing(): void
    {
        FxRate::factory()->create([
            'base_currency'   => 'EUR',
            'target_currency' => 'USD',
            'rate'            => 1.05,
            'date'            => today()->subDay(),
        ]);

        $result = $this->service->convert(100.0, 'EUR', 'USD', today());
        $this->assertEqualsWithDelta(105.0, $result, 0.001);
    }

    public function test_falls_back_up_to_3_days(): void
    {
        FxRate::factory()->create([
            'base_currency'   => 'EUR',
            'target_currency' => 'USD',
            'rate'            => 1.04,
            'date'            => today()->subDays(3),
        ]);

        $result = $this->service->convert(100.0, 'EUR', 'USD', today());
        $this->assertEqualsWithDelta(104.0, $result, 0.001);
    }

    public function test_throws_when_no_rate_within_3_days(): void
    {
        $this->expectException(FxRateNotFoundException::class);

        $this->service->convert(100.0, 'EUR', 'USD', today());
    }

    public function test_rate_older_than_3_days_not_used(): void
    {
        FxRate::factory()->create([
            'base_currency'   => 'EUR',
            'target_currency' => 'USD',
            'rate'            => 1.04,
            'date'            => today()->subDays(4),
        ]);

        $this->expectException(FxRateNotFoundException::class);

        $this->service->convert(100.0, 'EUR', 'USD', today());
    }

    public function test_logs_warning_when_using_fallback_date(): void
    {
        Log::spy();

        FxRate::factory()->create([
            'base_currency'   => 'EUR',
            'target_currency' => 'USD',
            'rate'            => 1.05,
            'date'            => today()->subDay(),
        ]);

        $this->service->convert(100.0, 'EUR', 'USD', today());

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_eur_to_eur_returns_1_as_rate(): void
    {
        // getRate('EUR', ...) returns 1.0 without any DB lookup
        $rate = $this->service->getRate('EUR', today());
        $this->assertSame(1.0, $rate);
    }
}
