<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\FxRateNotFoundException;
use App\Services\Fx\FxRateService;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly job that retries FX conversion for rows where the converted amount
 * is NULL because the FX rate was unavailable at sync time.
 *
 * Covers two tables:
 *   - orders.total_in_reporting_currency
 *   - ad_insights.spend_in_reporting_currency
 *
 * Queue:   low
 * Timeout: 300 s
 * Tries:   3
 * Backoff: default [60, 300, 900] s
 *
 * Processes all workspaces that have NULL conversions. Uses chunk(1000) to
 * prevent OOM on large datasets. Rows that still can't be converted (FX
 * rate genuinely missing for that date) are left NULL for the next nightly run.
 *
 * Scheduled daily at 07:00 UTC (routes/console.php).
 */
class RetryMissingConversionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 3;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(FxRateService $fx): void
    {
        // Collect all workspace_ids that have NULL conversions in either table.
        $orderWorkspaces = DB::table('orders')
            ->whereNull('total_in_reporting_currency')
            ->whereIn('status', ['completed', 'processing'])
            ->distinct()
            ->pluck('workspace_id')
            ->map(fn ($id) => (int) $id);

        $insightWorkspaces = DB::table('ad_insights')
            ->whereNull('spend_in_reporting_currency')
            ->distinct()
            ->pluck('workspace_id')
            ->map(fn ($id) => (int) $id);

        $workspaceIds = $orderWorkspaces->merge($insightWorkspaces)->unique()->values();

        if ($workspaceIds->isEmpty()) {
            Log::info('RetryMissingConversionJob: no NULL conversions found.');
            return;
        }

        Log::info('RetryMissingConversionJob: processing workspaces', [
            'workspace_count' => $workspaceIds->count(),
        ]);

        foreach ($workspaceIds as $workspaceId) {
            $this->processWorkspace($workspaceId, $fx);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function processWorkspace(int $workspaceId, FxRateService $fx): void
    {
        app(WorkspaceContext::class)->set($workspaceId);

        $workspace = DB::table('workspaces')
            ->where('id', $workspaceId)
            ->whereNull('deleted_at')
            ->select(['reporting_currency'])
            ->first();

        if ($workspace === null) {
            return;
        }

        $reportingCurrency = (string) $workspace->reporting_currency;

        [$orderConverted, $orderSkipped]     = $this->processOrders($workspaceId, $reportingCurrency, $fx);
        [$insightConverted, $insightSkipped] = $this->processAdInsights($workspaceId, $reportingCurrency, $fx);

        Log::info('RetryMissingConversionJob: workspace processed', [
            'workspace_id'     => $workspaceId,
            'orders_converted' => $orderConverted,
            'orders_skipped'   => $orderSkipped,
            'insights_converted' => $insightConverted,
            'insights_skipped'   => $insightSkipped,
        ]);
    }

    /**
     * Back-fill orders.total_in_reporting_currency for this workspace.
     *
     * @return array{int, int}  [converted, skipped]
     */
    private function processOrders(int $workspaceId, string $reportingCurrency, FxRateService $fx): array
    {
        $converted = 0;
        $skipped   = 0;

        DB::table('orders')
            ->where('workspace_id', $workspaceId)
            ->whereNull('total_in_reporting_currency')
            ->whereIn('status', ['completed', 'processing'])
            ->select(['id', 'currency', 'total', 'occurred_at'])
            ->orderBy('id')
            ->chunk(1000, function (Collection $chunk) use ($fx, $reportingCurrency, &$converted, &$skipped): void {
                $updates = [];

                foreach ($chunk as $order) {
                    try {
                        $amount = $fx->convert(
                            (float) $order->total,
                            (string) $order->currency,
                            $reportingCurrency,
                            Carbon::parse($order->occurred_at),
                        );

                        $updates[] = [
                            'id'                          => $order->id,
                            'total_in_reporting_currency' => round($amount, 4),
                        ];
                    } catch (FxRateNotFoundException $e) {
                        Log::warning('RetryMissingConversionJob: order FX rate still unavailable', [
                            'order_id'           => $order->id,
                            'currency'           => $order->currency,
                            'occurred_at'        => $order->occurred_at,
                            'reporting_currency' => $reportingCurrency,
                        ]);
                        $skipped++;
                    }
                }

                $this->batchUpdate('orders', 'total_in_reporting_currency', $updates);
                $converted += count($updates);
            });

        return [$converted, $skipped];
    }

    /**
     * Back-fill ad_insights.spend_in_reporting_currency for this workspace.
     *
     * Groups by distinct (currency, date) pairs so each FX lookup covers many rows at once.
     * Why: ad_insights rows for the same account/date share the same currency and rate —
     * computing convert(1.0, ...) once per pair gives the multiplier for all rows in that pair.
     *
     * Uses four-case conversion via convert(1.0, ...) which handles cross-rates (e.g. USD→GBP)
     * correctly via EUR as intermediary. The multiplier is then applied to all matching rows
     * in a single UPDATE, avoiding N individual queries.
     *
     * Related: app/Jobs/Concerns/SyncsAdInsights.php (upsertInsights — original write path)
     *
     * @return array{int, int}  [converted, skipped]
     */
    private function processAdInsights(int $workspaceId, string $reportingCurrency, FxRateService $fx): array
    {
        $converted = 0;
        $skipped   = 0;

        // Fetch distinct (currency, date) pairs so we can resolve the multiplier once per pair.
        $pairs = DB::table('ad_insights')
            ->where('workspace_id', $workspaceId)
            ->whereNull('spend_in_reporting_currency')
            ->selectRaw('DISTINCT currency, date::text AS date')
            ->orderBy('date')
            ->get();

        foreach ($pairs as $pair) {
            $currency = (string) $pair->currency;
            $date     = Carbon::parse((string) $pair->date);

            // convert(1.0, ...) gives the multiplier for any spend amount on this date.
            // This handles all four conversion cases (same/EUR-from/EUR-to/cross) correctly.
            try {
                $multiplier = $fx->convert(1.0, $currency, $reportingCurrency, $date);
            } catch (FxRateNotFoundException $e) {
                Log::warning('RetryMissingConversionJob: ad insight FX rate still unavailable', [
                    'currency'           => $currency,
                    'date'               => $date->toDateString(),
                    'reporting_currency' => $reportingCurrency,
                    'workspace_id'       => $workspaceId,
                ]);
                $skipped++;
                continue;
            }

            // Apply the multiplier to all NULL rows for this (currency, date) pair in one query.
            $affected = DB::update(
                'UPDATE ad_insights SET spend_in_reporting_currency = ROUND(spend * ?::numeric, 4) WHERE workspace_id = ? AND spend_in_reporting_currency IS NULL AND currency = ? AND date::date = ?',
                [$multiplier, $workspaceId, $currency, $date->toDateString()],
            );

            $converted += $affected;
        }

        return [$converted, $skipped];
    }

    /**
     * Batch update a single column via a parameterized CASE expression.
     * One query per chunk instead of N individual UPDATE statements.
     *
     * @param array<int, array{id: int, string: mixed}> $updates
     */
    private function batchUpdate(string $table, string $column, array $updates): void
    {
        if (empty($updates)) {
            return;
        }

        $ids      = array_column($updates, 'id');
        $bindings = [];
        $whens    = [];

        foreach ($updates as $u) {
            // Cast the value to numeric so PostgreSQL accepts it for DECIMAL columns.
            // PDO sends all bound parameters as text; without the explicit cast,
            // PostgreSQL rejects the statement with "column is of type numeric but
            // expression is of type text".
            $whens[]    = 'WHEN ? THEN ?::numeric';
            $bindings[] = $u['id'];
            $bindings[] = $u[$column];
        }

        $bindings = array_merge($bindings, $ids);
        $inMarks  = implode(',', array_fill(0, count($ids), '?'));

        DB::statement(
            "UPDATE {$table} SET {$column} = CASE id " . implode(' ', $whens) . " END WHERE id IN ({$inMarks})",
            $bindings,
        );
    }
}
