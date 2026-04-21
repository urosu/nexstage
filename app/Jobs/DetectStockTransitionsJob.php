<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Alert;
use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emits stock-transition alerts by diffing two consecutive daily_snapshot_products days.
 *
 * Queue:   low
 * Timeout: 120 s
 * Tries:   2
 *
 * Dispatched by: routes/console.php daily at 01:15 UTC, once per active store,
 * after DispatchDailySnapshots (00:30) has had ~45 min to finish the per-store
 * ComputeDailySnapshotJob pass. The two reads happen here, not in the snapshot
 * job, because transition detection needs both yesterday and the day before.
 *
 * Reads from:  daily_snapshot_products (yesterday + day-before), alerts (dedup lookup)
 * Writes to:   alerts
 *
 * Algorithm: simple state diff, no anomaly engine.
 *   - instock → outofstock   → severity=warning, type=product_out_of_stock
 *   - outofstock → instock   → severity=info,    type=product_back_in_stock
 *
 * Alert deduplication: skip if the same product + type was alerted within the
 * last 7 days. Stock can flicker (backorder → instock → outofstock within a
 * week when a store drip-feeds restocks) and one alert per flicker is noise.
 *
 * Products absent from yesterday's top-50 snapshot are skipped — the top-50
 * cut is the same surface /analytics/products shows, and alerts for a product
 * nobody is looking at would be noise.
 *
 * @see PLANNING.md section 5.8 (Stock tracking) and section 12.5 (/analytics/products)
 */
class DetectStockTransitionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    /** How far back (days) to look for a prior alert of the same (product, type). */
    private const DEDUP_WINDOW_DAYS = 7;

    public function __construct(
        private readonly int $storeId,
        private readonly int $workspaceId,
        private readonly ?string $asOfDate = null,
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $today     = $this->asOfDate !== null ? Carbon::parse($this->asOfDate) : Carbon::yesterday('UTC');
        $yesterday = $today->copy()->subDay();

        // Two-day inner join on (store_id, product_external_id). Only products
        // present on both days can produce a transition — a product that drops
        // out of the top-50 is a separate phenomenon we don't alert on.
        $rows = DB::select("
            SELECT
                t.product_external_id,
                t.product_name,
                y.stock_status AS prev_status,
                t.stock_status AS curr_status,
                t.stock_quantity AS curr_qty
            FROM daily_snapshot_products t
            JOIN daily_snapshot_products y
              ON y.store_id            = t.store_id
             AND y.product_external_id = t.product_external_id
             AND y.snapshot_date       = ?
            WHERE t.store_id      = ?
              AND t.snapshot_date = ?
              AND t.stock_status IS NOT NULL
              AND y.stock_status IS NOT NULL
              AND t.stock_status <> y.stock_status
        ", [
            $yesterday->toDateString(),
            $this->storeId,
            $today->toDateString(),
        ]);

        $created = 0;
        $deduped = 0;

        foreach ($rows as $r) {
            $transition = $this->classify($r->prev_status, $r->curr_status);
            if ($transition === null) {
                continue;
            }

            [$type, $severity] = $transition;

            // Dedup: suppress if a prior alert of the same (type, product) exists
            // for this store within the dedup window. JSONB containment catches
            // the product identity without widening the `alerts` schema.
            $alreadyAlerted = Alert::withoutGlobalScopes()
                ->where('workspace_id', $this->workspaceId)
                ->where('store_id', $this->storeId)
                ->where('type', $type)
                ->where('created_at', '>=', now()->subDays(self::DEDUP_WINDOW_DAYS))
                ->whereRaw("data @> ?::jsonb", [
                    json_encode(['product_external_id' => $r->product_external_id]),
                ])
                ->exists();

            if ($alreadyAlerted) {
                $deduped++;
                continue;
            }

            Alert::create([
                'workspace_id' => $this->workspaceId,
                'store_id'     => $this->storeId,
                'type'         => $type,
                'severity'     => $severity,
                'source'       => 'system',
                'data'         => [
                    'product_external_id' => $r->product_external_id,
                    'product_name'        => $r->product_name,
                    'prev_status'         => $r->prev_status,
                    'curr_status'         => $r->curr_status,
                    'stock_quantity'      => $r->curr_qty !== null ? (int) $r->curr_qty : null,
                    'transition_date'     => $today->toDateString(),
                ],
            ]);
            $created++;
        }

        Log::info('DetectStockTransitionsJob: completed', [
            'store_id'     => $this->storeId,
            'workspace_id' => $this->workspaceId,
            'date'         => $today->toDateString(),
            'created'      => $created,
            'deduped'      => $deduped,
        ]);
    }

    /**
     * Map a (prev → curr) stock-status pair onto an alert type + severity.
     * Returns null when the transition is not one we alert on (e.g. to/from
     * `onbackorder` — that's a shop-configuration state, not a sellout event).
     *
     * @return array{0:string,1:string}|null
     */
    private function classify(string $prev, string $curr): ?array
    {
        if ($prev === 'instock' && $curr === 'outofstock') {
            return ['product_out_of_stock', 'warning'];
        }
        if ($prev === 'outofstock' && $curr === 'instock') {
            return ['product_back_in_stock', 'info'];
        }
        return null;
    }
}
