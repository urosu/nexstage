<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Holiday;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Yasumi\Yasumi;

/**
 * Populates the global holidays table for a specific country and year.
 *
 * Queue:   low
 * Timeout: 30 s
 * Tries:   3
 *
 * Triggered by:
 *   1. Yearly schedule (January 1st) — one job dispatched per distinct workspace country.
 *      See: routes/console.php "refresh-holidays"
 *   2. WorkspaceObserver.updated — when workspace.country is set for the first time
 *      and no holidays exist yet for that country + current year.
 *      See: app/Observers/WorkspaceObserver.php
 *
 * Writes to: holidays table (global, not tenant-scoped).
 * Consumed by: DetectAnomaliesJob (skip detection on holiday dates),
 *              ComputeMetricBaselinesJob (exclude holiday dates from rolling window),
 *              chart event overlays (Phase 1).
 *
 * Not all countries are supported by yasumi. Unsupported country codes are
 * logged and skipped silently — the absence of rows is the safe fallback
 * (anomaly detection will not suppress alerts for unknown countries).
 *
 * See: PLANNING.md "holidays"
 */
class RefreshHolidaysJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries   = 3;

    // Maps ISO 3166-1 alpha-2 country codes to yasumi provider class names.
    // Only countries supported by the installed yasumi version are listed.
    // If a workspace country is not here, the job skips it gracefully.
    private const COUNTRY_TO_PROVIDER = [
        'AD' => 'Andorra',
        'AR' => 'Argentina',
        'AU' => 'Australia',
        'AT' => 'Austria',
        'BE' => 'Belgium',
        'BA' => 'Bosnia',
        'BR' => 'Brazil',
        'BG' => 'Bulgaria',
        'CA' => 'Canada',
        'HR' => 'Croatia',
        'CZ' => 'CzechRepublic',
        'DK' => 'Denmark',
        'EE' => 'Estonia',
        'FI' => 'Finland',
        'FR' => 'France',
        'GE' => 'Georgia',
        'DE' => 'Germany',
        'GR' => 'Greece',
        'HU' => 'Hungary',
        'IR' => 'Iran',
        'IE' => 'Ireland',
        'IT' => 'Italy',
        'JP' => 'Japan',
        'LV' => 'Latvia',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'MX' => 'Mexico',
        'NL' => 'Netherlands',
        'NZ' => 'NewZealand',
        'NO' => 'Norway',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'RO' => 'Romania',
        'RU' => 'Russia',
        'SM' => 'SanMarino',
        'SK' => 'Slovakia',
        'SI' => 'Slovenia',
        'ZA' => 'SouthAfrica',
        'KR' => 'SouthKorea',
        'ES' => 'Spain',
        'SE' => 'Sweden',
        'CH' => 'Switzerland',
        'TR' => 'Turkey',
        'US' => 'USA',
        'UA' => 'Ukraine',
        'GB' => 'UnitedKingdom',
        'VE' => 'Venezuela',
    ];

    public function __construct(
        private readonly string $countryCode,
        private readonly int    $year,
    ) {
        $this->onQueue('low');
    }

    /**
     * Yasumi returns the camelCase short-name key (e.g. "germanUnityDay") when
     * no translation exists for the requested locale. Convert those to readable
     * title case so the UI never shows raw identifiers.
     */
    private static function resolveName(string $name): string
    {
        // If the name contains a space it's already a proper translation.
        if (str_contains($name, ' ')) {
            return $name;
        }

        // camelCase → "German Unity Day"
        $spaced = preg_replace('/([A-Z])/', ' $1', $name) ?? $name;
        return ucwords(strtolower(trim($spaced)));
    }

    public function handle(): void
    {
        $provider = self::COUNTRY_TO_PROVIDER[$this->countryCode] ?? null;

        if ($provider === null) {
            Log::info('RefreshHolidaysJob: country not supported by yasumi, skipping', [
                'country_code' => $this->countryCode,
                'year'         => $this->year,
            ]);
            return;
        }

        $holidays = Yasumi::create($provider, $this->year, 'en_US');

        $rows = [];

        foreach ($holidays as $holiday) {
            $rows[] = [
                'country_code' => $this->countryCode,
                'date'         => $holiday->format('Y-m-d'),
                'name'         => self::resolveName($holiday->getName()),
                'year'         => $this->year,
                'created_at'   => now(),
            ];
        }

        if (empty($rows)) {
            Log::warning('RefreshHolidaysJob: yasumi returned 0 holidays', [
                'country_code' => $this->countryCode,
                'year'         => $this->year,
                'provider'     => $provider,
            ]);
            return;
        }

        // Upsert on (country_code, date, name) unique constraint.
        // On conflict, we update nothing — the row is already correct.
        // This is idempotent: safe to re-run on Jan 1st every year.
        Holiday::upsert(
            $rows,
            ['country_code', 'date', 'name'],
            ['year'], // update year on conflict (should be the same, but keeps it consistent)
        );

        Log::info('RefreshHolidaysJob: holidays upserted', [
            'country_code' => $this->countryCode,
            'year'         => $this->year,
            'count'        => count($rows),
        ]);
    }
}
