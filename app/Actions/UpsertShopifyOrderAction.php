<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\FxRateNotFoundException;
use App\Models\Order;
use App\Models\OrderCoupon;
use App\Models\OrderItem;
use App\Models\Store;
use App\Services\Attribution\AttributionParserService;
use App\Services\Fx\FxRateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Maps a raw Shopify GraphQL order node to our schema and upserts the order + items + coupons.
 *
 * Attribution:
 *   Shopify's customerJourneySummary is stored in platform_data so that
 *   ShopifyCustomerJourneySource can read it. AttributionParserService is then
 *   invoked and writes to attribution_* columns. The utm_* columns are left NULL
 *   for Shopify orders — RevenueAttributionService reads attribution_last_touch
 *   since the Phase 1.5 Step 14 cutover.
 *
 * COGS:
 *   Shopify does not embed unit cost in order item data. Cost is looked up from
 *   daily_snapshot_products.unit_cost (most recent snapshot ≤ order date).
 *   If no snapshot exists yet, platform_data.cogs_note is set to 'pre_snapshot'
 *   so the frontend can show an "Est." badge.
 *
 * Status mapping:
 *   Shopify displayFinancialStatus (ALL_CAPS enum) → our normalised status string.
 *
 * GID handling:
 *   Shopify GraphQL returns global IDs like "gid://shopify/Order/12345".
 *   We store only the numeric part (legacyResourceId) as external_id so it is
 *   consistent with webhook payloads which deliver the plain numeric ID.
 *
 * FX conversion:
 *   Same pattern as UpsertWooCommerceOrderAction — NULL on FxRateNotFoundException;
 *   RetryMissingConversionJob handles nightly.
 *
 * Order items:
 *   Deleted and re-inserted atomically (same as WC) — Shopify sends the full
 *   line item list on every sync/webhook.
 *
 * Called by: ShopifyConnector::syncOrders(), ProcessShopifyWebhookJob (Step 5)
 * Reads from: Shopify GraphQL order node (raw array)
 * Writes to: orders, order_items, order_coupons
 *
 * Related: app/Actions/UpsertWooCommerceOrderAction.php
 * See: PLANNING.md "Phase 2 — Shopify" Step 3
 * @see PLANNING.md section Phase 2
 */
class UpsertShopifyOrderAction
{
    public function __construct(
        private readonly FxRateService $fx,
        private readonly AttributionParserService $parser,
    ) {}

    /**
     * @param array<string, mixed> $shopifyOrder  Raw Shopify GraphQL order node.
     */
    public function handle(Store $store, string $reportingCurrency, array $shopifyOrder): void
    {
        // Shopify delivers the numeric order ID via legacyResourceId; fall back to
        // stripping the GID prefix from the `id` field.
        $externalId = (string) ($shopifyOrder['legacyResourceId']
            ?? $this->gidToNumeric($shopifyOrder['id'] ?? ''));

        if ($externalId === '') {
            Log::warning('UpsertShopifyOrderAction: missing order ID, skipping', [
                'store_id' => $store->id,
                'raw_id'   => $shopifyOrder['id'] ?? null,
            ]);
            return;
        }

        $occurredAt = Carbon::parse($shopifyOrder['createdAt'] ?? 'now')->utc();

        $moneyNode     = $shopifyOrder['currentTotalPriceSet']['shopMoney'] ?? [];
        $orderCurrency = strtoupper((string) ($moneyNode['currencyCode'] ?? $store->currency));
        $total         = (float) ($moneyNode['amount'] ?? 0);

        $totalInReporting = $this->convertTotal(
            $total, $orderCurrency, $reportingCurrency, $occurredAt, $store->id, $externalId
        );

        // Store raw customerJourneySummary in platform_data so ShopifyCustomerJourneySource
        // can read it from $order->platform_data without a separate DB column.
        $journeySummary = $shopifyOrder['customerJourneySummary'] ?? null;

        $platformData = array_filter([
            'order_name'               => $shopifyOrder['name'] ?? null,
            'customer_journey_summary' => $journeySummary,
        ]);

        $orderRow = [
            'workspace_id'                => $store->workspace_id,
            'store_id'                    => $store->id,
            'external_id'                 => $externalId,
            'external_number'             => $shopifyOrder['name'] ?? null,
            'status'                      => $this->mapStatus((string) ($shopifyOrder['displayFinancialStatus'] ?? '')),
            'currency'                    => $orderCurrency,
            'total'                       => $total,
            'subtotal'                    => (float) ($shopifyOrder['subtotalPriceSet']['shopMoney']['amount'] ?? 0),
            'tax'                         => (float) ($shopifyOrder['totalTaxSet']['shopMoney']['amount'] ?? 0),
            'shipping'                    => (float) ($shopifyOrder['totalShippingPriceSet']['shopMoney']['amount'] ?? 0),
            'discount'                    => (float) ($shopifyOrder['totalDiscountsSet']['shopMoney']['amount'] ?? 0),
            'total_in_reporting_currency' => $totalInReporting,
            'customer_email_hash'         => $this->hashEmail((string) ($shopifyOrder['email'] ?? '')),
            'customer_country'            => $this->nullableString($shopifyOrder['billingAddress']['countryCode'] ?? null),
            'customer_id'                 => $this->nullableGid($shopifyOrder['customer']['id'] ?? null),
            'payment_method'              => $this->nullableString(($shopifyOrder['paymentGatewayNames'] ?? [])[0] ?? null),
            'payment_method_title'        => null, // Shopify doesn't expose a display name separate from gateway ID
            'shipping_country'            => $this->nullableString($shopifyOrder['shippingAddress']['countryCode'] ?? null),
            // utm_* columns stay NULL for Shopify — attribution goes through platform_data → ShopifyCustomerJourneySource
            'utm_source'   => null,
            'utm_medium'   => null,
            'utm_campaign' => null,
            'utm_content'  => null,
            'utm_term'     => null,
            'source_type'  => null,
            'raw_meta'             => null,
            'raw_meta_api_version' => null,
            'platform_data'     => ! empty($platformData) ? json_encode($platformData) : null,
            'occurred_at'       => $occurredAt->toDateTimeString(),
            'synced_at'         => now()->toDateTimeString(),
            'created_at'        => now()->toDateTimeString(),
            'updated_at'        => now()->toDateTimeString(),
        ];

        $updateColumns = [
            'external_number', 'status', 'currency', 'total', 'subtotal',
            'tax', 'shipping', 'discount', 'total_in_reporting_currency',
            'customer_email_hash', 'customer_country', 'customer_id',
            'payment_method', 'shipping_country',
            'raw_meta_api_version', 'platform_data',
            'occurred_at', 'synced_at', 'updated_at',
        ];

        // Attribution pipeline — always enabled for Shopify (no feature flag needed
        // since Shopify orders have no existing utm_* data to protect during rollout).
        $parsed = $this->runParser($store, $orderRow, $journeySummary);

        $orderRow['attribution_source']      = $parsed->source_type;
        $orderRow['attribution_first_touch'] = $parsed->toTouchArray($parsed->first_touch) !== null
            ? json_encode($parsed->toTouchArray($parsed->first_touch))
            : null;
        $orderRow['attribution_last_touch'] = $parsed->toTouchArray($parsed->last_touch) !== null
            ? json_encode($parsed->toTouchArray($parsed->last_touch))
            : null;
        $orderRow['attribution_click_ids']   = $parsed->click_ids !== null
            ? json_encode($parsed->click_ids)
            : null;
        $orderRow['attribution_parsed_at']   = now()->toDateTimeString();

        $updateColumns = array_merge($updateColumns, [
            'attribution_source', 'attribution_first_touch', 'attribution_last_touch',
            'attribution_click_ids', 'attribution_parsed_at',
        ]);

        DB::transaction(function () use ($store, $externalId, $orderRow, $updateColumns, $shopifyOrder, $platformData): void {
            Order::upsert(
                [$orderRow],
                uniqueBy: ['store_id', 'external_id'],
                update: $updateColumns,
            );

            $orderId = Order::withoutGlobalScopes()
                ->where('store_id', $store->id)
                ->where('external_id', $externalId)
                ->value('id');

            if ($orderId === null) {
                throw new \RuntimeException(
                    "Shopify order not found after upsert: store={$store->id}, external_id={$externalId}"
                );
            }

            // Replace line items atomically.
            OrderItem::where('order_id', $orderId)->delete();

            [$itemRows, $anyPreSnapshot] = $this->buildItemRows(
                $orderId, $store,
                $shopifyOrder['lineItems']['edges'] ?? [],
                $shopifyOrder['createdAt'] ?? null,
            );

            if (! empty($itemRows)) {
                OrderItem::insert($itemRows);
            }

            // If any item lacked a COGS snapshot, stamp platform_data.cogs_note so the
            // frontend can show an "Est." badge. We update the column in the same
            // transaction so it is always in sync with the items we just inserted.
            if ($anyPreSnapshot) {
                $updatedPlatformData          = $platformData;
                $updatedPlatformData['cogs_note'] = 'pre_snapshot';

                DB::table('orders')
                    ->where('id', $orderId)
                    ->update(['platform_data' => json_encode($updatedPlatformData)]);
            }

            // Replace discount codes atomically.
            OrderCoupon::where('order_id', $orderId)->delete();

            $couponRows = $this->buildCouponRows($orderId, $shopifyOrder['discountCodes'] ?? []);

            if (! empty($couponRows)) {
                DB::table('order_coupons')->insert($couponRows);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Hydrate a temporary Order for the attribution parser.
     *
     * ShopifyCustomerJourneySource reads $order->platform_data['customer_journey_summary'].
     *
     * @param  array<string, mixed>|null $journeySummary  Raw customerJourneySummary node.
     */
    private function runParser(Store $store, array $orderRow, ?array $journeySummary): \App\ValueObjects\ParsedAttribution
    {
        $tempOrder = new Order();
        $tempOrder->forceFill([
            'workspace_id' => $store->workspace_id,
            'utm_source'   => null,
            'utm_medium'   => null,
            'utm_campaign' => null,
            'utm_content'  => null,
            'utm_term'     => null,
            'source_type'  => null,
            'raw_meta'     => null,
            // platform_data['customer_journey_summary'] is what ShopifyCustomerJourneySource reads.
            'platform_data' => $journeySummary !== null
                ? ['customer_journey_summary' => $journeySummary]
                : null,
        ]);

        return $this->parser->parse($tempOrder);
    }

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
        } catch (FxRateNotFoundException) {
            Log::warning('UpsertShopifyOrderAction: FX rate not found; total_in_reporting_currency set to NULL', [
                'store_id'           => $storeId,
                'external_id'        => $externalId,
                'order_currency'     => $orderCurrency,
                'reporting_currency' => $reportingCurrency,
                'date'               => $occurredAt->toDateString(),
            ]);
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>> $lineItemEdges  GraphQL `lineItems.edges` array.
     * @return array{0: array<int, array<string, mixed>>, 1: bool}  [rows, anyPreSnapshot]
     */
    private function buildItemRows(int $orderId, Store $store, array $lineItemEdges, ?string $orderCreatedAt): array
    {
        $rows           = [];
        $anyPreSnapshot = false;
        $orderDate      = $orderCreatedAt ? Carbon::parse($orderCreatedAt)->utc()->toDateString() : null;

        foreach ($lineItemEdges as $edge) {
            $item = $edge['node'];

            $productExternalId = null;
            if (! empty($item['product']['legacyResourceId'])) {
                $productExternalId = (string) $item['product']['legacyResourceId'];
            } elseif (! empty($item['product']['id'])) {
                $productExternalId = $this->gidToNumeric($item['product']['id']);
            }

            $variantName = null;
            if (! empty($item['variant']['title']) && $item['variant']['title'] !== 'Default Title') {
                $variantName = mb_substr((string) $item['variant']['title'], 0, 255);
            }

            $unitPrice = (float) ($item['originalUnitPriceSet']['shopMoney']['amount'] ?? 0);
            $quantity  = max(1, (int) ($item['quantity'] ?? 1));
            $lineTotal = $unitPrice * $quantity;

            // Sum all discount allocations for this line item.
            $discountAmount = 0.0;
            foreach ($item['discountAllocations'] ?? [] as $allocation) {
                $discountAmount += (float) ($allocation['allocatedAmountSet']['shopMoney']['amount'] ?? 0);
            }

            // COGS lookup: most recent snapshot on or before the order date.
            $unitCost   = null;
            $platformDataNote = null;

            if ($productExternalId !== null && $orderDate !== null) {
                $unitCost = DB::table('daily_snapshot_products')
                    ->where('store_id', $store->id)
                    ->where('product_external_id', $productExternalId)
                    ->whereDate('snapshot_date', '<=', $orderDate)
                    ->whereNotNull('unit_cost')
                    ->orderByDesc('snapshot_date')
                    ->value('unit_cost');

                if ($unitCost === null) {
                    // No snapshot with a unit_cost before this order — mark as pre-snapshot estimate.
                    $anyPreSnapshot = true;
                }
            }

            $rows[] = [
                'order_id'           => $orderId,
                'product_external_id' => $productExternalId,
                'product_name'       => mb_substr((string) ($item['title'] ?? ''), 0, 500),
                'variant_name'       => $variantName,
                'sku'                => mb_substr((string) ($item['sku'] ?? ''), 0, 255) ?: null,
                'quantity'           => $quantity,
                'unit_price'         => $unitPrice,
                'unit_cost'          => $unitCost !== null ? (float) $unitCost : null,
                'discount_amount'    => $discountAmount > 0 ? $discountAmount : null,
                'line_total'         => $lineTotal,
                'created_at'         => now()->toDateTimeString(),
                'updated_at'         => now()->toDateTimeString(),
            ];
        }

        return [$rows, $anyPreSnapshot];
    }

    /**
     * @param  array<int, string> $discountCodes  Plain code strings (API 2024-01+).
     * @return array<int, array<string, mixed>>
     */
    private function buildCouponRows(int $orderId, array $discountCodes): array
    {
        $rows = [];

        foreach ($discountCodes as $dc) {
            // API 2024-01+ returns discountCodes as [String!]! — plain code strings.
            $code = (string) $dc;

            if ($code === '') {
                continue;
            }

            $rows[] = [
                'order_id'        => $orderId,
                'coupon_code'     => mb_substr($code, 0, 255),
                'discount_type'   => null,
                'discount_amount' => 0,
                'created_at'      => now()->toDateTimeString(),
            ];
        }

        return $rows;
    }

    private function mapStatus(string $shopifyStatus): string
    {
        return match (strtoupper($shopifyStatus)) {
            'PAID'             => 'completed',
            'REFUNDED'         => 'refunded',
            'PARTIALLY_REFUNDED' => 'partially_refunded',
            'AUTHORIZED', 'PARTIALLY_PAID' => 'processing',
            'PENDING'          => 'pending',
            'VOIDED'           => 'cancelled',
            default            => 'other',
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
     * Extract the numeric part from a Shopify GID: "gid://shopify/Customer/12345" → "12345".
     */
    private function gidToNumeric(string $gid): string
    {
        return (string) (basename($gid) ?: $gid);
    }

    /**
     * Extract the numeric portion from a nullable GID.
     */
    private function nullableGid(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->gidToNumeric((string) $value);
    }
}
