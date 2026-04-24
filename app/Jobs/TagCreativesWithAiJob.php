<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AdAccount;
use App\Models\CreativeTagCategory;
use App\Models\Workspace;
use App\Services\Ai\AiCreativeTaggerService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Classifies ad creatives for a single workspace into the fixed creative taxonomy.
 *
 * Queue:   low
 * Timeout: 300 s (image fetches + Anthropic API calls)
 * Tries:   2
 * Backoff: [60, 300] s
 *
 * Skip conditions:
 *  - Frozen workspace (trial expired + no paid plan)
 *  - No active ad accounts
 *
 * Batch: up to 20 ads per run, ordered by spend desc (top spenders first).
 * Eligible: ads with effective_status IN ('ACTIVE','PAUSED') that have no
 *   ad_creative_tags rows OR whose tagged_at is older than 30 days.
 * If more than 20 eligible ads remain after processing, the job dispatches
 *   itself to handle the next batch.
 *
 * Confidence is always 1.0 for AI-assigned tags because the model is constrained
 * to a fixed slug list — the pick is structural, not probabilistic.
 *
 * @see app/Services/Ai/AiCreativeTaggerService.php
 * @see database/seeders/CreativeTagSeeder.php
 * @see PROGRESS.md §Phase 4.1
 */
class TagCreativesWithAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BATCH_SIZE = 20;

    public int $timeout = 300;
    public int $tries   = 2;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        private readonly int $workspaceId,
    ) {
        $this->onQueue('low');
    }

    public function handle(AiCreativeTaggerService $tagger): void
    {
        $workspace = Workspace::withoutGlobalScopes()->find($this->workspaceId);

        if ($workspace === null) {
            Log::warning('TagCreativesWithAiJob: workspace not found', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        $isFrozen = $workspace->trial_ends_at !== null
            && $workspace->trial_ends_at->lt(now())
            && $workspace->billing_plan === null;

        if ($isFrozen) {
            Log::info('TagCreativesWithAiJob: skipped — workspace trial expired', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        $hasAds = AdAccount::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->where('status', 'active')
            ->exists();

        if (! $hasAds) {
            Log::info('TagCreativesWithAiJob: no active ad accounts, skipping', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        // ---------------------------------------------------------------
        // Build taxonomy lookup: category_slug → ['id' => ..., 'tags' => [slug => tag_id]]
        // ---------------------------------------------------------------
        $categories = CreativeTagCategory::with('tags')->get();

        /** @var array<string, array{category_id: int, tags: array<string, int>}> $taxonomy */
        $taxonomy = [];
        foreach ($categories as $cat) {
            $tagMap = [];
            foreach ($cat->tags as $tag) {
                $tagMap[$tag->name] = $tag->id;
            }
            $taxonomy[$cat->name] = [
                'category_id' => $cat->id,
                'tags'        => $tagMap,
            ];
        }

        // Allowed slugs per category for the AI prompt.
        $allowedSlugs = array_map(
            fn (array $cat) => array_keys($cat['tags']),
            $taxonomy,
        );

        // ---------------------------------------------------------------
        // Fetch the next batch of untagged / stale ads
        // ---------------------------------------------------------------
        $staleThreshold = Carbon::now()->subDays(30)->toDateTimeString();

        $ads = DB::select("
            SELECT
                ads.id,
                ads.name,
                ads.effective_status,
                ads.creative_data,
                c.name   AS campaign_name,
                aa.platform,
                COALESCE(SUM(ai.spend_in_reporting_currency), 0) AS total_spend,
                COALESCE(SUM(ai.impressions), 0)                 AS total_impressions,
                COALESCE(SUM(ai.clicks), 0)                      AS total_clicks,
                COALESCE(SUM(
                    CASE WHEN ai.raw_insights IS NOT NULL
                         AND (ai.raw_insights->'video_3_sec_watched_actions') IS NOT NULL
                    THEN (
                        SELECT COALESCE(SUM((elem->>'value')::numeric), 0)
                        FROM jsonb_array_elements(ai.raw_insights->'video_3_sec_watched_actions') AS elem
                    ) ELSE 0 END
                ), 0) AS video_3s_plays,
                COALESCE(SUM(
                    CASE WHEN ai.raw_insights IS NOT NULL
                         AND (ai.raw_insights->'video_15_sec_watched_actions') IS NOT NULL
                    THEN (
                        SELECT COALESCE(SUM((elem->>'value')::numeric), 0)
                        FROM jsonb_array_elements(ai.raw_insights->'video_15_sec_watched_actions') AS elem
                    ) ELSE 0 END
                ), 0) AS video_15s_plays
            FROM ads
            LEFT JOIN adsets a     ON a.id = ads.adset_id
            LEFT JOIN campaigns c  ON c.id = a.campaign_id
            LEFT JOIN ad_accounts aa ON aa.workspace_id = ads.workspace_id
            LEFT JOIN ad_insights ai ON ai.ad_id = ads.id
                AND ai.workspace_id = ads.workspace_id
                AND ai.level = 'ad'
                AND ai.hour IS NULL
                AND ai.date >= (CURRENT_DATE - INTERVAL '30 days')
            WHERE ads.workspace_id = ?
              AND ads.effective_status IN ('ACTIVE', 'PAUSED')
              AND NOT EXISTS (
                  SELECT 1 FROM ad_creative_tags act
                  WHERE act.ad_id = ads.id
                    AND act.tagged_at > ?
              )
            GROUP BY ads.id, ads.name, ads.effective_status, ads.creative_data,
                     c.name, aa.platform
            ORDER BY total_spend DESC
            LIMIT ?
        ", [$this->workspaceId, $staleThreshold, self::BATCH_SIZE + 1]);

        $hasMore = count($ads) > self::BATCH_SIZE;
        $batch   = array_slice($ads, 0, self::BATCH_SIZE);

        if (empty($batch)) {
            Log::info('TagCreativesWithAiJob: no untagged ads, nothing to do', [
                'workspace_id' => $this->workspaceId,
            ]);
            return;
        }

        // ---------------------------------------------------------------
        // Classify each ad
        // ---------------------------------------------------------------
        $tagged  = 0;
        $skipped = 0;
        $errors  = 0;
        $now     = now()->toDateTimeString();

        foreach ($batch as $row) {
            $creative = is_string($row->creative_data)
                ? json_decode($row->creative_data, true)
                : $row->creative_data;

            $adData = [
                'name'           => $row->name,
                'headline'       => $creative['title'] ?? null,
                'platform'       => $row->platform ?? 'unknown',
                'campaign_name'  => $row->campaign_name ?? 'unknown',
                'thumbnail_url'  => $creative['thumbnail_url'] ?? null,
                'spend'          => (float) $row->total_spend,
                'impressions'    => (int)   $row->total_impressions,
                'clicks'         => (int)   $row->total_clicks,
                'video_3s_plays' => (int)   $row->video_3s_plays,
                'video_15s_plays'=> (int)   $row->video_15s_plays,
            ];

            try {
                $tagMap = $tagger->classify($adData, $allowedSlugs);
            } catch (RuntimeException $e) {
                Log::warning('TagCreativesWithAiJob: classify failed for ad', [
                    'workspace_id' => $this->workspaceId,
                    'ad_id'        => $row->id,
                    'error'        => $e->getMessage(),
                ]);
                $errors++;
                continue;
            }

            // Upsert one row per non-null category assignment.
            $upsertRows = [];
            foreach ($tagMap as $categorySlug => $tagSlug) {
                if ($tagSlug === null) {
                    continue;
                }
                $tagId = $taxonomy[$categorySlug]['tags'][$tagSlug] ?? null;
                if ($tagId === null) {
                    continue; // Slug returned by model doesn't match seeded list — discard.
                }
                $upsertRows[] = [
                    'ad_id'           => (int) $row->id,
                    'creative_tag_id' => $tagId,
                    'confidence'      => 1.0,
                    'source'          => 'ai',
                    'tagged_at'       => $now,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }

            if (! empty($upsertRows)) {
                DB::table('ad_creative_tags')->upsert(
                    $upsertRows,
                    ['ad_id', 'creative_tag_id'],
                    ['confidence', 'source', 'tagged_at', 'updated_at'],
                );
                $tagged++;
            } else {
                $skipped++;
            }
        }

        Log::info('TagCreativesWithAiJob: batch complete', [
            'workspace_id' => $this->workspaceId,
            'tagged'       => $tagged,
            'skipped'      => $skipped,
            'errors'       => $errors,
            'has_more'     => $hasMore,
        ]);

        if ($hasMore) {
            static::dispatch($this->workspaceId)->onQueue('low');
        }
    }
}
