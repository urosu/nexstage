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
        'customer_id',
        'payment_method',
        'payment_method_title',
        'shipping_country',
        'refund_amount',
        'last_refunded_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'source_type',
        'attribution_source',
        'attribution_first_touch',
        'attribution_last_touch',
        'attribution_click_ids',
        'attribution_parsed_at',
        'raw_meta',
        'raw_meta_api_version',
        'platform_data',
        'payment_fee',
        'is_first_for_customer',
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
            'refund_amount' => 'decimal:2',
            'last_refunded_at' => 'datetime',
            'attribution_first_touch' => 'array',
            'attribution_last_touch' => 'array',
            'attribution_click_ids' => 'array',
            'attribution_parsed_at' => 'datetime',
            'raw_meta' => 'array',
            'platform_data' => 'array',
            'payment_fee' => 'decimal:4',
            'is_first_for_customer' => 'boolean',
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

    public function coupons(): HasMany
    {
        return $this->hasMany(OrderCoupon::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
