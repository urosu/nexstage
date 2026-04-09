<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([WorkspaceScope::class])]
class Product extends Model
{
    protected $fillable = [
        'workspace_id',
        'store_id',
        'external_id',
        'name',
        'sku',
        'price',
        'status',
        'image_url',
        'product_url',
        'platform_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'platform_updated_at' => 'datetime',
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
