<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Polymorphic wrapper aggregating the items shown on /inbox (Alerts,
 * AiSummaries, DailyNotes). Holds inbox-specific presentation state
 * (status, snoozed_until) so underlying models don't need to learn
 * about the inbox lifecycle.
 *
 * Recommendations are surfaced directly (they have their own status
 * + snoozed_until) and are NOT wrapped by this model.
 *
 * Status lifecycle: open → done | dismissed; or snoozed_until set
 * (auto-resurfaces when now() > snoozed_until).
 *
 * @see PROGRESS.md §Phase 4.2 — Inbox destination
 *
 * @property int                 $id
 * @property int                 $workspace_id
 * @property string              $itemable_type
 * @property int                 $itemable_id
 * @property string              $status
 * @property \Carbon\Carbon|null $snoozed_until
 */
#[ScopedBy([WorkspaceScope::class])]
class InboxItem extends Model
{
    public const STATUS_OPEN      = 'open';
    public const STATUS_DONE      = 'done';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'workspace_id',
        'itemable_type',
        'itemable_id',
        'status',
        'snoozed_until',
    ];

    protected function casts(): array
    {
        return [
            'snoozed_until' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Resolves to Alert / AiSummary / DailyNote via itemable_type + itemable_id. */
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Open rows whose snooze window (if any) has passed — the inbox-visible set. */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_OPEN)
            ->where(function (Builder $q): void {
                $q->whereNull('snoozed_until')
                  ->orWhere('snoozed_until', '<=', now());
            });
    }
}
