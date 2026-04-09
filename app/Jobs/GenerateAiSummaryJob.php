<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AdInsight;
use App\Models\AiSummary;
use App\Models\DailySnapshot;
use App\Models\GscDailyStat;
use App\Models\SearchConsoleProperty;
use App\Models\Store;
use App\Models\Workspace;
use App\Services\Ai\AiSummaryService;
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
 * Generates the AI daily summary for a single workspace.
 *
 * Queue:   low
 * Timeout: 120 s
 * Tries:   2
 * Backoff: [60, 300] s
 *
 * Skip conditions (any → abort silently):
 *  - No active store for the workspace
 *  - Owner's last_login_at > 7 days ago (or never logged in)
 *  - Summary for today already exists in ai_summaries
 *
 * Data: yesterday, day-before, same-weekday-last-week from daily_snapshots
 *       + ad_insights WHERE level='campaign'. Omit GSC key if no property.
 *
 * Dispatched daily between 01:00–02:00 UTC, staggered by (workspace_id % 60)
 * minutes to spread load across the window.
 */
class GenerateAiSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        private readonly int $workspaceId,
    ) {
        $this->onQueue('low');
    }

    public function handle(AiSummaryService $aiService): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $workspace = Workspace::withoutGlobalScopes()
            ->with('owner')
            ->find($this->workspaceId);

        if ($workspace === null) {
            Log::warning('GenerateAiSummaryJob: workspace not found', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        // Skip: no active store
        $hasActiveStore = Store::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->where('status', 'active')
            ->exists();

        if (! $hasActiveStore) {
            Log::info('GenerateAiSummaryJob: no active store, skipping', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        // Skip: owner last_login_at > 7 days ago (or null = never logged in)
        $owner = $workspace->owner;

        if (
            $owner === null
            || $owner->last_login_at === null
            || $owner->last_login_at->lt(now()->subDays(7))
        ) {
            Log::info('GenerateAiSummaryJob: owner inactive, skipping', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        $today = now()->toDateString();

        // Skip: summary for today already exists
        $alreadyGenerated = AiSummary::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->whereDate('date', $today)
            ->exists();

        if ($alreadyGenerated) {
            Log::info('GenerateAiSummaryJob: summary already exists for today, skipping', [
                'workspace_id' => $this->workspaceId,
                'date'         => $today,
            ]);
            return;
        }

        // ---------------------------------------------------------------
        // Assemble payload
        // ---------------------------------------------------------------
        $yesterday           = now()->subDay()->toDateString();
        $dayBefore           = now()->subDays(2)->toDateString();
        $sameWeekdayLastWeek = now()->subWeek()->toDateString();

        $dates = [$yesterday, $dayBefore, $sameWeekdayLastWeek];

        $payload = [
            'workspace' => [
                'name'                => $workspace->name,
                'reporting_currency'  => $workspace->reporting_currency,
            ],
            'days' => [
                'yesterday'              => $this->buildDayMetrics($yesterday),
                'day_before'             => $this->buildDayMetrics($dayBefore),
                'same_weekday_last_week' => $this->buildDayMetrics($sameWeekdayLastWeek),
            ],
        ];

        // Optionally append GSC data
        $hasGscProperty = SearchConsoleProperty::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->where('status', 'active')
            ->exists();

        if ($hasGscProperty) {
            $payload['gsc'] = [
                'yesterday'              => $this->buildGscMetrics($yesterday),
                'day_before'             => $this->buildGscMetrics($dayBefore),
                'same_weekday_last_week' => $this->buildGscMetrics($sameWeekdayLastWeek),
            ];
        }

        // ---------------------------------------------------------------
        // Call Anthropic API
        // ---------------------------------------------------------------
        $result = $aiService->generate($payload);

        // ---------------------------------------------------------------
        // Upsert ai_summaries (idempotent on workspace_id + date)
        // ---------------------------------------------------------------
        DB::table('ai_summaries')->upsert(
            [[
                'workspace_id'  => $this->workspaceId,
                'date'          => $today,
                'summary_text'  => $result['text'],
                'payload_sent'  => json_encode($payload, JSON_THROW_ON_ERROR),
                'model_used'    => $result['model'],
                'generated_at'  => now()->toDateTimeString(),
                'created_at'    => now()->toDateTimeString(),
                'updated_at'    => now()->toDateTimeString(),
            ]],
            ['workspace_id', 'date'],
            ['summary_text', 'payload_sent', 'model_used', 'generated_at', 'updated_at'],
        );

        Log::info('GenerateAiSummaryJob: summary generated', [
            'workspace_id' => $this->workspaceId,
            'date'         => $today,
            'model'        => $result['model'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build per-day metrics from daily_snapshots + ad_insights (campaign level).
     *
     * @return array<string, mixed>
     */
    private function buildDayMetrics(string $date): array
    {
        // Aggregate daily_snapshots across all stores for this workspace
        $snapshot = DailySnapshot::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->whereDate('date', $date)
            ->selectRaw('
                SUM(orders_count) as orders_count,
                SUM(revenue) as revenue,
                SUM(new_customers) as new_customers,
                SUM(returning_customers) as returning_customers,
                CASE WHEN SUM(orders_count) > 0 THEN SUM(revenue) / SUM(orders_count) ELSE NULL END as aov
            ')
            ->first();

        // Aggregate ad spend at campaign level for this workspace on this date
        $adRow = AdInsight::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->where('level', 'campaign')
            ->whereDate('date', $date)
            ->whereNull('hour')
            ->selectRaw('SUM(spend_in_reporting_currency) as total_spend')
            ->first();

        $revenue  = $snapshot ? (float) ($snapshot->revenue ?? 0) : 0.0;
        $adSpend  = $adRow ? (float) ($adRow->total_spend ?? 0) : null;

        $roas = null;
        if ($adSpend !== null && $adSpend > 0 && $revenue > 0) {
            $roas = round($revenue / $adSpend, 2);
        }

        return [
            'date'               => $date,
            'revenue'            => $snapshot ? round($revenue, 2) : 0.0,
            'orders_count'       => $snapshot ? (int) ($snapshot->orders_count ?? 0) : 0,
            'aov'                => $snapshot && $snapshot->aov !== null ? round((float) $snapshot->aov, 2) : null,
            'new_customers'      => $snapshot ? (int) ($snapshot->new_customers ?? 0) : 0,
            'returning_customers'=> $snapshot ? (int) ($snapshot->returning_customers ?? 0) : 0,
            'ad_spend'           => $adSpend !== null ? round($adSpend, 2) : null,
            'roas'               => $roas,
        ];
    }

    /**
     * Build GSC metrics for a single date (aggregated across all properties).
     *
     * @return array<string, mixed>|null  null when no data exists for the date
     */
    private function buildGscMetrics(string $date): ?array
    {
        $row = GscDailyStat::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->whereDate('date', $date)
            ->selectRaw('
                SUM(clicks) as clicks,
                SUM(impressions) as impressions,
                AVG(position) as position
            ')
            ->first();

        if ($row === null || ($row->clicks === null && $row->impressions === null)) {
            return null;
        }

        return [
            'date'        => $date,
            'clicks'      => (int) ($row->clicks ?? 0),
            'impressions' => (int) ($row->impressions ?? 0),
            'position'    => $row->position !== null ? round((float) $row->position, 1) : null,
        ];
    }
}
