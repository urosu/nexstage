<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([WorkspaceScope::class])]
class AdInsight extends Model
{
    use HasFactory;
    protected $fillable = [
        'workspace_id',
        'ad_account_id',
        'level',
        'campaign_id',
        'adset_id',
        'ad_id',
        'date',
        'hour',
        'spend',
        'spend_in_reporting_currency',
        'impressions',
        'clicks',
        'reach',
        'ctr',
        'cpc',
        'platform_roas',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'spend' => 'decimal:4',
            'spend_in_reporting_currency' => 'decimal:4',
            'ctr' => 'decimal:6',
            'cpc' => 'decimal:4',
            'platform_roas' => 'decimal:4',
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

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function adset(): BelongsTo
    {
        return $this->belongsTo(Adset::class);
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }
}
