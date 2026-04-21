<?php

declare(strict_types=1);

namespace App\ValueObjects;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Typed wrapper for the `workspaces.workspace_settings` JSONB column.
 *
 * Keeps workspace-level UI config out of dedicated columns. Schema changes to
 * this shape do NOT require migrations — add/remove keys here only.
 *
 * Do NOT store query-filter dimensions here (e.g. primary_country_code lives on
 * `stores`, not here). Only store UI config the frontend reads.
 *
 * @see PLANNING.md section 5.6
 *
 * Reads: Workspace::$workspace_settings cast
 * Writes: WorkspaceSettingsController, OnboardingController (indirectly via Workspace::update)
 */
final class WorkspaceSettings implements CastsAttributes
{
    // ── Naming convention ─────────────────────────────────────────────────────

    public bool $namingConventionEnabled = false;

    public string $namingConventionSeparator = '|';

    /** One of: country_campaign_target | campaign_target | campaign */
    public string $namingConventionShape = 'country_campaign_target';

    // ── Dashboard preferences ─────────────────────────────────────────────────

    public bool $showSourceBadgesOnHero = false;

    /** @var string[] */
    public array $defaultChartSeries = ['revenue', 'orders', 'ad_spend'];

    /**
     * How many days before the actual holiday date to place the chart marker.
     * 0 = show on the holiday itself; 7–14 = typical ad ramp-up window.
     */
    public int $holidayLeadDays = 0;

    /**
     * How many days before a holiday to send a reminder email to the workspace owner.
     * 0 = notifications disabled.
     */
    public int $holidayNotificationDays = 0;

    /**
     * How many days before a commercial event to send a reminder email.
     * 0 = notifications disabled.
     */
    public int $commercialNotificationDays = 0;

    // ── Dismissed banners ─────────────────────────────────────────────────────

    public ?string $ios14BannerDismissedAt = null;

    public ?string $negativeNotTrackedBannerDismissedAt = null;

    // ── Cast interface ────────────────────────────────────────────────────────

    /**
     * Deserialise from the DB value (null or JSON string/array) to a WorkspaceSettings instance.
     *
     * @param  array<string, mixed>|string|null  $value
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): self
    {
        $data = [];

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $data    = is_array($decoded) ? $decoded : [];
        } elseif (is_array($value)) {
            $data = $value;
        }

        return self::fromArray($data);
    }

    /**
     * Serialise to JSON string for persistence.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof self) {
            return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
        }

        if (is_array($value)) {
            return json_encode(self::fromArray($value)->toArray(), JSON_THROW_ON_ERROR);
        }

        return json_encode((new self())->toArray(), JSON_THROW_ON_ERROR);
    }

    // ── Factory + serialisation ───────────────────────────────────────────────

    /**
     * Build from a raw decoded array, applying defaults for any missing keys.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $settings = new self();

        $nc = $data['naming_convention'] ?? [];

        $settings->namingConventionEnabled   = (bool) ($nc['enabled'] ?? false);
        $settings->namingConventionSeparator = (string) ($nc['separator'] ?? '|');
        $settings->namingConventionShape     = (string) ($nc['shape'] ?? 'country_campaign_target');

        $dp = $data['dashboard_preferences'] ?? [];

        $settings->showSourceBadgesOnHero = (bool) ($dp['show_source_badges_on_hero'] ?? false);
        $settings->defaultChartSeries     = (array) ($dp['default_chart_series'] ?? ['revenue', 'orders', 'ad_spend']);
        $settings->holidayLeadDays              = (int) ($dp['holiday_lead_days'] ?? 0);
        $settings->holidayNotificationDays     = (int) ($dp['holiday_notification_days'] ?? 0);
        $settings->commercialNotificationDays  = (int) ($dp['commercial_notification_days'] ?? 0);

        $settings->ios14BannerDismissedAt              = isset($data['ios14_banner_dismissed_at']) ? (string) $data['ios14_banner_dismissed_at'] : null;
        $settings->negativeNotTrackedBannerDismissedAt = isset($data['negative_not_tracked_banner_dismissed_at']) ? (string) $data['negative_not_tracked_banner_dismissed_at'] : null;

        return $settings;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'naming_convention' => [
                'enabled'   => $this->namingConventionEnabled,
                'separator' => $this->namingConventionSeparator,
                'shape'     => $this->namingConventionShape,
            ],
            'dashboard_preferences' => [
                'show_source_badges_on_hero' => $this->showSourceBadgesOnHero,
                'default_chart_series'       => $this->defaultChartSeries,
                'holiday_lead_days'              => $this->holidayLeadDays,
                'holiday_notification_days'      => $this->holidayNotificationDays,
                'commercial_notification_days'   => $this->commercialNotificationDays,
            ],
            'ios14_banner_dismissed_at'              => $this->ios14BannerDismissedAt,
            'negative_not_tracked_banner_dismissed_at' => $this->negativeNotTrackedBannerDismissedAt,
        ];
    }
}
