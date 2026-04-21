<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\Attribution\AttributionParserService;
use App\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-processes every existing order for a workspace through the attribution parser pipeline.
 *
 * Queue:   low
 * Timeout: 3600 s (1 hour)
 * Tries:   3
 *
 * Design decisions:
 *  - Idempotent: overwrites attribution_* columns on every run. Safe to re-run.
 *  - Does not touch utm_* columns — RevenueAttributionService reads those until
 *    Step 14 cutover explicitly switches reads.
 *  - Full historical backfill: no 90-day window. All orders for the workspace.
 *  - Chunked in batches of 200 to avoid memory exhaustion on large stores.
 *  - WorkspaceScope is not active in jobs; workspace_id is filtered explicitly.
 *
 * Progress is stored in Cache under key `attribution_backfill_{workspace_id}`:
 *   { status: running|completed|failed, processed: N, total: N, started_at: ISO, completed_at: ISO|null }
 * /admin/system-health (Step 15) reads these keys per workspace.
 *
 * Dispatched manually from AdminController::dispatchAttributionBackfill.
 * Dispatched automatically by TriggerReactivationBackfillJob for recovered workspaces.
 *
 * @see PLANNING.md section 6
 */
class BackfillAttributionDataJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout   = 3600;
    public int $tries     = 3;
    public int $uniqueFor = 3660;

    /**
     * Orders processed per DB chunk. 200 balances memory use vs round-trip overhead.
     * Each order loads raw_meta (potentially several KB of JSONB) so keep below 500.
     */
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly int $workspaceId,
    ) {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return (string) $this->workspaceId;
    }

    public function handle(AttributionParserService $parser): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $cacheKey  = self::cacheKey($this->workspaceId);
        $startedAt = now()->toISOString();

        $total = Order::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->count();

        Cache::put($cacheKey, [
            'status'       => 'running',
            'processed'    => 0,
            'total'        => $total,
            'started_at'   => $startedAt,
            'completed_at' => null,
        ], now()->addDays(7));

        Log::info('BackfillAttributionDataJob: started', [
            'workspace_id' => $this->workspaceId,
            'total_orders' => $total,
        ]);

        $processed = 0;

        try {
            Order::withoutGlobalScopes()
                ->where('workspace_id', $this->workspaceId)
                ->select([
                    'id', 'workspace_id',
                    'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
                    'source_type', 'raw_meta',
                ])
                ->orderBy('id')
                ->chunk(self::CHUNK_SIZE, function ($orders) use ($parser, $total, $cacheKey, $startedAt, &$processed): void {
                    $now  = now()->toDateTimeString();
                    $rows = [];

                    foreach ($orders as $order) {
                        $parsed = $parser->parse($order);

                        $firstTouch = $parsed->toTouchArray($parsed->first_touch);
                        $lastTouch  = $parsed->toTouchArray($parsed->last_touch);

                        $rows[] = [
                            'id'                      => $order->id,
                            'attribution_source'      => $parsed->source_type,
                            'attribution_first_touch' => $firstTouch !== null ? json_encode($firstTouch) : null,
                            'attribution_last_touch'  => $lastTouch  !== null ? json_encode($lastTouch)  : null,
                            'attribution_click_ids'   => $parsed->click_ids !== null ? json_encode($parsed->click_ids) : null,
                            'attribution_parsed_at'   => $now,
                            'updated_at'              => $now,
                        ];

                        $processed++;
                    }

                    // Single UPDATE … FROM (VALUES …) for the whole chunk — one round-trip
                    // instead of CHUNK_SIZE individual statements.
                    $placeholders = implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?, ?)'));
                    $bindings = [];
                    foreach ($rows as $row) {
                        $bindings[] = $row['id'];
                        $bindings[] = $row['attribution_source'];
                        $bindings[] = $row['attribution_first_touch'];
                        $bindings[] = $row['attribution_last_touch'];
                        $bindings[] = $row['attribution_click_ids'];
                        $bindings[] = $row['attribution_parsed_at'];
                        $bindings[] = $row['updated_at'];
                    }
                    DB::statement("
                        UPDATE orders AS o
                        SET attribution_source      = v.attribution_source,
                            attribution_first_touch = v.attribution_first_touch::jsonb,
                            attribution_last_touch  = v.attribution_last_touch::jsonb,
                            attribution_click_ids   = v.attribution_click_ids::jsonb,
                            attribution_parsed_at   = v.attribution_parsed_at::timestamp,
                            updated_at              = v.updated_at::timestamp
                        FROM (VALUES {$placeholders}) AS v(
                            id, attribution_source, attribution_first_touch,
                            attribution_last_touch, attribution_click_ids,
                            attribution_parsed_at, updated_at
                        )
                        WHERE o.id = v.id::bigint
                    ", $bindings);

                    Cache::put($cacheKey, [
                        'status'       => 'running',
                        'processed'    => $processed,
                        'total'        => $total,
                        'started_at'   => $startedAt,
                        'completed_at' => null,
                    ], now()->addDays(7));
                });

            Cache::put($cacheKey, [
                'status'       => 'completed',
                'processed'    => $processed,
                'total'        => $total,
                'started_at'   => $startedAt,
                'completed_at' => now()->toISOString(),
            ], now()->addDays(7));

            Log::info('BackfillAttributionDataJob: completed', [
                'workspace_id' => $this->workspaceId,
                'processed'    => $processed,
            ]);
        } catch (\Throwable $e) {
            Cache::put($cacheKey, [
                'status'       => 'failed',
                'processed'    => $processed,
                'total'        => $total,
                'started_at'   => $startedAt,
                'completed_at' => now()->toISOString(),
                'error'        => mb_substr($e->getMessage(), 0, 255),
            ], now()->addDays(7));

            Log::error('BackfillAttributionDataJob: failed', [
                'workspace_id' => $this->workspaceId,
                'processed'    => $processed,
                'error'        => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Cache key for a workspace's backfill progress.
     * Read by /admin/system-health (Step 15) to surface per-workspace backfill state.
     */
    public static function cacheKey(int $workspaceId): string
    {
        return "attribution_backfill_{$workspaceId}";
    }
}
