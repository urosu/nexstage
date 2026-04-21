<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Computes Frequently-Bought-Together (FBT) affinities for one workspace.
 *
 * Triggered by: weekly Sunday schedule (per workspace, low queue).
 *
 * Reads from: orders + order_items (last 90 days, completed/processing only),
 *             products (resolves product_external_id → products.id).
 * Writes to: product_affinities (deletes the workspace's rows then inserts the
 *            current batch — the table is a snapshot, not an append log).
 *
 * Algorithm: Apriori pair frequency at k=2.
 *   - support     = pair_orders / total_orders     (probability of co-occurrence)
 *   - confidence  = pair_orders / a_orders         (P(B | A), asymmetric)
 *   - lift        = confidence / (b_orders / N)    (>1 = positive association)
 *   - margin_lift = confidence × avg unit margin of B over the window
 *                   (NULL when COGS not configured for B)
 *
 * Both directions (A→B and B→A) are inserted because confidence is asymmetric
 * and the recommendation surface ("frequently bought with X") needs the row
 * keyed on the product the user is currently looking at.
 *
 * Minimum support: a pair must appear in at least 3 distinct orders to be
 * considered. Below this, the lift estimate is too noisy to act on. The
 * threshold is intentionally hardcoded — tuning belongs in Phase 3 once we
 * have real-store distributions to observe.
 *
 * @see PLANNING.md section 19 (Frequently-Bought-Together)
 * @see PLANNING.md section 5 (product_affinities schema)
 */
class ComputeProductAffinitiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    /** Minimum number of distinct orders a pair must appear in. */
    private const MIN_PAIR_ORDERS = 3;

    /** How far back to look. */
    private const WINDOW_DAYS = 90;

    public function __construct(private readonly int $workspaceId) {}

    public function handle(): void
    {
        $workspace = Workspace::withoutGlobalScopes()->find($this->workspaceId);
        if (! $workspace || $workspace->deleted_at !== null) {
            return;
        }

        $from = now()->subDays(self::WINDOW_DAYS)->toDateString() . ' 00:00:00';
        $now  = now();

        // Single SQL: build the order-product basket, count pair co-occurrences,
        // join singletons for confidence/lift, average B's contribution margin
        // for margin_lift. Postgres handles this in one pass across a 90d window.
        //
        // The product join uses (workspace_id, store_id, external_id) so an
        // external_id reused across stores in the same workspace cannot
        // cross-pollinate baskets.
        $rows = DB::select(
            <<<'SQL'
                WITH basket AS (
                    SELECT
                        oi.order_id,
                        p.id        AS product_id,
                        p.store_id,
                        oi.unit_price,
                        oi.unit_cost,
                        oi.quantity
                    FROM order_items oi
                    JOIN orders o
                      ON o.id = oi.order_id
                    JOIN products p
                      ON p.workspace_id = o.workspace_id
                     AND p.store_id     = o.store_id
                     AND p.external_id  = oi.product_external_id
                    WHERE o.workspace_id = :workspace
                      AND o.status IN ('completed', 'processing')
                      AND o.occurred_at >= :since
                ),
                store_totals AS (
                    SELECT store_id, COUNT(DISTINCT order_id) AS n
                    FROM basket
                    GROUP BY store_id
                ),
                product_orders AS (
                    SELECT
                        store_id,
                        product_id,
                        COUNT(DISTINCT order_id) AS cnt,
                        AVG(NULLIF(unit_price, 0) - unit_cost) FILTER (WHERE unit_cost IS NOT NULL AND unit_cost > 0) AS avg_unit_margin
                    FROM basket
                    GROUP BY store_id, product_id
                ),
                pair_counts AS (
                    SELECT
                        a.store_id,
                        a.product_id AS product_a_id,
                        b.product_id AS product_b_id,
                        COUNT(DISTINCT a.order_id) AS pair_orders
                    FROM basket a
                    JOIN basket b
                      ON b.order_id = a.order_id
                     AND b.store_id = a.store_id
                     AND b.product_id <> a.product_id
                    GROUP BY a.store_id, a.product_id, b.product_id
                    HAVING COUNT(DISTINCT a.order_id) >= :min_pair
                )
                SELECT
                    pc.store_id,
                    pc.product_a_id,
                    pc.product_b_id,
                    pc.pair_orders,
                    st.n                 AS total_orders,
                    poa.cnt              AS a_orders,
                    pob.cnt              AS b_orders,
                    pob.avg_unit_margin  AS b_avg_margin
                FROM pair_counts pc
                JOIN store_totals  st  ON st.store_id  = pc.store_id
                JOIN product_orders poa ON poa.store_id = pc.store_id AND poa.product_id = pc.product_a_id
                JOIN product_orders pob ON pob.store_id = pc.store_id AND pob.product_id = pc.product_b_id
            SQL,
            [
                'workspace' => $this->workspaceId,
                'since'     => $from,
                'min_pair'  => self::MIN_PAIR_ORDERS,
            ],
        );

        $insertRows = [];
        foreach ($rows as $r) {
            $totalOrders = (int) $r->total_orders;
            $aOrders     = (int) $r->a_orders;
            $bOrders     = (int) $r->b_orders;
            $pairOrders  = (int) $r->pair_orders;

            // Defensive — should be impossible given the joins above.
            if ($totalOrders === 0 || $aOrders === 0 || $bOrders === 0) {
                continue;
            }

            $support    = $pairOrders / $totalOrders;
            $confidence = $pairOrders / $aOrders;
            $bSupport   = $bOrders / $totalOrders;
            $lift       = $bSupport > 0 ? $confidence / $bSupport : 0;

            // margin_lift: expected € margin contributed when B is bundled with A.
            // NULL when B has no COGS data — the differentiating metric requires
            // real cost data, an estimate from price alone would mislead.
            $marginLift = null;
            if ($r->b_avg_margin !== null) {
                $marginLift = round($confidence * (float) $r->b_avg_margin, 4);
            }

            $insertRows[] = [
                'workspace_id'  => $this->workspaceId,
                'store_id'      => (int) $r->store_id,
                'product_a_id'  => (int) $r->product_a_id,
                'product_b_id'  => (int) $r->product_b_id,
                'support'       => round($support, 6),
                'confidence'    => round($confidence, 6),
                'lift'          => round($lift, 4),
                'margin_lift'   => $marginLift,
                'calculated_at' => $now,
            ];
        }

        // Replace the workspace's snapshot atomically. The table is a derived
        // cache, not an append log — stale rows would surface decommissioned
        // products on the recommendation surface.
        DB::transaction(function () use ($insertRows): void {
            DB::table('product_affinities')
                ->where('workspace_id', $this->workspaceId)
                ->delete();

            // Chunk inserts to keep the prepared statement under Postgres' parameter limit.
            foreach (array_chunk($insertRows, 500) as $chunk) {
                DB::table('product_affinities')->insert($chunk);
            }
        });
    }
}
