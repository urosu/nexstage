<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Holiday;
use App\Services\CommercialEventCalendar;
use Illuminate\Database\Seeder;

/**
 * Seeds the global holidays table with curated ecommerce commercial events.
 *
 * Safe to run in all environments and idempotent — upserts on the existing
 * (country_code, date, name) unique constraint. Covers current year + next year
 * so shops always see upcoming events. Annual re-seeding is handled by
 * SeedCommercialEventsJob scheduled on January 1st.
 *
 * @see App\Jobs\SeedCommercialEventsJob
 * @see App\Services\CommercialEventCalendar
 */
class CommercialEventsSeeder extends Seeder
{
    public function __construct(private readonly CommercialEventCalendar $calendar) {}

    public function run(): void
    {
        $currentYear = (int) now()->format('Y');

        foreach ([$currentYear, $currentYear + 1] as $year) {
            $rows = $this->calendar->resolve($year);

            if (empty($rows)) {
                continue;
            }

            Holiday::upsert(
                $rows,
                ['country_code', 'date', 'name'],
                ['year', 'type', 'category'],
            );

            $this->command?->info("Seeded " . count($rows) . " commercial events for {$year}.");
        }
    }
}
