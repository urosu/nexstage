<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[ScopedBy([WorkspaceScope::class])]
class AiSummary extends Model
{
    protected $fillable = [
        'workspace_id',
        'date',
        'summary_text',
        'payload_sent',
        'model_used',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'payload_sent' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function inboxItems(): MorphMany
    {
        return $this->morphMany(InboxItem::class, 'itemable');
    }

    protected static function booted(): void
    {
        static::created(function (AiSummary $summary): void {
            InboxItem::create([
                'workspace_id'  => $summary->workspace_id,
                'itemable_type' => self::class,
                'itemable_id'   => $summary->id,
                'status'        => InboxItem::STATUS_OPEN,
            ]);
        });
    }
}
