<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the cross-entity Winners & Losers overview page.
 *
 * Shows top/bottom performers for campaigns (by real ROAS), products
 * (by revenue), and acquisition channels (by revenue) — all in one view.
 * Designed as a morning-briefing snapshot, not a drill-down page.
 *
 * @see PLANNING.md section 15 (Winners/Losers classifier)
 */
class WinnersLosersController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $params      = $this->validateParams($request);
        $from        = $params['from'];
        $to          = $params['to'];

        return Inertia::render('Analytics/WinnersLosers', [
            'campaigns' => $this->topCampaigns($workspaceId, $from, $to),
            'products'  => $this->topProducts($workspaceId, $from, $to),
            ...$params,
        ]);
    }

    /** @return array{from:string,to:string} */
    private function validateParams(Request $request): array
    {
        $v = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'   => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            'from' => $v['from'] ?? now()->subDays(29)->toDateString(),
            'to'   => $v['to']   ?? now()->toDateString(),
        ];
    }

    /**
     * Top/bottom 5 campaigns by real ROAS (peer classifier — no target required).
     *
     * Only campaigns with both spend and attributed revenue are ranked.
     * Attribution is matched by campaign external_id first, then campaign name.
     *
     * @return array{winners:list<array<string,mixed>>, losers:list<array<string,mixed>>}
     */
    private function topCampaigns(int $workspaceId, string $from, string $to): array
    {
        // Step 1: aggregate spend per campaign
        $spendRows = DB::select("
            SELECT
                c.id,
                c.name,
                c.external_id,
                aa.platform,
                SUM(ai.spend_in_reporting_currency) AS spend
            FROM ad_insights ai
            JOIN campaigns    c  ON c.id  = ai.campaign_id
            JOIN ad_accounts  aa ON aa.id = ai.ad_account_id
            WHERE ai.workspace_id = ?
              AND ai.level        = 'campaign'
              AND ai.hour         IS NULL
              AND ai.date         BETWEEN ? AND ?
            GROUP BY c.id, c.name, c.external_id, aa.platform
            HAVING SUM(ai.spend_in_reporting_currency) > 0
        ", [$workspaceId, $from, $to]);

        if (empty($spendRows)) {
            return ['winners' => [], 'losers' => []];
        }

        // Step 2: attributed revenue grouped by utm_campaign value
        $attrRows = DB::select("
            SELECT
                attribution_last_touch->>'campaign' AS utm_campaign,
                SUM(total_in_reporting_currency)    AS revenue
            FROM orders
            WHERE workspace_id = ?
              AND occurred_at::date BETWEEN ? AND ?
              AND status NOT IN ('cancelled','refunded','failed','trash','pending-cancel')
              AND attribution_last_touch->>'channel_type' IN ('paid_social','paid_search')
              AND attribution_last_touch->>'campaign' IS NOT NULL
            GROUP BY attribution_last_touch->>'campaign'
        ", [$workspaceId, $from, $to]);

        // Build lowercase lookup: utm_campaign value → revenue
        $revenueByUtm = [];
        foreach ($attrRows as $row) {
            $revenueByUtm[strtolower((string) $row->utm_campaign)] = (float) $row->revenue;
        }

        // Step 3: match and compute real_roas
        $campaigns = [];
        foreach ($spendRows as $row) {
            $spend   = (float) $row->spend;
            $extId   = strtolower((string) ($row->external_id ?? ''));
            $name    = strtolower((string) ($row->name ?? ''));
            $revenue = ($extId !== '' ? ($revenueByUtm[$extId] ?? null) : null)
                    ?? $revenueByUtm[$name]
                    ?? null;

            $realRoas = ($spend > 0 && $revenue !== null && $revenue > 0)
                ? round($revenue / $spend, 2)
                : null;

            $campaigns[] = [
                'id'        => (int)    $row->id,
                'name'      => (string) $row->name,
                'platform'  => (string) $row->platform,
                'spend'     => round($spend, 2),
                'revenue'   => $revenue !== null ? round($revenue, 2) : null,
                'real_roas' => $realRoas,
            ];
        }

        // Rank only campaigns with real_roas; sort descending
        $ranked = array_values(array_filter($campaigns, fn ($c) => $c['real_roas'] !== null));
        if (count($ranked) < 2) {
            return ['winners' => $ranked, 'losers' => [], 'peer_avg_roas' => null];
        }

        usort($ranked, fn ($a, $b) => $b['real_roas'] <=> $a['real_roas']);

        $peerAvgRoas = round(array_sum(array_column($ranked, 'real_roas')) / count($ranked), 2);

        $n = min(5, (int) floor(count($ranked) / 2));

        return [
            'winners'       => array_values(array_slice($ranked, 0, $n)),
            'losers'        => array_values(array_reverse(array_slice($ranked, -$n))),
            'peer_avg_roas' => $peerAvgRoas,
        ];
    }

    /**
     * Top/bottom 5 products by revenue.
     * Out-of-stock products are excluded from the loser ranking — they can't act on it.
     *
     * @return array{winners:list<array<string,mixed>>, losers:list<array<string,mixed>>}
     */
    private function topProducts(int $workspaceId, string $from, string $to): array
    {
        $rows = DB::select("
            SELECT
                dsp.product_external_id,
                COALESCE(MAX(dsp.product_name), MAX(p.name), dsp.product_external_id) AS name,
                MAX(p.image_url)              AS image_url,
                SUM(dsp.revenue)              AS revenue,
                SUM(dsp.units)                AS units,
                MAX(dsp.stock_status)         AS stock_status
            FROM daily_snapshot_products dsp
            LEFT JOIN products p
              ON  p.external_id  = dsp.product_external_id
              AND p.workspace_id = dsp.workspace_id
              AND p.store_id     = dsp.store_id
            WHERE dsp.workspace_id  = ?
              AND dsp.snapshot_date BETWEEN ? AND ?
              AND dsp.revenue       > 0
            GROUP BY dsp.product_external_id
            ORDER BY revenue DESC
        ", [$workspaceId, $from, $to]);

        if (empty($rows)) {
            return ['winners' => [], 'losers' => []];
        }

        $products = array_map(fn ($r) => [
            'external_id'  => (string) $r->product_external_id,
            'name'         => (string) $r->name,
            'image_url'    => $r->image_url,
            'revenue'      => round((float) $r->revenue, 2),
            'units'        => (int) $r->units,
            'stock_status' => (string) ($r->stock_status ?? 'instock'),
        ], $rows);

        // Out-of-stock excluded from loser ranking
        $rankable = array_values(array_filter($products, fn ($p) => $p['stock_status'] !== 'outofstock'));
        if (count($rankable) < 2) {
            return ['winners' => array_slice($rankable, 0, 5), 'losers' => []];
        }

        // Already sorted desc by SQL — slice top and bottom
        $n = min(5, (int) floor(count($rankable) / 2));

        return [
            'winners' => array_values(array_slice($rankable, 0, $n)),
            'losers'  => array_values(array_reverse(array_slice($rankable, -$n))),
        ];
    }

}
