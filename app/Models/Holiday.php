<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Global reference table — NOT tenant-scoped (no workspace_id, no WorkspaceScope).
// One row per country per holiday per year. No per-workspace duplication.
//
// type='public'     — national/work-free holidays via RefreshHolidaysJob + Yasumi
// type='commercial' — curated ecommerce events via SeedCommercialEventsJob + CommercialEventCalendar
//
// Consumed by: DetectAnomaliesJob (skip detection on holiday dates),
//              ComputeMetricBaselinesJob (exclude holiday dates from baseline window),
//              chart event overlays, SendHolidayNotificationsJob.
// See: PLANNING.md "holidays"
class Holiday extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'country_code',
        'date',
        'name',
        'year',
        'type',
        'category',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
