<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Models\LighthouseSnapshot;
use Carbon\Carbon;
use App\Models\StoreUrl;
use App\Models\Workspace;
use App\Models\WorkspaceEvent;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance page — Lighthouse / PageSpeed Insights data.
 *
 * Triggered by: GET /performance
 * Reads from:   store_urls, lighthouse_snapshots, holidays, workspace_events
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
 */
class PerformanceController extends Controller
{
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

        // ── URL summary table: latest mobile + desktop scores per URL ──────────
        $urlSummary = array_map(static function (array $su) use ($latestPerUrlStrategy): array {
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
            ];
        }, $storeUrls);

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
}
