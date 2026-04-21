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
class Store extends Model
{
    use HasFactory;
    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'type',
        'platform',
        'primary_country_code',
        'domain',
        'currency',
        'timezone',
        'platform_store_id',
        'status',
        'consecutive_sync_failures',
        'website_url',
        'auth_key_encrypted',
        'auth_secret_encrypted',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'token_expires_at',
        'webhook_secret_encrypted',
        'historical_import_status',
        'historical_import_from',
        'historical_import_checkpoint',
        'historical_import_progress',
        'historical_import_total_orders',
        'historical_import_started_at',
        'historical_import_completed_at',
        'historical_import_duration_seconds',
        'last_synced_at',
        'target_roas',
        'target_cpo',
        'target_marketing_pct',
    ];

    protected $hidden = [
        'auth_key_encrypted',
        'auth_secret_encrypted',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'webhook_secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'historical_import_from' => 'date',
            'historical_import_checkpoint' => 'array',
            'historical_import_started_at' => 'datetime',
            'historical_import_completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'target_roas' => 'decimal:2',
            'target_cpo' => 'decimal:2',
            'target_marketing_pct' => 'decimal:2',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function dailySnapshots(): HasMany
    {
        return $this->hasMany(DailySnapshot::class);
    }

    public function hourlySnapshots(): HasMany
    {
        return $this->hasMany(HourlySnapshot::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }

    public function searchConsoleProperty(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SearchConsoleProperty::class);
    }

    public function storeUrls(): HasMany
    {
        return $this->hasMany(StoreUrl::class);
    }

    public function storeWebhooks(): HasMany
    {
        return $this->hasMany(StoreWebhook::class);
    }

    public function dailySnapshotProducts(): HasMany
    {
        return $this->hasMany(DailySnapshotProduct::class);
    }

    public function lighthouseSnapshots(): HasMany
    {
        return $this->hasMany(LighthouseSnapshot::class);
    }
}
