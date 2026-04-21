<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Populated by: RunLighthouseCheckJob (Phase 1)
// Read by: PerformanceController
// Related: app/Jobs/RunLighthouseCheckJob.php
// See: PLANNING.md "Performance Monitoring — PSI Rate Limit Planning"
#[ScopedBy([WorkspaceScope::class])]
class LighthouseSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'store_id',
        'store_url_id',
        'checked_at',
        'strategy',
        'performance_score',
        'seo_score',
        'accessibility_score',
        'best_practices_score',
        'lcp_ms',
        'fcp_ms',
        'cls_score',
        'inp_ms',
        'ttfb_ms',
        'tbt_ms',
        'crux_source',
        'crux_lcp_p75_ms',
        'crux_inp_p75_ms',
        'crux_cls_p75',
        'crux_fcp_p75_ms',
        'crux_ttfb_p75_ms',
        'raw_response',
        'raw_response_api_version',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at'   => 'datetime',
            'created_at'   => 'datetime',
            'cls_score'    => 'decimal:4',
            'crux_cls_p75' => 'decimal:4',
            'raw_response' => 'array',
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

    public function storeUrl(): BelongsTo
    {
        return $this->belongsTo(StoreUrl::class);
    }
}
