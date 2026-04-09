<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([WorkspaceScope::class])]
class Order extends Model
{
    use HasFactory;
    protected $fillable = [
        'workspace_id',
        'store_id',
        'external_id',
        'external_number',
        'status',
        'currency',
        'total',
        'subtotal',
        'tax',
        'shipping',
        'discount',
        'total_in_reporting_currency',
        'customer_email_hash',
        'customer_country',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'occurred_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'tax' => 'decimal:4',
            'shipping' => 'decimal:4',
            'discount' => 'decimal:4',
            'total_in_reporting_currency' => 'decimal:4',
            'occurred_at' => 'datetime',
            'synced_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
