<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PsiQuotaExceededException;
use App\Models\LighthouseSnapshot;
use App\Models\StoreUrl;
use App\Models\Workspace;
use App\Services\PerformanceMonitoring\PsiClient;
use App\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Runs a PageSpeed Insights check for a single monitored URL.
 *
 * Triggered by: routes/console.php (daily, staggered across 4-hour window
 *               by store_url_id % 240 minutes, starting at 04:00 UTC).
 * Also dispatched immediately when a store_url is first created.
 *
 * Writes to:   lighthouse_snapshots (one row per check)
 * Side-effect: sets workspaces.has_psi = true on the first successful snapshot
 *              so the nav indicator and dashboard row become visible.
 *
 * Queue:   sync-psi
 * Timeout: 60 s (PSI responses can take 15–30 s for slow pages)
 * Tries:   2 (PSI failures are transient; a missed daily check is not critical)
 * Backoff: [300] s (5 min before retry)
 *
 * Quota exceeded: caught silently, warning logged, no retry consumed.
 * This matches the PLANNING.md guidance: "skip gracefully, log warning, retry next day".
 *
 * See: PLANNING.md "Performance Monitoring — PSI Rate Limit Planning"
 * Related: app/Services/PerformanceMonitoring/PsiClient.php
 * Related: app/Models/StoreUrl.php
 * Related: app/Models/LighthouseSnapshot.php
 */
class RunLighthouseCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;
    public int $tries   = 2;

    /** @var int[] */
    public array $backoff = [300];

    public function __construct(
        private readonly int $storeUrlId,
        private readonly int $storeId,
        private readonly int $workspaceId,
        private readonly string $strategy = 'mobile',
    ) {
        $this->onQueue('sync-psi');
    }

    public function handle(): void
    {
        app(WorkspaceContext::class)->set($this->workspaceId);

        $storeUrl = StoreUrl::withoutGlobalScopes()->find($this->storeUrlId);

        if ($storeUrl === null || ! $storeUrl->is_active) {
            return;
        }

        $client = new PsiClient(
            apiKey:         config('services.psi.api_key'),
            timeoutSeconds: (int) config('services.psi.timeout', 30),
        );

        try {
            $result = $client->check($storeUrl->url, $this->strategy);
        } catch (PsiQuotaExceededException $e) {
            // Why: PSI quota exhausted for today. Skip gracefully — do NOT consume
            // a retry attempt or increment failure counters. The next scheduled daily
            // run will try again automatically.
            // See: PLANNING.md "Performance Monitoring — PSI Rate Limit Planning"
            Log::warning('RunLighthouseCheckJob: PSI quota exceeded, skipping', [
                'store_url_id' => $this->storeUrlId,
                'url'          => $storeUrl->url,
            ]);
            return;
        }

        $now = now();

        LighthouseSnapshot::create([
            'workspace_id'             => $this->workspaceId,
            'store_id'                 => $this->storeId,
            'store_url_id'             => $this->storeUrlId,
            'checked_at'               => $now,
            'strategy'                 => $this->strategy,
            'performance_score'        => $result['performance_score'],
            'seo_score'                => $result['seo_score'],
            'accessibility_score'      => $result['accessibility_score'],
            'best_practices_score'     => $result['best_practices_score'],
            'lcp_ms'                   => $result['lcp_ms'],
            'fcp_ms'                   => $result['fcp_ms'],
            'cls_score'                => $result['cls_score'],
            'inp_ms'                   => $result['inp_ms'],
            'ttfb_ms'                  => $result['ttfb_ms'],
            'tbt_ms'                   => $result['tbt_ms'],
            'crux_source'              => $result['crux_source'],
            'crux_lcp_p75_ms'          => $result['crux_lcp_p75_ms'],
            'crux_inp_p75_ms'          => $result['crux_inp_p75_ms'],
            'crux_cls_p75'             => $result['crux_cls_p75'],
            'crux_fcp_p75_ms'          => $result['crux_fcp_p75_ms'],
            'crux_ttfb_p75_ms'         => $result['crux_ttfb_p75_ms'],
            'raw_response'             => $result['raw_response'],
            'raw_response_api_version' => $result['api_version'],
            'created_at'               => $now,
        ]);

        // Set has_psi on the workspace after the first successful snapshot.
        // Why: has_psi drives nav visibility (Site Performance link) and the
        // dashboard "Site health" row. We only flip it once — no need to update
        // every time a check runs.
        $workspace = Workspace::withoutGlobalScopes()->find($this->workspaceId);
        if ($workspace !== null && ! $workspace->has_psi) {
            $workspace->update(['has_psi' => true]);
        }

        // Auto-populate label from <title> tag when the user didn't set one.
        // Only do this on mobile to avoid a duplicate fetch.
        // Why: store_urls.label drives all UI display; without it pages show as raw URLs.
        if ($this->strategy === 'mobile' && $storeUrl->label === null) {
            $this->fetchAndSaveTitle($storeUrl);
        }

        Log::info('RunLighthouseCheckJob: completed', [
            'store_url_id'      => $this->storeUrlId,
            'url'               => $storeUrl->url,
            'strategy'          => $this->strategy,
            'performance_score' => $result['performance_score'],
        ]);
    }

    /**
     * Fetch the page <title> from the URL and save it as the store_url label.
     *
     * Uses a 10 s HTTP GET with a browser-like UA to avoid bot blocks.
     * Strips whitespace and truncates to 255 chars. Silent on failure — a missing
     * title is non-critical; the URL itself is still a valid fallback in the UI.
     */
    private function fetchAndSaveTitle(StoreUrl $storeUrl): void
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Nexstage/1.0)'])
                ->timeout(10)
                ->get($storeUrl->url);

            if (! $response->successful()) {
                return;
            }

            if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $response->body(), $matches)) {
                $title = mb_substr(trim(html_entity_decode($matches[1])), 0, 255);
                if ($title !== '') {
                    $storeUrl->update(['label' => $title]);
                    Log::info('RunLighthouseCheckJob: auto-saved page title', [
                        'store_url_id' => $this->storeUrlId,
                        'title'        => $title,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('RunLighthouseCheckJob: failed to fetch page title', [
                'store_url_id' => $this->storeUrlId,
                'url'          => $storeUrl->url,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
