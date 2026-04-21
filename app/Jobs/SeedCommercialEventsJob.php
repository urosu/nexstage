<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Holiday;
use App\Services\CommercialEventCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Seeds curated ecommerce commercial events for a given year.
 *
 * Queue:   low
 * Timeout: 30 s
 * Tries:   3
 *
 * Triggered by:
 *   1. Yearly schedule (January 1st at 00:20 UTC) to populate events for the new year.
 *      See: routes/console.php "seed-commercial-events"
 *
 * Uses CommercialEventCalendar as the data source — all event rules live there.
 * Writes type='commercial' rows to the global holidays table.
 *
 * @see App\Services\CommercialEventCalendar
 * @see PLANNING.md section 22
 */
class SeedCommercialEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries   = 3;

    public function __construct(private readonly int $year)
    {
        $this->onQueue('low');
    }

    public function handle(CommercialEventCalendar $calendar): void
    {
        $rows = $calendar->resolve($this->year);

        if (empty($rows)) {
            Log::warning('SeedCommercialEventsJob: no rows resolved', ['year' => $this->year]);
            return;
        }

        Holiday::upsert(
            $rows,
            ['country_code', 'date', 'name'],
            ['year', 'type', 'category'],
        );

        Log::info('SeedCommercialEventsJob: commercial events seeded', [
            'year'  => $this->year,
            'count' => count($rows),
        ]);
    }
}
