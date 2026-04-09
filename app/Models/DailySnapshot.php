<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([WorkspaceScope::class])]
class DailySnapshot extends Model
{
    use HasFactory;
    protected $fillable = [
        'workspace_id',
        'store_id',
        'date',
        'orders_count',
        'revenue',
        'revenue_native',
        'aov',
        'items_sold',
        'items_per_order',
        'new_customers',
        'returning_customers',
        'revenue_by_country',
        'top_products',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'revenue' => 'decimal:4',
            'revenue_native' => 'decimal:4',
            'aov' => 'decimal:4',
            'items_per_order' => 'decimal:2',
            'revenue_by_country' => 'array',
            'top_products' => 'array',
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
