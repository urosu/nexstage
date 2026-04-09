<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\FxRateNotFoundException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Scopes\WorkspaceScope;
use App\Services\Fx\FxRateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Maps a raw WooCommerce order payload to our schema and upserts the order + items.
 *
 * Precondition: WorkspaceContext MUST be set by the calling job before invoking
 * this action. The action uses workspace-scoped Eloquent queries internally.
 *
 * FX conversion:
 *   - On success: total_in_reporting_currency is populated.
 *   - On FxRateNotFoundException: total_in_reporting_currency is left NULL.
 *     RetryMissingConversionJob handles NULLs nightly — never treat NULL as 0.
 *
 * Order items:
 *   WooCommerce sends the full item list on every webhook/sync. We delete the
 *   existing items for the order and re-insert inside a single transaction.
 *   This is idempotent and handles item additions/removals correctly. The
 *   expression unique index on order_items (order_id, product_external_id,
 *   COALESCE(variant_name, '')) cannot be used with Eloquent::upsert() when
 *   variant_name is nullable, so delete+insert within a transaction is the
 *   correct pattern for this table.
 */
class UpsertWooCommerceOrderAction
{
    public function __construct(
        private readonly FxRateService $fx,
    ) {}

    /**
     * @param array<string, mixed> $wcOrder  Raw WooCommerce REST API order object.
     */
    public function handle(Store $store, string $reportingCurrency, array $wcOrder): void
    {
        $externalId = (string) $wcOrder['id'];

        $occurredAt = Carbon::parse($wcOrder['date_created_gmt'] ?? 'now')->utc();

        $orderCurrency = (string) ($wcOrder['currency'] ?? $store->currency);
        $total         = (float) ($wcOrder['total'] ?? 0);

        $totalInReporting = $this->convertTotal($total, $orderCurrency, $reportingCurrency, $occurredAt, $store->id, $externalId);

        $orderRow = [
            'workspace_id'                => $store->workspace_id,
            'store_id'                    => $store->id,
            'external_id'                 => $externalId,
            'external_number'             => $this->nullableString($wcOrder['number'] ?? null),
            'status'                      => $this->mapStatus((string) ($wcOrder['status'] ?? '')),
            'currency'                    => $orderCurrency,
            'total'                       => $total,
            'subtotal'                    => (float) ($wcOrder['subtotal'] ?? 0),
            'tax'                         => (float) ($wcOrder['total_tax'] ?? 0),
            'shipping'                    => (float) ($wcOrder['shipping_total'] ?? 0),
            'discount'                    => (float) ($wcOrder['discount_total'] ?? 0),
            'total_in_reporting_currency' => $totalInReporting,
            'customer_email_hash'         => $this->hashEmail($wcOrder['billing']['email'] ?? ''),
            'customer_country'            => $this->nullableString($wcOrder['billing']['country'] ?? null),
            'utm_source'                  => $this->utmMeta($wcOrder, '_utm_source'),
            'utm_medium'                  => $this->utmMeta($wcOrder, '_utm_medium'),
            'utm_campaign'                => $this->utmMeta($wcOrder, '_utm_campaign'),
            'utm_content'                 => $this->utmMeta($wcOrder, '_utm_content'),
            'occurred_at'                 => $occurredAt->toDateTimeString(),
            'synced_at'                   => now()->toDateTimeString(),
            'created_at'                  => now()->toDateTimeString(),
            'updated_at'                  => now()->toDateTimeString(),
        ];

        DB::transaction(function () use ($store, $externalId, $orderRow, $wcOrder): void {
            // Upsert the order. created_at is excluded from update to preserve
            // the original ingestion timestamp on re-syncs.
            Order::upsert(
                [$orderRow],
                uniqueBy: ['store_id', 'external_id'],
                update: [
                    'external_number', 'status', 'currency', 'total', 'subtotal',
                    'tax', 'shipping', 'discount', 'total_in_reporting_currency',
                    'customer_email_hash', 'customer_country', 'utm_source',
                    'utm_medium', 'utm_campaign', 'utm_content',
                    'occurred_at', 'synced_at', 'updated_at',
                ],
            );

            // Retrieve the order PK — needed to associate items.
            $orderId = Order::where('store_id', $store->id)
                ->where('external_id', $externalId)
                ->value('id');

            if ($orderId === null) {
                throw new \RuntimeException(
                    "Order not found after upsert: store={$store->id}, external_id={$externalId}"
                );
            }

            // Replace all items atomically. WooCommerce sends the full line item
            // list on every event, so we can safely replace rather than diff.
            OrderItem::where('order_id', $orderId)->delete();

            $itemRows = $this->buildItemRows($orderId, $store, $wcOrder['line_items'] ?? []);

            if (! empty($itemRows)) {
                OrderItem::insert($itemRows);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function convertTotal(
        float  $total,
        string $orderCurrency,
        string $reportingCurrency,
        Carbon $occurredAt,
        int    $storeId,
        string $externalId,
    ): ?float {
        try {
            return $this->fx->convert($total, $orderCurrency, $reportingCurrency, $occurredAt);
        } catch (FxRateNotFoundException $e) {
            Log::warning('UpsertWooCommerceOrderAction: FX rate not found; total_in_reporting_currency set to NULL', [
                'store_id'            => $storeId,
                'external_id'         => $externalId,
                'order_currency'      => $orderCurrency,
                'reporting_currency'  => $reportingCurrency,
                'date'                => $occurredAt->toDateString(),
            ]);

            return null;
        }
    }

    private function mapStatus(string $wcStatus): string
    {
        return match ($wcStatus) {
            'completed'  => 'completed',
            'processing' => 'processing',
            'refunded'   => 'refunded',
            'cancelled'  => 'cancelled',
            default      => 'other',
        };
    }

    private function hashEmail(string $email): ?string
    {
        $normalised = trim(strtolower($email));

        return $normalised !== '' ? hash('sha256', $normalised) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * Extract a UTM value from the order's top-level meta_data array.
     *
     * @param array<string, mixed> $wcOrder
     */
    private function utmMeta(array $wcOrder, string $key): ?string
    {
        foreach ($wcOrder['meta_data'] ?? [] as $meta) {
            if (($meta['key'] ?? '') === $key) {
                $val = $meta['value'] ?? null;
                return $val !== null && $val !== '' ? (string) $val : null;
            }
        }

        return null;
    }

    /**
     * Build the rows to insert into order_items for a single order.
     *
     * @param  array<int, array<string, mixed>> $lineItems
     * @return array<int, array<string, mixed>>
     */
    private function buildItemRows(int $orderId, Store $store, array $lineItems): array
    {
        $rows = [];
        $now  = now()->toDateTimeString();

        foreach ($lineItems as $item) {
            $rows[] = [
                'order_id'            => $orderId,
                'workspace_id'        => $store->workspace_id,
                'store_id'            => $store->id,
                'product_external_id' => (string) ($item['product_id'] ?? 0),
                'product_name'        => (string) ($item['name'] ?? ''),
                'variant_name'        => $this->extractVariantName($item),
                'sku'                 => $this->nullableString($item['sku'] ?? null),
                'quantity'            => (int) ($item['quantity'] ?? 0),
                'unit_price'          => (float) ($item['price'] ?? 0),
                'line_total'          => (float) ($item['total'] ?? 0),
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        return $rows;
    }

    /**
     * Derive the variant name from a WooCommerce line item's meta_data.
     *
     * Returns null for non-variation items (variation_id === 0).
     * For variations: concatenates the display_value of all non-internal
     * meta entries (display_key not starting with '_').
     *
     * @param array<string, mixed> $item
     */
    private function extractVariantName(array $item): ?string
    {
        if (($item['variation_id'] ?? 0) === 0) {
            return null;
        }

        $parts = [];

        foreach ($item['meta_data'] ?? [] as $meta) {
            $displayKey   = (string) ($meta['display_key'] ?? '');
            $displayValue = (string) ($meta['display_value'] ?? '');

            if ($displayKey === '' || str_starts_with($displayKey, '_') || $displayValue === '') {
                continue;
            }

            $parts[] = $displayValue;
        }

        return ! empty($parts) ? implode(', ', $parts) : null;
    }
}
