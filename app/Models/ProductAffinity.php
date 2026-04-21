<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frequently-Bought-Together associations computed by ComputeProductAffinitiesJob.
 *
 * Apriori-style query over last 90 days of order_items, run weekly on Sundays.
 * Unique on (workspace_id, store_id, product_a_id, product_b_id) — the job
 * replaces the full workspace batch by deleting and reinserting on each run.
 *
 * margin_lift = confidence × contribution margin. The differentiating metric vs
 * naive FBT; requires COGS data. NULL when COGS is not configured on the store.
 *
 * Written by: ComputeProductAffinitiesJob (Phase 1.6).
 * Read by: product detail pages ("Frequently bought with X").
 * @see PLANNING.md section 5
 */
#[ScopedBy([WorkspaceScope::class])]
class ProductAffinity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'store_id',
        'product_a_id',
        'product_b_id',
        'support',
        'confidence',
        'lift',
        'margin_lift',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'support'      => 'decimal:6',
            'confidence'   => 'decimal:6',
            'lift'         => 'decimal:4',
            'margin_lift'  => 'decimal:4',
            'calculated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function productA(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_a_id');
    }

    public function productB(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_b_id');
    }
}
