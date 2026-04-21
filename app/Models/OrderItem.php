<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// WorkspaceScope intentionally NOT applied to this model.
// Why: order_items has no workspace_id or store_id columns — tenant isolation is
// guaranteed through the parent order. All queries MUST go through $order->items()
// or whereIn('order_id', ...) with order IDs already scoped to the workspace.
// Direct OrderItem:: queries are only safe when filtering by known order IDs.
// See: PLANNING.md "order_items query audit (SECURITY)"
class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_external_id',
        'product_name',
        'variant_name',
        'sku',
        'quantity',
        'unit_price',
        'unit_cost',
        'discount_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'      => 'decimal:4',
            'unit_cost'       => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'line_total'      => 'decimal:4',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
