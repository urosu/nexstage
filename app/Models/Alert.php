<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[ScopedBy([WorkspaceScope::class])]
class Alert extends Model
{
    protected $fillable = [
        'workspace_id',
        'store_id',
        'ad_account_id',
        'property_id',
        'type',
        'severity',
        'source',
        'data',
        'is_silent',
        'review_status',
        'reviewed_at',
        'estimated_impact_low',
        'estimated_impact_high',
        'gsc_conversion_rate_at_alert',
        'store_aov_at_alert',
        'read_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_silent' => 'boolean',
            'reviewed_at' => 'datetime',
            'estimated_impact_low' => 'decimal:2',
            'estimated_impact_high' => 'decimal:2',
            'gsc_conversion_rate_at_alert' => 'decimal:6',
            'store_aov_at_alert' => 'decimal:2',
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function adAccount(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(SearchConsoleProperty::class, 'property_id');
    }

    public function inboxItems(): MorphMany
    {
        return $this->morphMany(InboxItem::class, 'itemable');
    }

    protected static function booted(): void
    {
        static::created(function (Alert $alert): void {
            InboxItem::create([
                'workspace_id'  => $alert->workspace_id,
                'itemable_type' => self::class,
                'itemable_id'   => $alert->id,
                'status'        => $alert->resolved_at !== null
                    ? InboxItem::STATUS_DONE
                    : InboxItem::STATUS_OPEN,
            ]);
        });
    }
}
