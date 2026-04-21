<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Workspace;
use App\Services\RevenueAttributionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Computes UTM coverage health for a single workspace and persists it on the workspace row.
 *
 * Triggered by: ConnectStoreAction (on store connect), FacebookOAuthController::connectAdAccounts(),
 *               GoogleOAuthController::connectGoogleAdsAccounts(), nightly schedule (03:00 UTC, low queue).
 *
 * Reads from: orders (last 30 days, completed/processing only)
 * Writes to: workspaces.utm_coverage_pct, utm_coverage_status, utm_coverage_checked_at,
 *             utm_unrecognized_sources
 *
 * Coverage statuses:
 *   green  → ≥80% of orders have attribution_source IN ('pys', 'wc_native')
 *   amber  → 50–80%
 *   red    → <50%
 *
 * Only runs when the workspace has both has_store=true and has_ads=true — coverage is only
 * meaningful once there is both store order data and a paid ads integration to attribute against.
 *
 * See: PLANNING.md "UTM Coverage Health Check + Tag Generator"
 */
class ComputeUtmCoverageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $workspaceId) {}

    public function handle(RevenueAttributionService $attribution): void
    {
        $workspace = Workspace::withoutGlobalScopes()->find($this->workspaceId);

        if (! $workspace) {
            return;
        }

        // Only meaningful when both store orders and ads are present.
        if (! $workspace->has_store || ! $workspace->has_ads) {
            return;
        }

        $from = now()->subDays(29)->toDateString();
        $to   = now()->toDateString();

        [$coveragePct, $status] = $this->computeCoverage($this->workspaceId, $from, $to);
        $unrecognized = $attribution->getUnrecognizedSources($this->workspaceId, $from, $to);

        $workspace->update([
            'utm_coverage_pct'          => $coveragePct,
            'utm_coverage_status'       => $status,
            'utm_coverage_checked_at'   => now(),
            'utm_unrecognized_sources'  => $unrecognized,
        ]);
    }

    /**
     * Compute what percentage of the last 30 days' orders have explicit UTM attribution.
     *
     * "Tagged" = attribution_source IN ('pys', 'wc_native') — orders where a UTM-tagged
     * URL was tracked by PixelYourSite or WooCommerce native. Referrer-heuristic orders
     * (source='referrer') do not count as tagged since no UTM link was clicked.
     *
     * Returns [float $pct, string $status] where $pct is 0–100 (null becomes 0).
     */
    private function computeCoverage(int $workspaceId, string $from, string $to): array
    {
        $row = \Illuminate\Support\Facades\DB::selectOne(
            <<<SQL
                SELECT
                    COUNT(*)                                                                        AS total,
                    COUNT(*) FILTER (WHERE attribution_source IN ('pys', 'wc_native'))              AS tagged
                FROM orders
                WHERE workspace_id = ?
                  AND status IN ('completed', 'processing')
                  AND occurred_at BETWEEN ? AND ?
            SQL,
            [$workspaceId, $from . ' 00:00:00', $to . ' 23:59:59'],
        );

        $total  = (int) ($row->total  ?? 0);
        $tagged = (int) ($row->tagged ?? 0);

        if ($total === 0) {
            // No orders — treat as green (nothing to misattribute yet).
            return [100.0, 'green'];
        }

        $pct = round(($tagged / $total) * 100, 2);

        $status = match (true) {
            $pct >= 80 => 'green',
            $pct >= 50 => 'amber',
            default    => 'red',
        };

        return [$pct, $status];
    }
}
