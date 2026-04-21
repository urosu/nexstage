<?php

declare(strict_types=1);

namespace App\Models;

use App\ValueObjects\WorkspaceSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Cashier\Billable;

class Workspace extends Model
{
    use Billable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'owner_id',
        'reporting_currency',
        'reporting_timezone',
        'trial_ends_at',
        'billing_plan',
        'billing_workspace_id',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'billing_name',
        'billing_email',
        'billing_address',
        'vat_number',
        'is_orphaned',
        'has_store',
        'has_ads',
        'has_gsc',
        'has_psi',
        'country',
        'region',
        'timezone',
        'target_roas',
        'target_cpo',
        'target_marketing_pct',
        'utm_coverage_pct',
        'utm_coverage_status',
        'utm_coverage_checked_at',
        'utm_unrecognized_sources',
        'workspace_settings',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'billing_address' => 'array',
            'is_orphaned' => 'boolean',
            'deleted_at' => 'datetime',
            'has_store' => 'boolean',
            'has_ads' => 'boolean',
            'has_gsc' => 'boolean',
            'has_psi' => 'boolean',
            'target_roas' => 'decimal:2',
            'target_cpo' => 'decimal:2',
            'target_marketing_pct' => 'decimal:2',
            'utm_coverage_pct' => 'decimal:2',
            'utm_coverage_checked_at' => 'datetime',
            'utm_unrecognized_sources' => 'array',
            'workspace_settings'       => WorkspaceSettings::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function billingOwner(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'billing_workspace_id');
    }

    public function billingChildren(): HasMany
    {
        return $this->hasMany(Workspace::class, 'billing_workspace_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function workspaceUsers(): HasMany
    {
        return $this->hasMany(WorkspaceUser::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function adAccounts(): HasMany
    {
        return $this->hasMany(AdAccount::class);
    }

    public function searchConsoleProperties(): HasMany
    {
        return $this->hasMany(SearchConsoleProperty::class);
    }

    public function dailySnapshots(): HasMany
    {
        return $this->hasMany(DailySnapshot::class);
    }

    public function hourlySnapshots(): HasMany
    {
        return $this->hasMany(HourlySnapshot::class);
    }

    public function aiSummaries(): HasMany
    {
        return $this->hasMany(AiSummary::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function storeUrls(): HasMany
    {
        return $this->hasMany(StoreUrl::class);
    }

    public function metricBaselines(): HasMany
    {
        return $this->hasMany(MetricBaseline::class);
    }

    public function workspaceEvents(): HasMany
    {
        return $this->hasMany(WorkspaceEvent::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function couponExclusions(): HasMany
    {
        return $this->hasMany(CouponExclusion::class);
    }

    public function dailySnapshotProducts(): HasMany
    {
        return $this->hasMany(DailySnapshotProduct::class);
    }
}
