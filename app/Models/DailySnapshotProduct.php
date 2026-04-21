<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Replaces daily_snapshots.top_products JSONB — top 50 products per store per day by revenue.
// Populated by: ComputeDailySnapshotJob (writes top 50 per store per day).
// Read by: Products analytics page controller.
// Related: app/Jobs/ComputeDailySnapshotJob.php
#[ScopedBy([WorkspaceScope::class])]
class DailySnapshotProduct extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'store_id',
        'snapshot_date',
        'product_external_id',
        'product_name',
        'revenue',
        'unit_cost',
        'units',
        'rank',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'created_at' => 'datetime',
            'revenue'   => 'decimal:4',
            'unit_cost' => 'decimal:4',
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
