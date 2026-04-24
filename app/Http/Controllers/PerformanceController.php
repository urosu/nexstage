<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Models\LighthouseSnapshot;
use Carbon\Carbon;
use App\Models\StoreUrl;
use App\Models\Workspace;
use App\Models\WorkspaceEvent;
use App\Services\NarrativeTemplateService;
use App\Services\PerformanceMonitoring\PerformanceRevenueService;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance page — Lighthouse / PageSpeed Insights data.
 *
 * Triggered by: GET /performance
 * Reads from:   store_urls, lighthouse_snapshots, gsc_pages, orders,
 *               search_console_properties, holidays, workspace_events
 * Writes to:    nothing
 *
 * Both mobile and desktop strategies are returned simultaneously so the page
 * can show them side by side without requiring a strategy toggle.
 *
 * URL state is managed via query params: ?url_id=X&from=Y-m-d&to=Y-m-d
 *
 * See: PLANNING.md "Performance Monitoring — Performance page"
 * Related: app/Jobs/RunLighthouseCheckJob.php
 * Related: app/Models/LighthouseSnapshot.php
 * Related: app/Services/PerformanceMonitoring/PerformanceRevenueService.php
 */
class PerformanceController extends Controller
{
    public function __construct(
        private readonly PerformanceRevenueService $revenue,
        private readonly NarrativeTemplateService  $narrativeService,
    ) {}

    public function __invoke(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();
        $workspace   = Workspace::withoutGlobalScopes()->findOrFail($workspaceId);

        $validated = $request->validate([
            'url_id' => ['sometimes', 'nullable', 'integer'],
            'from'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to'     => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = $validated['from'] ?? now()->subDays(29)->toDateString();
        $to   = $validated['to']   ?? now()->toDateString();

        // ── Monitored URLs ─────────────────────────────────────────────────────
        $storeUrls = StoreUrl::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->with('store:id,name,slug')
            ->orderByDesc('is_homepage')
            ->orderBy('id')
            ->get()
            ->map(fn (StoreUrl $su) => [
                'id'          => $su->id,
                'url'         => $su->url,
                'label'       => $su->label,
                'is_homepage' => $su->is_homepage,
                'store_id'    => $su->store_id,
                'store_name'  => $su->store?->name,
                'store_slug'  => $su->store?->slug,
            ])
            ->all();

        if (empty($storeUrls)) {
            return Inertia::render('Performance/Index', [
                'store_urls'               => [],
                'selected_url'             => null,
                'mobile_latest'            => null,
                'desktop_latest'           => null,
                'mobile_history'           => [],
                'desktop_history'          => [],
                'mobile_score_delta'       => null,
                'desktop_score_delta'      => null,
                'url_summary'              => [],
                'holiday_overlays'         => [],
                'workspace_event_overlays' => [],
                'from'                     => $from,
                'to'                       => $to,
                'revenue_at_risk'          => 0.0,
                'performance_audits'       => [],
                'performance_alerts'       => [],
                'narrative'                => null,
            ]);
        }

        $allUrlIds      = array_column($storeUrls, 'id');
        $requestedUrlId = isset($validated['url_id']) ? (int) $validated['url_id'] : null;
        $selectedUrlId  = ($requestedUrlId !== null && in_array($requestedUrlId, $allUrlIds, true))
            ? $requestedUrlId
            : $allUrlIds[0];

        // ── Latest snapshot per (URL, strategy) ──────────────────────────────
        // Keyed as "{store_url_id}_{strategy}" for O(1) lookup below.
        $latestPerUrlStrategy = LighthouseSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('strategy', ['mobile', 'desktop'])
            ->whereIn('store_url_id', $allUrlIds)
            ->selectRaw('
                DISTINCT ON (store_url_id, strategy)
                store_url_id,
                strategy,
                checked_at,
                performance_score,
                seo_score,
                accessibility_score,
                best_practices_score,
                lcp_ms,
                cls_score,
                inp_ms,
                ttfb_ms,
                tbt_ms,
                fcp_ms,
                crux_source,
                crux_lcp_p75_ms,
                crux_inp_p75_ms,
                crux_cls_p75,
                crux_fcp_p75_ms,
                crux_ttfb_p75_ms
            ')
            ->orderByRaw('store_url_id, strategy, checked_at DESC')
            ->get()
            ->keyBy(fn (LighthouseSnapshot $s) => $s->store_url_id . '_' . $s->strategy);

        // ── Selected URL: latest scores for each strategy ─────────────────────
        $mobileLatestRow  = $latestPerUrlStrategy->get($selectedUrlId . '_mobile');
        $desktopLatestRow = $latestPerUrlStrategy->get($selectedUrlId . '_desktop');

        $mobileLatest  = $this->buildLatestScores($mobileLatestRow);
        $desktopLatest = $this->buildLatestScores($desktopLatestRow);

        // TTFB/TBT/FCP are now included in the DISTINCT ON select above — no extra queries needed.
        foreach ([
            'mobile'  => ['row' => $mobileLatestRow,  'scores' => &$mobileLatest],
            'desktop' => ['row' => $desktopLatestRow, 'scores' => &$desktopLatest],
        ] as ['row' => $row, 'scores' => &$scores]) {
            if ($scores !== null && $row !== null) {
                $scores['ttfb_ms'] = $row->ttfb_ms;
                $scores['tbt_ms']  = $row->tbt_ms;
                $scores['fcp_ms']  = $row->fcp_ms;
            }
        }
        unset($scores);

        // ── History for selected URL ───────────────────────────────────────────
        $historyRows = LighthouseSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('store_url_id', $selectedUrlId)
            ->whereIn('strategy', ['mobile', 'desktop'])
            ->whereBetween('checked_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderBy('checked_at')
            ->select([
                'checked_at',
                'strategy',
                'performance_score',
                'seo_score',
                'accessibility_score',
                'best_practices_score',
                'lcp_ms',
                'cls_score',
                'inp_ms',
                'crux_source',
                'crux_lcp_p75_ms',
                'crux_inp_p75_ms',
                'crux_cls_p75',
            ])
            ->get();

        $mapHistory = fn (LighthouseSnapshot $s): array => [
            'date'                 => $s->checked_at->toDateString(),
            'checked_at'           => $s->checked_at->toISOString(),
            'performance_score'    => $s->performance_score,
            'seo_score'            => $s->seo_score,
            'accessibility_score'  => $s->accessibility_score,
            'best_practices_score' => $s->best_practices_score,
            'lcp_ms'               => $s->lcp_ms,
            'cls_score'            => $s->cls_score ? (float) $s->cls_score : null,
            'inp_ms'               => $s->inp_ms,
            'crux_source'          => $s->crux_source,
            'crux_lcp_p75_ms'      => $s->crux_lcp_p75_ms,
            'crux_inp_p75_ms'      => $s->crux_inp_p75_ms,
            'crux_cls_p75'         => $s->crux_cls_p75 ? (float) $s->crux_cls_p75 : null,
        ];

        $mobileHistory  = $historyRows->where('strategy', 'mobile')->values()->map($mapHistory)->all();
        $desktopHistory = $historyRows->where('strategy', 'desktop')->values()->map($mapHistory)->all();

        // ── Revenue and risk enrichment (§F18, §F19) ─────────────────────────
        $riskData         = $this->revenue->revenueAtRisk($workspaceId, $storeUrls);
        $monthlyOrdersMap = $this->revenue->monthlyOrdersPerUrl($workspaceId, $storeUrls);

        // ── URL summary table: latest mobile + desktop scores per URL ──────────
        $urlSummary = array_map(
            function (array $su) use ($latestPerUrlStrategy, $riskData, $monthlyOrdersMap): array {
                $mobile  = $latestPerUrlStrategy->get($su['id'] . '_mobile');
                $desktop = $latestPerUrlStrategy->get($su['id'] . '_desktop');

                return [
                    ...$su,
                    'mobile_performance_score'  => $mobile?->performance_score,
                    'mobile_seo_score'          => $mobile?->seo_score,
                    'mobile_lcp_ms'             => $mobile?->lcp_ms,
                    'mobile_inp_ms'             => $mobile?->inp_ms,
                    'desktop_performance_score' => $desktop?->performance_score,
                    'desktop_seo_score'         => $desktop?->seo_score,
                    'desktop_lcp_ms'            => $desktop?->lcp_ms,
                    'last_checked_at'           => $mobile?->checked_at?->toISOString()
                        ?? $desktop?->checked_at?->toISOString(),
                    'monthly_orders'            => $monthlyOrdersMap[$su['id']] ?? 0,
                    'revenue_risk'              => $riskData['per_url'][$su['id']] ?? 0.0,
                ];
            },
            $storeUrls,
        );

        // ── Event overlays ────────────────────────────────────────────────────
        $leadDays        = $workspace->workspace_settings->holidayLeadDays;
        $today           = now()->toDateString();
        $queryFrom       = $leadDays > 0 ? Carbon::parse($from)->addDays($leadDays)->toDateString() : $from;
        $queryTo         = $leadDays > 0 ? Carbon::parse($to)->addDays($leadDays)->toDateString()   : $to;
        $holidayOverlays = [];
        if ($workspace->country !== null) {
            $holidayOverlays = Holiday::whereBetween('date', [$queryFrom, $queryTo])
                ->where('country_code', $workspace->country)
                ->orderBy('date')
                ->get(['date', 'name', 'type'])
                ->map(function ($h) use ($leadDays, $today) {
                    $actualDate  = $h->date->toDateString();
                    $displayDate = $leadDays > 0
                        ? $h->date->copy()->subDays($leadDays)->toDateString()
                        : $actualDate;

                    return [
                        'date'        => $displayDate,
                        'name'        => $h->name,
                        'type'        => $h->type,
                        'is_upcoming' => $leadDays > 0 && $actualDate > $today,
                        'lead_days'   => $leadDays,
                        'actual_date' => $leadDays > 0 ? $h->date->format('M j') : null,
                    ];
                })
                ->all();
        }

        // No active scope filter on performance page — show workspace-wide events only.
        $workspaceEventOverlays = WorkspaceEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('date_from', '<=', $to)
            ->where('date_to',   '>=', $from)
            ->forAnnotationScope()
            ->orderBy('date_from')
            ->get(['date_from', 'date_to', 'name', 'event_type'])
            ->map(fn ($e) => [
                'date_from'  => $e->date_from->toDateString(),
                'date_to'    => $e->date_to->toDateString(),
                'name'       => $e->name,
                'event_type' => $e->event_type,
            ])
            ->all();

        $scoreDeltas = $this->buildScoreDeltas(
            $workspaceId,
            $selectedUrlId,
            $from,
            $mobileLatestRow,
            $desktopLatestRow,
        );

        // ── Audit drill-down for selected URL ─────────────────────────────────
        $selectedMobileWithRaw = LighthouseSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('store_url_id', $selectedUrlId)
            ->where('strategy', 'mobile')
            ->orderByDesc('checked_at')
            ->select(['raw_response'])
            ->first();

        $performanceAudits = $this->buildAuditList($selectedMobileWithRaw);

        // ── Regression alerts ─────────────────────────────────────────────────
        $performanceAlerts = $this->buildPerformanceAlerts(
            $workspaceId,
            $storeUrls,
            $latestPerUrlStrategy,
        );

        // ── Page narrative (§NarrativeTemplateService::forPerformance) ────────
        $narrative = $this->narrativeService->forPerformance(
            $mobileLatest['performance_score'] ?? null,
            $mobileLatest['lcp_ms'] ?? null,
            $riskData['total'] > 0 ? (float) $riskData['total'] : null,
        );

        return Inertia::render('Performance/Index', [
            'store_urls'               => $storeUrls,
            'selected_url_id'          => $selectedUrlId,
            'mobile_latest'            => $mobileLatest,
            'desktop_latest'           => $desktopLatest,
            'mobile_history'           => $mobileHistory,
            'desktop_history'          => $desktopHistory,
            'mobile_score_delta'       => $scoreDeltas['mobile'],
            'desktop_score_delta'      => $scoreDeltas['desktop'],
            'url_summary'              => $urlSummary,
            'holiday_overlays'         => $holidayOverlays,
            'workspace_event_overlays' => $workspaceEventOverlays,
            'from'                     => $from,
            'to'                       => $to,
            'revenue_at_risk'          => $riskData['total'],
            'performance_audits'       => $performanceAudits,
            'performance_alerts'       => $performanceAlerts,
            'narrative'                => $narrative,
        ]);
    }

    /**
     * Build the LatestScores shape from a snapshot row, or null if no row.
     *
     * TTFB/TBT/FCP are fetched separately (not in the DISTINCT ON select) and
     * merged in by the caller after this method returns.
     *
     * @return array<string,mixed>|null
     */
    private function buildLatestScores(?LighthouseSnapshot $snap): ?array
    {
        if ($snap === null) {
            return null;
        }

        return [
            'performance_score'    => $snap->performance_score,
            'seo_score'            => $snap->seo_score,
            'accessibility_score'  => $snap->accessibility_score,
            'best_practices_score' => $snap->best_practices_score,
            'lcp_ms'               => $snap->lcp_ms,
            'cls_score'            => $snap->cls_score ? (float) $snap->cls_score : null,
            'inp_ms'               => $snap->inp_ms,
            'ttfb_ms'              => null, // filled in by caller
            'tbt_ms'               => null,
            'fcp_ms'               => null,
            'crux_source'          => $snap->crux_source,
            'crux_lcp_p75_ms'      => $snap->crux_lcp_p75_ms,
            'crux_inp_p75_ms'      => $snap->crux_inp_p75_ms,
            'crux_cls_p75'         => $snap->crux_cls_p75 ? (float) $snap->crux_cls_p75 : null,
            'crux_fcp_p75_ms'      => $snap->crux_fcp_p75_ms,
            'crux_ttfb_p75_ms'     => $snap->crux_ttfb_p75_ms,
            'checked_at'           => $snap->checked_at?->toISOString(),
        ];
    }

    /**
     * Compute integer point deltas (latest − prior) for all 4 Lighthouse score types.
     *
     * "Prior" is the most-recent snapshot strictly before the $from date, giving
     * users a clear "how much did my scores change over this period" signal.
     *
     * Returns null per-strategy when no prior snapshot exists.
     *
     * @return array{
     *   mobile:  array{performance: int|null, seo: int|null, accessibility: int|null, best_practices: int|null}|null,
     *   desktop: array{performance: int|null, seo: int|null, accessibility: int|null, best_practices: int|null}|null,
     * }
     */
    private function buildScoreDeltas(
        int $workspaceId,
        int $selectedUrlId,
        string $from,
        ?LighthouseSnapshot $mobileLatestRow,
        ?LighthouseSnapshot $desktopLatestRow,
    ): array {
        $priorRows = LighthouseSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('store_url_id', $selectedUrlId)
            ->whereIn('strategy', ['mobile', 'desktop'])
            ->where('checked_at', '<', $from . ' 00:00:00')
            ->selectRaw('
                DISTINCT ON (strategy)
                strategy,
                performance_score,
                seo_score,
                accessibility_score,
                best_practices_score
            ')
            ->orderByRaw('strategy, checked_at DESC')
            ->get()
            ->keyBy('strategy');

        $diff = static fn (?int $a, ?int $b): ?int =>
            ($a !== null && $b !== null) ? $a - $b : null;

        $build = function (?LighthouseSnapshot $latest, ?LighthouseSnapshot $prior) use ($diff): ?array {
            if ($latest === null || $prior === null) {
                return null;
            }
            return [
                'performance'    => $diff($latest->performance_score,    $prior->performance_score),
                'seo'            => $diff($latest->seo_score,            $prior->seo_score),
                'accessibility'  => $diff($latest->accessibility_score,  $prior->accessibility_score),
                'best_practices' => $diff($latest->best_practices_score, $prior->best_practices_score),
            ];
        };

        return [
            'mobile'  => $build($mobileLatestRow,  $priorRows->get('mobile')),
            'desktop' => $build($desktopLatestRow, $priorRows->get('desktop')),
        ];
    }

    /**
     * Parse the top failing audits from a Lighthouse snapshot's raw_response.
     *
     * Sorted by (weight × (1 − score)) descending — highest score-impact first,
     * matching DebugBear's "sorted by impact" presentation.
     * Returns at most 15 audits to avoid overwhelming the UI.
     *
     * @return array<int, array{id: string, title: string, description: string|null, score: float|null, weight: float, display_value: string|null}>
     */
    private function buildAuditList(?LighthouseSnapshot $snap): array
    {
        if ($snap === null || $snap->raw_response === null) {
            return [];
        }

        $lr        = $snap->raw_response['lighthouseResult'] ?? [];
        $auditRefs = $lr['categories']['performance']['auditRefs'] ?? [];
        $allAudits = $lr['audits'] ?? [];

        // Build weight map: auditId → weight (only audits that affect the score).
        $weights = [];
        foreach ($auditRefs as $ref) {
            if (isset($ref['id'], $ref['weight']) && $ref['weight'] > 0) {
                $weights[$ref['id']] = (float) $ref['weight'];
            }
        }

        $results = [];
        foreach ($weights as $id => $weight) {
            $audit = $allAudits[$id] ?? null;
            if ($audit === null) {
                continue;
            }

            $score = isset($audit['score']) ? (float) $audit['score'] : null;

            // Skip passing audits (score ≥ 0.9) and informational items (null score).
            if ($score === null || $score >= 0.9) {
                continue;
            }

            $results[] = [
                'id'            => $id,
                'title'         => $audit['title']       ?? $id,
                'description'   => $audit['description'] ?? null,
                'score'         => $score,
                'weight'        => $weight,
                'display_value' => $audit['displayValue'] ?? null,
            ];
        }

        // Sort descending by impact: weight × (1 − score).
        usort($results, static fn ($a, $b) =>
            ($b['weight'] * (1 - ($b['score'] ?? 1))) <=> ($a['weight'] * (1 - ($a['score'] ?? 1)))
        );

        return array_slice($results, 0, 15);
    }

    /**
     * Detect performance regressions by comparing the latest snapshot against
     * the most recent snapshot from the 7–14 day prior window.
     *
     * Three alert types (Uptime <99.5% deferred to Phase 5):
     *   score_drop      — performance_score dropped >10 pts
     *   lcp_regression  — LCP increased >500ms
     *   new_failing_audit — new audits with score < 0.9 vs 7 days ago
     *
     * Alerts are ephemeral (computed per request) — not written to the alerts table,
     * which is for durable anomaly signals with review workflow.
     *
     * @param  \Illuminate\Support\Collection<string, LighthouseSnapshot> $latestPerUrlStrategy
     * @return array<int, array{type: string, severity: string, message: string, url_id: int, url_label: string, delta: int|float}>
     */
    private function buildPerformanceAlerts(
        int $workspaceId,
        array $storeUrls,
        \Illuminate\Support\Collection $latestPerUrlStrategy,
    ): array {
        if (empty($storeUrls)) {
            return [];
        }

        $allUrlIds = array_column($storeUrls, 'id');

        // Prior snapshots: most recent mobile snapshot per URL in the 7–14 day window.
        $priorRows = LighthouseSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('store_url_id', $allUrlIds)
            ->where('strategy', 'mobile')
            ->whereBetween('checked_at', [
                now()->subDays(14)->startOfDay(),
                now()->subDays(7)->endOfDay(),
            ])
            ->selectRaw('
                DISTINCT ON (store_url_id)
                store_url_id,
                performance_score,
                lcp_ms,
                raw_response
            ')
            ->orderByRaw('store_url_id, checked_at DESC')
            ->get()
            ->keyBy('store_url_id');

        $alerts = [];

        foreach ($storeUrls as $su) {
            $latest = $latestPerUrlStrategy->get($su['id'] . '_mobile');
            $prior  = $priorRows->get($su['id']);
            $label  = $su['label'] ?? $su['url'];

            if ($latest === null || $prior === null) {
                continue;
            }

            // (a) Performance score drop > 10 pts.
            if ($latest->performance_score !== null && $prior->performance_score !== null) {
                $drop = $prior->performance_score - $latest->performance_score;
                if ($drop > 10) {
                    $alerts[] = [
                        'type'      => 'score_drop',
                        'severity'  => $drop > 20 ? 'critical' : 'warning',
                        'message'   => "Performance score dropped {$drop} pts (now {$latest->performance_score})",
                        'url_id'    => $su['id'],
                        'url_label' => $label,
                        'delta'     => $drop,
                    ];
                }
            }

            // (b) LCP regression > 500 ms.
            if ($latest->lcp_ms !== null && $prior->lcp_ms !== null) {
                $regression = $latest->lcp_ms - $prior->lcp_ms;
                if ($regression > 500) {
                    $alerts[] = [
                        'type'      => 'lcp_regression',
                        'severity'  => 'warning',
                        'message'   => 'LCP increased by ' . number_format($regression / 1000, 1) . 's',
                        'url_id'    => $su['id'],
                        'url_label' => $label,
                        'delta'     => $regression,
                    ];
                }
            }

            // (c) Newly failing audits vs 7 days ago.
            if ($latest->raw_response !== null && $prior->raw_response !== null) {
                $latestFailing = $this->failingAuditIds($latest->raw_response);
                $priorFailing  = $this->failingAuditIds($prior->raw_response);
                $newlyFailing  = array_diff($latestFailing, $priorFailing);

                if (count($newlyFailing) > 0) {
                    $n = count($newlyFailing);
                    $alerts[] = [
                        'type'      => 'new_failing_audit',
                        'severity'  => 'warning',
                        'message'   => "{$n} new failing " . ($n === 1 ? 'audit' : 'audits') . ' vs 7 days ago',
                        'url_id'    => $su['id'],
                        'url_label' => $label,
                        'delta'     => $n,
                    ];
                }
            }
        }

        return $alerts;
    }

    /**
     * Returns audit IDs with score < 0.9 from a raw_response array (already decoded).
     *
     * @param  array<string, mixed> $rawResponse
     * @return string[]
     */
    private function failingAuditIds(array $rawResponse): array
    {
        $audits  = $rawResponse['lighthouseResult']['audits'] ?? [];
        $failing = [];

        foreach ($audits as $id => $audit) {
            $score = $audit['score'] ?? null;
            if ($score !== null && (float) $score < 0.9) {
                $failing[] = $id;
            }
        }

        return $failing;
    }
}
