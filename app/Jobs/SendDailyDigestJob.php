<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\DailyDigestMail;
use App\Models\Alert;
use App\Models\NotificationPreference;
use App\Models\Workspace;
use App\Scopes\WorkspaceScope;
use App\Services\NarrativeTemplateService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the daily (or weekly Monday) digest email to the workspace owner.
 *
 * Checks notification_preferences opt-in before computing any metrics. Hero
 * metrics are queried directly from daily_snapshots + ad_insights — not via
 * DashboardController, which is HTTP-only. The attention-item logic replicates
 * DashboardController::computeTodaysAttention() for job context.
 *
 * Queue:   default
 * Timeout: 60 s
 * Tries:   3
 *
 * Dispatched by: DispatchDailyDigestsJob (hourly scheduler action)
 *
 * Guards:
 *   - Owner must have a verified email address.
 *   - At least one notification_preference row with channel='email' AND
 *     delivery_mode IN ('daily_digest','weekly_digest') AND enabled=true.
 *   - weekly_digest-only users skip non-Monday sends.
 *
 * Reads:  workspaces, users, notification_preferences, daily_snapshots,
 *         ad_insights, alerts, recommendations
 * Writes: mail queue
 *
 * @see App\Jobs\DispatchDailyDigestsJob
 * @see PROGRESS.md Phase 3.7 — Daily digest
 */
class SendDailyDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 3;

    public function __construct(
        public readonly int $workspaceId,
        public readonly bool $isMonday,
    ) {
        $this->onQueue('default');
    }

    public function handle(NarrativeTemplateService $narrativeService): void
    {
        $workspace = Workspace::withoutGlobalScope(WorkspaceScope::class)
            ->with('owner')
            ->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $owner = $workspace->owner;

        if ($owner === null || $owner->email_verified_at === null) {
            return;
        }

        // Opt-in: at least one enabled email preference using digest delivery.
        $prefs = NotificationPreference::withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $this->workspaceId)
            ->where('user_id', $owner->id)
            ->where('channel', 'email')
            ->where('enabled', true)
            ->whereIn('delivery_mode', ['daily_digest', 'weekly_digest'])
            ->get(['delivery_mode']);

        if ($prefs->isEmpty()) {
            return;
        }

        $hasDaily  = $prefs->contains('delivery_mode', 'daily_digest');
        $hasWeekly = $prefs->contains('delivery_mode', 'weekly_digest');

        // weekly_digest-only users skip non-Monday sends entirely.
        if (! $hasDaily && $hasWeekly && ! $this->isMonday) {
            return;
        }

        // When both modes coexist, daily wins on non-Mondays; weekly wins on Mondays.
        $isWeekly = $this->isMonday && $hasWeekly && ! $hasDaily;

        $tz        = $workspace->timezone ?: 'UTC';
        $endDate   = Carbon::yesterday($tz)->toDateString();
        $startDate = $isWeekly
            ? Carbon::yesterday($tz)->subDays(6)->toDateString()
            : $endDate;

        $metrics        = $this->computeHeroMetrics($this->workspaceId, $startDate, $endDate);
        $attentionItems = $this->computeAttentionItems($this->workspaceId, $workspace->slug);

        $narrative = $narrativeService->forDashboard(
            revenue:         $metrics['revenue'] > 0 ? $metrics['revenue'] : null,
            compareRevenue:  null,
            comparisonLabel: null,
            roas:            $metrics['roas'],
            hasAds:          $workspace->has_ads,
            hasGsc:          $workspace->has_gsc,
        );

        Mail::to($owner->email)->queue(new DailyDigestMail(
            workspace:      $workspace,
            narrative:      $narrative,
            heroMetrics:    $metrics,
            attentionItems: $attentionItems,
            isWeekly:       $isWeekly,
            startDate:      $startDate,
            endDate:        $endDate,
        ));

        Log::info('SendDailyDigestJob: digest queued', [
            'workspace_id' => $this->workspaceId,
            'owner_id'     => $owner->id,
            'is_weekly'    => $isWeekly,
            'date_range'   => "{$startDate} → {$endDate}",
        ]);
    }

    /**
     * Yesterday's (or last-7-days) hero numbers from daily_snapshots + ad_insights.
     * Uses workspace_id explicitly — WorkspaceScope is request-bound and unavailable here.
     *
     * ROAS = total revenue ÷ total ad spend (MER — blended, no attribution filter).
     * Sufficient for the digest summary; precise Real ROAS lives on the Acquisition page.
     *
     * @return array{revenue:float, orders:int, ad_spend:float, roas:float|null}
     */
    private function computeHeroMetrics(int $workspaceId, string $startDate, string $endDate): array
    {
        $snapshot = DB::table('daily_snapshots')
            ->join('stores', 'daily_snapshots.store_id', '=', 'stores.id')
            ->where('stores.workspace_id', $workspaceId)
            ->whereBetween('daily_snapshots.date', [$startDate, $endDate])
            ->selectRaw('
                COALESCE(SUM(daily_snapshots.revenue), 0)      AS revenue,
                COALESCE(SUM(daily_snapshots.orders_count), 0) AS orders
            ')
            ->first();

        $adSpend = (float) DB::table('ad_insights')
            ->join('ad_accounts', 'ad_insights.ad_account_id', '=', 'ad_accounts.id')
            ->where('ad_accounts.workspace_id', $workspaceId)
            ->where('ad_insights.level', 'campaign')
            ->whereNull('ad_insights.hour')
            ->whereBetween('ad_insights.date', [$startDate, $endDate])
            ->sum('ad_insights.spend');

        $revenue = (float) ($snapshot->revenue ?? 0);
        $roas    = $adSpend > 0 ? round($revenue / $adSpend, 2) : null;

        return [
            'revenue'  => $revenue,
            'orders'   => (int) ($snapshot->orders ?? 0),
            'ad_spend' => $adSpend,
            'roas'     => $roas,
        ];
    }

    /**
     * Replicates DashboardController::computeTodaysAttention() for job context.
     * Alerts take precedence; fills remaining slots with open recommendations.
     * Capped at 5 items. Hrefs are absolute URLs (required for email links).
     *
     * @return array<int, array{text:string, href:string, severity:string}>
     */
    private function computeAttentionItems(int $workspaceId, string $workspaceSlug): array
    {
        $items = [];

        $alerts = Alert::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereNull('resolved_at')
            ->where('is_silent', false)
            ->whereNotIn('severity', ['info'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['type', 'severity']);

        foreach ($alerts as $alert) {
            $items[] = [
                'text'     => ucfirst(str_replace('_', ' ', $alert->type)),
                'href'     => url("/{$workspaceSlug}/manage/integrations"),
                'severity' => $alert->severity === 'critical' ? 'critical' : 'warning',
            ];
        }

        $remaining = 5 - count($items);
        if ($remaining > 0) {
            $recs = DB::table('recommendations')
                ->where('workspace_id', $workspaceId)
                ->where('status', 'open')
                ->where(function ($q): void {
                    $q->whereNull('snoozed_until')
                      ->orWhere('snoozed_until', '<', now());
                })
                ->orderBy('priority')
                ->orderByDesc('created_at')
                ->limit($remaining)
                ->get(['title', 'target_url']);

            foreach ($recs as $rec) {
                $rawHref = $rec->target_url ?: '/acquisition';
                $href    = str_starts_with($rawHref, 'http')
                    ? $rawHref
                    : url("/{$workspaceSlug}" . $rawHref);

                $items[] = [
                    'text'     => $rec->title,
                    'href'     => $href,
                    'severity' => 'info',
                ];
            }
        }

        return array_slice($items, 0, 5);
    }
}
