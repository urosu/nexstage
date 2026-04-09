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
 * Nightly job that retries FX conversion for orders where
 * total_in_reporting_currency IS NULL (rate was unavailable at sync time).
 *
 * Queue:   low
 * Timeout: 300 s
 * Tries:   3
 * Backoff: default [60, 300, 900] s
 *
 * Processes all workspaces that have NULL conversions. Uses chunk(1000) to
 * prevent OOM on large datasets. Orders that still can't be converted (FX
 * rate genuinely missing) are logged as warnings and left NULL for the next
 * nightly run.
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
        // Find all workspace_ids that have orders with NULL total_in_reporting_currency.
        $workspaceIds = DB::table('orders')
            ->whereNull('total_in_reporting_currency')
            ->whereIn('status', ['completed', 'processing'])
            ->distinct()
            ->pluck('workspace_id')
            ->map(fn ($id) => (int) $id);

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
        $converted         = 0;
        $skipped           = 0;

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
                            'id'                           => $order->id,
                            'total_in_reporting_currency'  => round($amount, 4),
                        ];
                    } catch (FxRateNotFoundException $e) {
                        Log::warning('RetryMissingConversionJob: FX rate still unavailable', [
                            'order_id'           => $order->id,
                            'currency'           => $order->currency,
                            'occurred_at'        => $order->occurred_at,
                            'reporting_currency' => $reportingCurrency,
                        ]);
                        $skipped++;
                    }
                }

                foreach ($updates as $update) {
                    DB::table('orders')
                        ->where('id', $update['id'])
                        ->update(['total_in_reporting_currency' => $update['total_in_reporting_currency']]);
                }

                $converted += count($updates);
            });

        Log::info('RetryMissingConversionJob: workspace processed', [
            'workspace_id' => $workspaceId,
            'converted'    => $converted,
            'skipped'      => $skipped,
        ]);
    }
}
