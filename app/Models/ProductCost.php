<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manual COGS fallback when no WooCommerce plugin or Shopify snapshot is available.
 *
 * Two entry paths: CSV upload or manual UI entry. Supports dated ranges so cost changes
 * over time don't corrupt historical contribution margin calculations.
 *
 * CogsReaderService checks this table last — after WC plugin meta and Shopify snapshots.
 * effective_to=NULL means the row is currently active.
 *
 * Written by: ProductCostImportAction (CSV), ProductCostController (manual).
 * Read by: CogsReaderService.
 * @see PLANNING.md section 5, 7
 */
#[ScopedBy([WorkspaceScope::class])]
class ProductCost extends Model
{
    protected $fillable = [
        'workspace_id',
        'store_id',
        'product_external_id',
        'unit_cost',
        'currency',
        'effective_from',
        'effective_to',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost'      => 'decimal:4',
            'effective_from' => 'date',
            'effective_to'   => 'date',
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
}
