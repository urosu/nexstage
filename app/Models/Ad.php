<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([WorkspaceScope::class])]
class Ad extends Model
{
    protected $fillable = [
        'workspace_id',
        'adset_id',
        'external_id',
        'name',
        'status',
        'effective_status',
        'destination_url',
        'creative_data',
        'creative_data_api_version',
    ];

    protected function casts(): array
    {
        return [
            'creative_data' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function adset(): BelongsTo
    {
        return $this->belongsTo(Adset::class);
    }

    public function adInsights(): HasMany
    {
        return $this->hasMany(AdInsight::class);
    }

    /** @return BelongsToMany<CreativeTag> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CreativeTag::class, 'ad_creative_tags')
            ->withPivot(['confidence', 'source', 'tagged_at'])
            ->withTimestamps();
    }
}
