<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RefreshHolidaysJob;
use App\Jobs\SeedCommercialEventsJob;
use App\Models\Holiday;
use App\Models\Workspace;
use App\Services\CommercialEventCalendar;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the holiday calendar page.
 *
 * Supports browsing any of the 49 yasumi-supported countries via ?country=XX,
 * defaulting to the workspace's own country. Year navigation via ?year=YYYY.
 *
 * Reads: holidays (global table, filtered by country + year)
 * Writes: nothing
 *
 * Called by: GET /{workspace}/holidays (web.php)
 */
class HolidaysController extends Controller
{
    /** ISO 3166-1 alpha-2 → display name for all yasumi-supported countries. */
    private const SUPPORTED_COUNTRIES = [
        'AD' => 'Andorra',
        'AR' => 'Argentina',
        'AT' => 'Austria',
        'AU' => 'Australia',
        'BA' => 'Bosnia & Herzegovina',
        'BE' => 'Belgium',
        'BG' => 'Bulgaria',
        'BR' => 'Brazil',
        'CA' => 'Canada',
        'CH' => 'Switzerland',
        'CZ' => 'Czech Republic',
        'DE' => 'Germany',
        'DK' => 'Denmark',
        'EE' => 'Estonia',
        'ES' => 'Spain',
        'FI' => 'Finland',
        'FR' => 'France',
        'GB' => 'United Kingdom',
        'GE' => 'Georgia',
        'GR' => 'Greece',
        'HR' => 'Croatia',
        'HU' => 'Hungary',
        'IE' => 'Ireland',
        'IR' => 'Iran',
        'IT' => 'Italy',
        'JP' => 'Japan',
        'KR' => 'South Korea',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'LV' => 'Latvia',
        'MX' => 'Mexico',
        'NL' => 'Netherlands',
        'NO' => 'Norway',
        'NZ' => 'New Zealand',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'RO' => 'Romania',
        'RU' => 'Russia',
        'SE' => 'Sweden',
        'SI' => 'Slovenia',
        'SK' => 'Slovakia',
        'SM' => 'San Marino',
        'TR' => 'Turkey',
        'UA' => 'Ukraine',
        'US' => 'United States',
        'VE' => 'Venezuela',
        'ZA' => 'South Africa',
        // Countries covered by CommercialEventCalendar but not by yasumi (commercial events only).
        'CN' => 'China',
        'IN' => 'India',
        'SG' => 'Singapore',
    ];

    public function index(Request $request): Response
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $currentYear = (int) now()->format('Y');
        $year        = (int) $request->input('year', $currentYear);
        $year        = max(2020, min($currentYear + 2, $year));

        // Default to workspace country; fall back to first supported country.
        $defaultCountry  = $workspace->country ?? array_key_first(self::SUPPORTED_COUNTRIES);
        $selectedCountry = strtoupper((string) $request->input('country', $defaultCountry));

        if (! array_key_exists($selectedCountry, self::SUPPORTED_COUNTRIES)) {
            $selectedCountry = $defaultCountry;
        }

        // Seed public holidays on the fly if missing for this country+year.
        $existsPublic = Holiday::where('country_code', $selectedCountry)->where('year', $year)->where('type', 'public')->exists();
        if (! $existsPublic) {
            RefreshHolidaysJob::dispatchSync($selectedCountry, $year);
        }

        // Seed commercial events if they haven't been seeded for this year yet.
        // Commercial events are global (all countries at once), so one check suffices.
        $existsCommercial = Holiday::where('year', $year)->where('type', 'commercial')->exists();
        if (! $existsCommercial) {
            SeedCommercialEventsJob::dispatchSync($year);
        }

        $holidays = Holiday::where('country_code', $selectedCountry)
            ->where('year', $year)
            ->orderBy('date')
            ->get()
            ->map(fn (Holiday $h) => [
                'id'        => $h->id,
                'name'      => $h->name,
                'date'      => $h->date->toDateString(),
                'day_label' => $h->date->format('l'),
                'days_away' => (int) now()->startOfDay()->diffInDays($h->date->startOfDay(), false),
                'type'      => $h->type,
                'category'  => $h->category,
            ])
            ->values()
            ->all();

        // Build country list sorted alphabetically by name for the dropdown.
        $countries = collect(self::SUPPORTED_COUNTRIES)
            ->map(fn (string $name, string $code) => ['code' => $code, 'name' => $name])
            ->sortBy('name')
            ->values()
            ->all();

        return Inertia::render('Holidays/Index', [
            'holidays'         => $holidays,
            'year'             => $year,
            'current_year'     => $currentYear,
            'selected_country' => $selectedCountry,
            'workspace_country' => $workspace->country,
            'countries'        => $countries,
        ]);
    }
}
