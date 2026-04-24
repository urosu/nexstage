<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAnnotationScope;
use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[ScopedBy([WorkspaceScope::class])]
class DailyNote extends Model
{
    use HasAnnotationScope;
    protected $fillable = [
        'workspace_id',
        'date',
        'note',
        'scope_type',
        'scope_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inboxItems(): MorphMany
    {
        return $this->morphMany(InboxItem::class, 'itemable');
    }

    protected static function booted(): void
    {
        static::created(function (DailyNote $note): void {
            InboxItem::create([
                'workspace_id'  => $note->workspace_id,
                'itemable_type' => self::class,
                'itemable_id'   => $note->id,
                'status'        => InboxItem::STATUS_OPEN,
            ]);
        });
    }
}
