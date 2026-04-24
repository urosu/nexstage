<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single prescriptive suggestion surfaced on Home (Today's Attention),
 * Acquisition (opportunities sidebar), and Inbox.
 *
 * Produced by nightly Phase 4.3 agents and by page controllers that detect
 * cross-integration patterns. Each row carries a templated title/body, an
 * optional monetary impact estimate, a deep-link target_url, and a
 * type-specific data JSONB payload consumed by front-end templates.
 *
 * Status lifecycle: open → done | snoozed (until snoozed_until) | dismissed.
 * Active scope = open rows whose snooze window has expired.
 *
 * @see PROGRESS.md §Destination specs — Home/Acquisition/Inbox
 * @see PROGRESS.md §F-series formulas for impact_estimate computations
 *
 * @property int         $id
 * @property int         $workspace_id
 * @property string      $type
 * @property int         $priority
 * @property string      $title
 * @property string      $body
 * @property float|null  $impact_estimate
 * @property string|null $impact_currency
 * @property string|null $target_url
 * @property array|null  $data
 * @property string      $status
 * @property \Carbon\Carbon|null $snoozed_until
 */
#[ScopedBy([WorkspaceScope::class])]
class Recommendation extends Model
{
    public const STATUS_OPEN      = 'open';
    public const STATUS_DONE      = 'done';
    public const STATUS_SNOOZED   = 'snoozed';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'workspace_id',
        'type',
        'priority',
        'title',
        'body',
        'impact_estimate',
        'impact_currency',
        'target_url',
        'data',
        'status',
        'snoozed_until',
    ];

    protected function casts(): array
    {
        return [
            'impact_estimate' => 'decimal:4',
            'data'            => 'array',
            'snoozed_until'   => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Open rows whose snooze window (if any) has passed. */
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
