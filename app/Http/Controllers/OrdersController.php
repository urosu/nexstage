<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Workspace;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Single order detail page.
 *
 * Shows the full attribution journey for one order: first-touch, last-touch,
 * click IDs, and the parser source that won. Reads `orders.attribution_*`
 * JSONB columns populated by `AttributionParserService` during sync.
 *
 * Route model binding resolves the Order through `WorkspaceScope`, so an
 * order belonging to another workspace 404s instead of leaking.
 *
 * @see PLANNING.md section 6 (Attribution parser output)
 * @see PLANNING.md Phase 1.6 "Order detail page with attribution journey"
 */
class OrdersController extends Controller
{
    public function show(Order $order): Response
    {
        $order->load(['store:id,name,slug', 'items', 'refunds']);

        return Inertia::render('Orders/Show', [
            'order' => [
                'id'                  => $order->id,
                'external_id'         => $order->external_id,
                'external_number'     => $order->external_number,
                'status'              => $order->status,
                'currency'            => $order->currency,
                'total'               => (float) $order->total,
                'subtotal'            => (float) $order->subtotal,
                'tax'                 => (float) $order->tax,
                'shipping'            => (float) $order->shipping,
                'discount'            => (float) $order->discount,
                'refund_amount'       => (float) ($order->refund_amount ?? 0),
                'customer_country'    => $order->customer_country,
                'shipping_country'    => $order->shipping_country,
                'payment_method_title'=> $order->payment_method_title,
                'occurred_at'         => $order->occurred_at?->toISOString(),
                'store'               => $order->store ? [
                    'id'   => $order->store->id,
                    'name' => $order->store->name,
                    'slug' => $order->store->slug,
                ] : null,
                // Legacy utm_* columns (parser inputs) — shown for transparency.
                'utm_source'          => $order->utm_source,
                'utm_medium'          => $order->utm_medium,
                'utm_campaign'        => $order->utm_campaign,
                'utm_content'         => $order->utm_content,
                'utm_term'            => $order->utm_term,
                // Parser output — the canonical attribution record.
                'attribution_source'  => $order->attribution_source,
                'attribution_first_touch' => $order->attribution_first_touch,
                'attribution_last_touch'  => $order->attribution_last_touch,
                'attribution_click_ids'   => $order->attribution_click_ids,
                'attribution_parsed_at'   => $order->attribution_parsed_at?->toISOString(),
                // Shopify-specific: cogs_note='pre_snapshot' when no unit_cost snapshot
                // existed before the order date. Frontend shows "Est." badge on line items.
                'cogs_note' => is_array($order->platform_data) ? ($order->platform_data['cogs_note'] ?? null) : null,
            ],
            'items' => $order->items->map(fn ($item) => [
                'id'              => $item->id,
                'product_name'    => $item->product_name,
                'variant_name'    => $item->variant_name,
                'sku'              => $item->sku,
                'quantity'         => (int) $item->quantity,
                'unit_price'       => (float) $item->unit_price,
                'unit_cost'        => $item->unit_cost !== null ? (float) $item->unit_cost : null,
                'discount_amount'  => (float) $item->discount_amount,
                'line_total'       => (float) $item->line_total,
            ])->values(),
            'refunds' => $order->refunds->map(fn ($refund) => [
                'id'          => $refund->id,
                'amount'      => (float) $refund->amount,
                'reason'      => $refund->reason,
                'refunded_at' => $refund->refunded_at?->toISOString(),
            ])->values(),
        ]);
    }
}
