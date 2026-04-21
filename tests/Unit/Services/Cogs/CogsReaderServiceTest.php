<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cogs;

use App\Services\Cogs\CogsReaderService;
use Tests\TestCase;

class CogsReaderServiceTest extends TestCase
{
    private CogsReaderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CogsReaderService();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal WC line item with the given meta_data entries.
     *
     * @param array<string, mixed> $meta  key→value pairs to put in meta_data
     * @param int                  $qty
     * @return array<string, mixed>
     */
    private function lineItem(array $meta = [], int $qty = 1): array
    {
        return [
            'quantity'  => $qty,
            'meta_data' => array_map(
                fn (string $key, mixed $val) => ['key' => $key, 'value' => $val],
                array_keys($meta),
                array_values($meta),
            ),
        ];
    }

    // -------------------------------------------------------------------------
    // No COGS data
    // -------------------------------------------------------------------------

    public function test_returns_null_when_no_meta_data(): void
    {
        $item = ['quantity' => 1, 'meta_data' => []];

        $this->assertNull($this->service->readFromLineItem($item));
    }

    public function test_returns_null_when_meta_data_key_absent(): void
    {
        $item = $this->lineItem(['_some_other_key' => '5.00']);

        $this->assertNull($this->service->readFromLineItem($item));
    }

    public function test_returns_null_when_line_item_has_no_keys(): void
    {
        $this->assertNull($this->service->readFromLineItem([]));
    }

    // -------------------------------------------------------------------------
    // WC native COGS (_wc_cogs_total_cost)
    // -------------------------------------------------------------------------

    public function test_returns_unit_cost_by_dividing_total_by_qty(): void
    {
        $item   = $this->lineItem(['_wc_cogs_total_cost' => '30.00'], qty: 3);
        $result = $this->service->readFromLineItem($item);

        $this->assertEqualsWithDelta(10.0, $result, 0.0001);
    }

    public function test_qty_1_returns_total_as_unit(): void
    {
        $item   = $this->lineItem(['_wc_cogs_total_cost' => '12.50'], qty: 1);
        $result = $this->service->readFromLineItem($item);

        $this->assertEqualsWithDelta(12.5, $result, 0.0001);
    }

    public function test_rounds_to_4_decimal_places(): void
    {
        // 10.00 / 3 = 3.3333...
        $item   = $this->lineItem(['_wc_cogs_total_cost' => '10.00'], qty: 3);
        $result = $this->service->readFromLineItem($item);

        $this->assertEqualsWithDelta(3.3333, $result, 0.00005);
    }

    public function test_returns_null_when_zero(): void
    {
        $item = $this->lineItem(['_wc_cogs_total_cost' => '0.00'], qty: 2);

        $this->assertNull($this->service->readFromLineItem($item));
    }

    public function test_returns_null_when_negative(): void
    {
        $item = $this->lineItem(['_wc_cogs_total_cost' => '-5.00'], qty: 1);

        $this->assertNull($this->service->readFromLineItem($item));
    }

    public function test_returns_null_for_non_numeric_meta_value(): void
    {
        $item = $this->lineItem(['_wc_cogs_total_cost' => 'N/A']);

        $this->assertNull($this->service->readFromLineItem($item));
    }

    public function test_handles_meta_entry_without_key(): void
    {
        // Malformed meta entry — should be silently skipped.
        $item = [
            'quantity'  => 1,
            'meta_data' => [
                ['value' => '5.00'],                           // no key
                ['key' => '_wc_cogs_total_cost', 'value' => '9.00'],
            ],
        ];

        $result = $this->service->readFromLineItem($item);

        $this->assertEqualsWithDelta(9.0, $result, 0.0001);
    }

    public function test_handles_missing_quantity_field(): void
    {
        // quantity absent — service clamps to 1, so total_cost IS the unit_cost.
        $item = [
            'meta_data' => [['key' => '_wc_cogs_total_cost', 'value' => '9.00']],
        ];

        $result = $this->service->readFromLineItem($item);

        $this->assertEqualsWithDelta(9.0, $result, 0.0001);
    }
}
