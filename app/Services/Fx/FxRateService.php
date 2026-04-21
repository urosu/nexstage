<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\Exceptions\FxRateNotFoundException;
use App\Models\FxRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * DB-first FX rate lookup and currency conversion.
 *
 * NEVER calls the Frankfurter API. The fx_rates table is the cache.
 * All rates are stored as EUR-based (base_currency = 'EUR').
 *
 * Callers that receive FxRateNotFoundException must:
 *   - Log a warning
 *   - Leave total_in_reporting_currency = NULL
 *   - Let RetryMissingConversionJob handle the NULL nightly
 */
class FxRateService
{
    /**
     * Return the EUR → $targetCurrency rate for the given date.
     *
     * Lookup logic (per spec):
     *   1. Exact date match → return
     *   2. Look back up to 3 days for nearest earlier rate → return
     *   3. Not found → throw FxRateNotFoundException
     */
    public function getRate(string $targetCurrency, Carbon $date): float
    {
        if ($targetCurrency === 'EUR') {
            return 1.0;
        }

        $dateStr = $date->toDateString();

        $row = FxRate::where('base_currency', 'EUR')
            ->where('target_currency', $targetCurrency)
            ->whereBetween('date', [
                $date->copy()->subDays(3)->toDateString(),
                $dateStr,
            ])
            ->orderBy('date', 'desc')
            ->select(['rate', 'date'])
            ->first();

        if ($row === null) {
            throw new FxRateNotFoundException($targetCurrency, $dateStr);
        }

        if ($row->date->toDateString() !== $dateStr) {
            Log::warning('FX rate fallback: using earlier date', [
                'target_currency' => $targetCurrency,
                'requested_date'  => $dateStr,
                'used_date'       => $row->date->toDateString(),
            ]);
        }

        return (float) $row->rate;
    }

    /**
     * Convert $amount from $orderCurrency to $reportingCurrency using rates on $date.
     *
     * Four-case conversion (all rates are EUR-based):
     *   1. orderCurrency == reportingCurrency          → return as-is
     *   2. orderCurrency == 'EUR'                      → amount × rate(EUR→reporting)
     *   3. reportingCurrency == 'EUR'                  → amount / rate(EUR→order)
     *   4. neither is EUR                              → amount × (rate(EUR→reporting) / rate(EUR→order))
     *
     * @throws FxRateNotFoundException propagated from getRate(); caller must catch and set NULL
     */
    public function convert(
        float $amount,
        string $orderCurrency,
        string $reportingCurrency,
        Carbon $date,
    ): float {
        // Case 1: same currency
        if ($orderCurrency === $reportingCurrency) {
            return $amount;
        }

        // Case 2: order is already EUR
        if ($orderCurrency === 'EUR') {
            return round($amount * $this->getRate($reportingCurrency, $date), 4);
        }

        // Case 3: reporting currency is EUR
        if ($reportingCurrency === 'EUR') {
            return round($amount / $this->getRate($orderCurrency, $date), 4);
        }

        // Case 4: cross-rate via EUR
        $rateToReporting = $this->getRate($reportingCurrency, $date);
        $rateToOrder     = $this->getRate($orderCurrency, $date);

        return round($amount * ($rateToReporting / $rateToOrder), 4);
    }
}
