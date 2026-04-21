<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Replaces stores.platform_webhook_ids JSONB — normalized webhook tracking per store.
// Soft-deleted via deleted_at for audit trail (webhook was registered, then removed).
//
// Written by: ConnectStoreAction (register) + store disconnect logic (soft-delete).
// Read by: WooCommerceWebhookController (validate incoming webhook topics).
// Related: app/Actions/ConnectStoreAction.php
// See: PLANNING.md "store_webhooks"
#[ScopedBy([WorkspaceScope::class])]
class StoreWebhook extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'store_id',
        'workspace_id',
        'platform_webhook_id',
        'topic',
        'last_successful_delivery_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'last_successful_delivery_at' => 'datetime',
            'created_at' => 'datetime',
            'deleted_at' => 'datetime',
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
