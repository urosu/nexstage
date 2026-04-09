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
 * Recomputes total_in_reporting_currency for every order in a workspace
 * after the workspace's reporting_currency has changed.
 *
 * Queue:   low
 * Timeout: 7200 s (2 hours)
 * Tries:   3
 * Backoff: default [60, 300, 900] s
 *
 * After all orders are recomputed, dispatches one ComputeDailySnapshotJob per
 * distinct date that already has a daily_snapshots row, so snapshot revenue
 * figures are consistent with the new currency.
 *
 * Uses chunk(1000) on all queries to prevent OOM.
 *
 * Dispatched by: settings/workspace update when reporting_currency changes.
 */
class RecomputeReportingCurrencyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries   = 3;

    public function __construct(
        private readonly int $workspaceId,
    ) {
        $this->onQueue('low');
    }

    public function handle(FxRateService $fx): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $workspace = DB::table('workspaces')
            ->where('id', $this->workspaceId)
            ->whereNull('deleted_at')
            ->select(['reporting_currency'])
            ->first();

        if ($workspace === null) {
            Log::warning('RecomputeReportingCurrencyJob: workspace not found or deleted', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        $reportingCurrency = (string) $workspace->reporting_currency;
        $converted         = 0;
        $nulled            = 0;

        DB::table('orders')
            ->where('workspace_id', $this->workspaceId)
            ->select(['id', 'currency', 'total', 'occurred_at'])
            ->orderBy('id')
            ->chunk(1000, function (Collection $chunk) use ($fx, $reportingCurrency, &$converted, &$nulled): void {
                foreach ($chunk as $order) {
                    try {
                        $amount = $fx->convert(
                            (float) $order->total,
                            (string) $order->currency,
                            $reportingCurrency,
                            Carbon::parse($order->occurred_at),
                        );

                        DB::table('orders')
                            ->where('id', $order->id)
                            ->update(['total_in_reporting_currency' => round($amount, 4)]);

                        $converted++;
                    } catch (FxRateNotFoundException $e) {
                        DB::table('orders')
                            ->where('id', $order->id)
                            ->update(['total_in_reporting_currency' => null]);

                        Log::warning('RecomputeReportingCurrencyJob: FX rate unavailable, setting NULL', [
                            'order_id'           => $order->id,
                            'currency'           => $order->currency,
                            'occurred_at'        => $order->occurred_at,
                            'reporting_currency' => $reportingCurrency,
                        ]);

                        $nulled++;
                    }
                }
            });

        Log::info('RecomputeReportingCurrencyJob: orders recomputed', [
            'workspace_id' => $this->workspaceId,
            'converted'    => $converted,
            'nulled'       => $nulled,
        ]);

        $this->redispatchSnapshotJobs();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Dispatch one ComputeDailySnapshotJob per store+date that already has a
     * daily_snapshots row so revenue figures are updated to the new currency.
     */
    private function redispatchSnapshotJobs(): void
    {
        $dispatched = 0;

        DB::table('daily_snapshots')
            ->where('workspace_id', $this->workspaceId)
            ->select(['store_id', 'date'])
            ->orderBy('store_id')
            ->orderBy('date')
            ->chunk(1000, function (Collection $chunk) use (&$dispatched): void {
                foreach ($chunk as $row) {
                    ComputeDailySnapshotJob::dispatch(
                        (int) $row->store_id,
                        Carbon::parse($row->date),
                    );
                    $dispatched++;
                }
            });

        Log::info('RecomputeReportingCurrencyJob: snapshot jobs dispatched', [
            'workspace_id' => $this->workspaceId,
            'dispatched'   => $dispatched,
        ]);
    }
}
