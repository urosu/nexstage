<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([WorkspaceScope::class])]
class AdAccount extends Model
{
    use HasFactory;
    protected $fillable = [
        'workspace_id',
        'platform',
        'external_id',
        'name',
        'currency',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'token_expires_at',
        'status',
        'consecutive_sync_failures',
        'last_synced_at',
        'historical_import_status',
        'historical_import_from',
        'historical_import_checkpoint',
        'historical_import_progress',
        'historical_import_started_at',
        'historical_import_completed_at',
        'historical_import_duration_seconds',
    ];

    protected $hidden = [
        'access_token_encrypted',
        'refresh_token_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at'               => 'datetime',
            'last_synced_at'                 => 'datetime',
            'historical_import_from'         => 'date',
            'historical_import_checkpoint'   => 'array',
            'historical_import_started_at'   => 'datetime',
            'historical_import_completed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function adInsights(): HasMany
    {
        return $this->hasMany(AdInsight::class);
    }
}
