<?php

declare(strict_types=1);

namespace App\ValueObjects;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Typed wrapper for the `stores.cost_settings` JSONB column.
 *
 * Controls how revenue and costs are adjusted before profit is computed.
 * All three axes — tax deduction, shipping cost mode, fixed monthly overheads —
 * live here. Adding/removing keys never requires a migration.
 *
 * @see PLANNING.md section 6 (profit formula §F3)
 *
 * Reads: Store::$cost_settings cast, ProfitCalculator
 * Writes: StoreController::updateCostSettings
 */
final class StoreCostSettings implements CastsAttributes
{
    // ── Tax ───────────────────────────────────────────────────────────────────

    /**
     * When true, tax is embedded in orders.total and must be deducted to get
     * net revenue. Default true — correct for most EU/VAT stores.
     */
    public bool $deductTax = true;

    /**
     * Fallback tax rate (%) applied to orders where orders.tax = 0.
     * Reverse-calculation is used (rate / (100 + rate)) since prices are inclusive.
     * NULL disables the fallback.
     */
    public ?float $defaultTaxRate = null;

    /**
     * Per-country fallback overrides. ISO 3166-1 alpha-2 → rate (%).
     * Checked before defaultTaxRate.
     *
     * @var array<string, float>
     */
    public array $countryTaxRates = [];

    /**
     * When true, orders with tax = 0 are treated as B2B / tax-exempt and
     * the fallback rate is NOT applied to them.
     */
    public bool $zeroTaxIsB2b = false;

    // ── Shipping ──────────────────────────────────────────────────────────────

    /**
     * How actual fulfillment cost is determined:
     *   'order'      — use orders.shipping as-is (default)
     *   'flat'       — flat rate per order, regardless of what was charged
     *   'percentage' — multiply orders.shipping by shippingPercentage
     */
    public string $shippingCostMode = 'order';

    /** Flat fulfillment cost per order in the store's reporting currency. */
    public ?float $shippingFlatRate = null;

    /**
     * Multiplier applied to orders.shipping when mode = 'percentage'.
     * 1.0 = same as charged; 0.9 = merchant pays 90% of what was charged.
     */
    public ?float $shippingPercentage = null;

    // ── Fixed monthly costs ───────────────────────────────────────────────────

    /**
     * Recurring overhead entries (platform subscription, apps, warehouse, etc.).
     * Shown as a prorated deduction over the selected date range.
     *
     * Each entry: { name: string, amount: float, currency: string }
     *
     * @var array<int, array{name: string, amount: float, currency: string}>
     */
    public array $fixedMonthlyCosts = [];

    // ── WooCommerce COGS meta keys ────────────────────────────────────────────

    /**
     * Extra WooCommerce order-item meta keys to probe for COGS data, beyond the
     * built-in set (WC 10.3+ core, SkyVerge, WPFactory, Booster).
     *
     * value_type: 'unit' → the meta value is cost per unit (most plugins).
     *             'total' → the meta value is total cost for the line; divided by quantity.
     *
     * @var array<int, array{key: string, value_type: 'unit'|'total'}>
     */
    public array $customCogsMetaKeys = [];

    // ── Cast interface ────────────────────────────────────────────────────────

    /** @param array<string, mixed>|string|null $value */
    public function get(Model $model, string $key, mixed $value, array $attributes): self
    {
        $data = [];

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $data    = is_array($decoded) ? $decoded : [];
        } elseif (is_array($value)) {
            $data = $value;
        }

        return self::fromArray($data);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof self) {
            return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
        }

        if (is_array($value)) {
            return json_encode(self::fromArray($value)->toArray(), JSON_THROW_ON_ERROR);
        }

        return json_encode((new self())->toArray(), JSON_THROW_ON_ERROR);
    }

    // ── Factory + serialisation ───────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $s = new self();

        $tax = $data['tax'] ?? [];
        $s->deductTax        = (bool) ($tax['deduct_tax']       ?? true);
        $s->defaultTaxRate   = isset($tax['default_tax_rate'])  ? (float) $tax['default_tax_rate']  : null;
        $s->countryTaxRates  = array_map('floatval', (array) ($tax['country_tax_rates'] ?? []));
        $s->zeroTaxIsB2b     = (bool) ($tax['zero_tax_is_b2b'] ?? false);

        $ship = $data['shipping'] ?? [];
        $s->shippingCostMode   = (string) ($ship['cost_mode']    ?? 'order');
        $s->shippingFlatRate   = isset($ship['flat_rate'])       ? (float) $ship['flat_rate']       : null;
        $s->shippingPercentage = isset($ship['percentage'])      ? (float) $ship['percentage']      : null;

        $s->fixedMonthlyCosts = array_map(
            fn ($entry) => [
                'name'     => (string) ($entry['name']     ?? ''),
                'amount'   => (float)  ($entry['amount']   ?? 0),
                'currency' => (string) ($entry['currency'] ?? 'USD'),
            ],
            array_values(array_filter((array) ($data['fixed_monthly_costs'] ?? []), 'is_array')),
        );

        $cogs = $data['cogs'] ?? [];

        $s->customCogsMetaKeys = array_map(
            fn ($entry) => [
                'key'        => (string) ($entry['key'] ?? ''),
                'value_type' => in_array($entry['value_type'] ?? '', ['unit', 'total'], true) ? $entry['value_type'] : 'unit',
            ],
            array_values(array_filter(
                (array) ($cogs['custom_meta_keys'] ?? []),
                fn ($e) => is_array($e) && isset($e['key']) && $e['key'] !== '',
            )),
        );

        return $s;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tax' => [
                'deduct_tax'        => $this->deductTax,
                'default_tax_rate'  => $this->defaultTaxRate,
                'country_tax_rates' => $this->countryTaxRates,
                'zero_tax_is_b2b'   => $this->zeroTaxIsB2b,
            ],
            'shipping' => [
                'cost_mode'  => $this->shippingCostMode,
                'flat_rate'  => $this->shippingFlatRate,
                'percentage' => $this->shippingPercentage,
            ],
            'fixed_monthly_costs' => $this->fixedMonthlyCosts,
            'cogs'                => [
                'custom_meta_keys' => $this->customCogsMetaKeys,
            ],
        ];
    }

    /**
     * Compute prorated fixed cost total for a given date range.
     * Prorates by days: (days / 30) * monthly_total.
     */
    public function proratedFixedCosts(string $from, string $to): float
    {
        if (empty($this->fixedMonthlyCosts)) {
            return 0.0;
        }

        $days    = (int) (new \DateTime($from))->diff(new \DateTime($to))->days + 1;
        $monthly = array_sum(array_column($this->fixedMonthlyCosts, 'amount'));

        return round($monthly * ($days / 30), 2);
    }
}
