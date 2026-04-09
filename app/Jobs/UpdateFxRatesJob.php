<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FxRate;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches missing EUR-based FX rates from Frankfurter and upserts them into fx_rates.
 *
 * Scheduled daily at 06:00 UTC (routes/console.php).
 * Queue: low. Timeout: 60s. Tries: 3. Backoff: [30, 120, 300].
 *
 * Can also be dispatched with an explicit date range for targeted prefetching
 * (e.g. from WooCommerceHistoricalImportJob before processing a date range).
 *
 * IMPORTANT: This is the ONLY place that calls the Frankfurter API.
 *            FxRateService reads the fx_rates table exclusively.
 */
class UpdateFxRatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param Carbon|null $from  Start of date range (inclusive). Defaults to 7 days ago.
     * @param Carbon|null $to    End of date range (inclusive). Defaults to yesterday.
     */
    public function __construct(
        private readonly ?Carbon $from = null,
        private readonly ?Carbon $to = null,
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        $from = ($this->from ?? Carbon::yesterday()->subDays(6))->startOfDay();
        $to   = ($this->to   ?? Carbon::yesterday())->startOfDay();

        if ($from->gt($to)) {
            Log::warning('UpdateFxRatesJob: $from is after $to, nothing to fetch.', [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
            ]);
            return;
        }

        $missingDates = $this->findMissingDates($from, $to);

        if ($missingDates->isEmpty()) {
            Log::info('UpdateFxRatesJob: all dates already cached.', [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
            ]);
            return;
        }

        $this->fetchAndUpsert($missingDates->first(), $missingDates->last());
    }

    /**
     * Returns the sorted list of dates in [$from, $to] that have no row in fx_rates.
     *
     * @return Collection<int, string>  Date strings YYYY-MM-DD, ascending.
     */
    private function findMissingDates(Carbon $from, Carbon $to): Collection
    {
        $allDates = collect();
        $cursor   = $from->copy();

        while ($cursor->lte($to)) {
            $allDates->push($cursor->toDateString());
            $cursor->addDay();
        }

        // Dates that already have at least one EUR-based row
        $cachedDates = FxRate::where('base_currency', 'EUR')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->distinct()
            ->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->all();

        return $allDates->diff($cachedDates)->values()->sort()->values();
    }

    /**
     * Calls Frankfurter for the given date range and upserts all returned rates.
     *
     * Frankfurter v2 returns a flat array of objects:
     *   [{"date":"2024-01-15","base":"EUR","quote":"USD","rate":1.09}, ...]
     */
    private function fetchAndUpsert(string $firstMissing, string $lastMissing): void
    {
        $baseUrl = rtrim((string) config('services.frankfurter.url'), '/');

        if (empty($baseUrl)) {
            throw new \RuntimeException(
                'FRANKFURTER_API_URL is not configured. Ensure it is set in .env and the config cache is cleared.'
            );
        }

        $url      = $baseUrl . '/rates';
        $response = Http::timeout(30)->get($url, [
            'from' => $firstMissing,
            'to'   => $lastMissing,
            'base' => 'EUR',
        ]);

        if ($response->failed()) {
            Log::error('UpdateFxRatesJob: Frankfurter API request failed.', [
                'status' => $response->status(),
                'url'    => $url,
                'from'   => $firstMissing,
                'to'     => $lastMissing,
            ]);
            $this->fail(new \RuntimeException(
                "Frankfurter API returned HTTP {$response->status()} for {$firstMissing}..{$lastMissing}"
            ));
            return;
        }

        $entries = $response->json();

        if (empty($entries)) {
            Log::warning('UpdateFxRatesJob: Frankfurter returned no rate data.', [
                'from' => $firstMissing,
                'to'   => $lastMissing,
            ]);
            return;
        }

        $rows = [];
        $now  = now()->toDateTimeString();

        foreach ($entries as $entry) {
            $rows[] = [
                'base_currency'   => $entry['base'],
                'target_currency' => $entry['quote'],
                'rate'            => $entry['rate'],
                'date'            => $entry['date'],
                'created_at'      => $now,
            ];
        }

        // Upsert in chunks to stay within parameter limits.
        foreach (array_chunk($rows, 500) as $chunk) {
            FxRate::upsert(
                $chunk,
                uniqueBy: ['base_currency', 'target_currency', 'date'],
                update: ['rate'],
            );
        }

        Log::info('UpdateFxRatesJob: upserted FX rates.', [
            'rows' => count($rows),
            'from' => $firstMissing,
            'to'   => $lastMissing,
        ]);
    }
}
