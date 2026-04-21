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
class Campaign extends Model
{
    use HasFactory;
    protected $fillable = [
        'workspace_id',
        'ad_account_id',
        'external_id',
        'name',
        'previous_names',
        'parsed_convention',
        'status',
        'objective',
        'daily_budget',
        'lifetime_budget',
        'budget_type',
        'bid_strategy',
        'target_value',
        'target_roas',
        'target_cpo',
    ];

    protected function casts(): array
    {
        return [
            'previous_names'    => 'array',
            'parsed_convention' => 'array',
            'daily_budget' => 'decimal:2',
            'lifetime_budget' => 'decimal:2',
            'target_value' => 'decimal:2',
            'target_roas' => 'decimal:2',
            'target_cpo' => 'decimal:2',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function adAccount(): BelongsTo
    {
        return $this->belongsTo(AdAccount::class);
    }

    public function adsets(): HasMany
    {
        return $this->hasMany(Adset::class);
    }

    public function adInsights(): HasMany
    {
        return $this->hasMany(AdInsight::class);
    }
}
