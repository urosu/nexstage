<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computes (or recomputes) all hourly_snapshots rows for a single store + date.
 *
 * Queue:   low
 * Timeout: 300 s
 * Tries:   3
 * Backoff: default [60, 300, 900] s
 *
 * Idempotent: uses INSERT … ON CONFLICT (store_id, date, hour) DO UPDATE so it
 * is safe to dispatch multiple times for the same store + date.
 *
 * Dispatched by:
 *  - DispatchHourlySnapshots (nightly 00:45 UTC) — one job per active store for yesterday
 *
 * Constructor params: (int $storeId, Carbon $date)
 */
class ComputeHourlySnapshotsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout   = 300;
    public int $tries     = 3;
    public int $uniqueFor = 360;

    public function __construct(
        private readonly int    $storeId,
        private readonly Carbon $date,
    ) {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return "{$this->storeId}:{$this->date->toDateString()}";
    }

    public function handle(): void
    {
        $store = DB::table('stores')
            ->where('id', $this->storeId)
            ->select(['id', 'workspace_id'])
            ->first();

        if ($store === null) {
            Log::warning('ComputeHourlySnapshotsJob: store not found', [
                'store_id' => $this->storeId,
            ]);
            return;
        }

        $workspaceId = (int) $store->workspace_id;
        $dateStr     = $this->date->toDateString();

        app(WorkspaceContext::class)->set($workspaceId);

        $rows = DB::table('orders')
            ->selectRaw("
                EXTRACT(HOUR FROM occurred_at)::smallint AS hour,
                COUNT(*)::int                            AS orders_count,
                SUM(total_in_reporting_currency)         AS revenue
            ")
            ->where('workspace_id', $workspaceId)
            ->where('store_id', $this->storeId)
            ->whereBetween('occurred_at', [$dateStr . ' 00:00:00', $dateStr . ' 23:59:59'])
            ->whereIn('status', ['completed', 'processing'])
            ->groupByRaw('EXTRACT(HOUR FROM occurred_at)')
            ->get();

        if ($rows->isEmpty()) {
            Log::info('ComputeHourlySnapshotsJob: no orders for date', [
                'store_id' => $this->storeId,
                'date'     => $dateStr,
            ]);
            return;
        }

        $now    = now()->toDateTimeString();
        $upsert = $rows->map(fn ($row) => [
            'workspace_id' => $workspaceId,
            'store_id'     => $this->storeId,
            'date'         => $dateStr,
            'hour'         => (int) $row->hour,
            'orders_count' => (int) $row->orders_count,
            'revenue'      => $row->revenue !== null ? (float) $row->revenue : null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ])->all();

        DB::table('hourly_snapshots')->upsert(
            $upsert,
            ['store_id', 'date', 'hour'],
            ['orders_count', 'revenue', 'updated_at'],
        );

        Log::info('ComputeHourlySnapshotsJob: snapshots computed', [
            'store_id'  => $this->storeId,
            'date'      => $dateStr,
            'row_count' => count($upsert),
        ]);
    }
}
