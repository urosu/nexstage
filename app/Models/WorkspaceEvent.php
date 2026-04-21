<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAnnotationScope;
use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Workspace-specific promotions and expected spikes/drops for baseline adjustment.
// NOT for holidays — those are in the global holidays table.
//
// event_type: promotion | expected_spike | expected_drop
// is_auto_detected / needs_review: Phase 2 auto-detection from coupon usage patterns.
// suppress_anomalies: when true, DetectAnomaliesJob skips anomaly firing for these date ranges.
//
// Consumed by: DetectAnomaliesJob (suppress alerts), ComputeMetricBaselinesJob (exclude from window),
//              chart event overlay markers (Phase 1).
// Related: app/Jobs/DetectAnomaliesJob.php, app/Jobs/ComputeMetricBaselinesJob.php
// See: PLANNING.md "workspace_events"
#[ScopedBy([WorkspaceScope::class])]
class WorkspaceEvent extends Model
{
    use HasAnnotationScope;
    protected $fillable = [
        'workspace_id',
        'event_type',
        'name',
        'date_from',
        'date_to',
        'is_auto_detected',
        'needs_review',
        'suppress_anomalies',
        'scope_type',
        'scope_id',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'is_auto_detected' => 'boolean',
            'needs_review' => 'boolean',
            'suppress_anomalies' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
