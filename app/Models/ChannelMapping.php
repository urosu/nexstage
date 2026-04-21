<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Classifies (utm_source, utm_medium) pairs into named channels and channel types.
 *
 * Global rows (workspace_id=NULL, is_global=true) are seeded from PLANNING section 16.4.
 * Workspace-scoped rows override global rows — ChannelClassifierService prefers
 * the workspace row when both exist for the same (source_pattern, medium_pattern).
 *
 * utm_medium_pattern=NULL means "match any medium for this source".
 *
 * Written by: channel_mappings migration (global seed), UI classify action (workspace overrides).
 * Read by: ChannelClassifierService.
 * @see PLANNING.md section 16.4
 */
class ChannelMapping extends Model
{
    protected $fillable = [
        'workspace_id',
        'utm_source_pattern',
        'utm_medium_pattern',
        'channel_name',
        'channel_type',
        'is_global',
        'is_regex',
    ];

    protected function casts(): array
    {
        return [
            'is_global' => 'boolean',
            'is_regex'  => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
