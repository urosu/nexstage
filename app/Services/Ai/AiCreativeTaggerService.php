<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Calls the Anthropic Messages API to classify a single ad creative into the
 * pre-seeded taxonomy (creative_tag_categories + creative_tags).
 *
 * The caller provides the ad's metadata and the allowed tag slugs per category.
 * This service builds a multimodal message (image + text when thumbnail_url is
 * present) and returns a map of category_slug → tag_slug (null when the model
 * abstains from a category).
 *
 * Classification is constrained: the prompt enumerates exact allowed slugs and
 * instructs the model not to invent values. Any value not in the allowed list is
 * treated as null by the caller.
 *
 * @see app/Jobs/TagCreativesWithAiJob.php
 * @see PROGRESS.md §Phase 4.1
 */
class AiCreativeTaggerService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a creative analyst classifying ad creatives for ecommerce brands.

For each taxonomy category, return exactly one slug from the allowed list, or null if none clearly applies. Return only valid JSON — no markdown, no explanation, no keys outside the taxonomy.

Rules:
- Only use slugs from the allowed lists below.
- Do not invent new slugs.
- Return null when genuinely unsure, not a guess.
- The JSON must have exactly one key per category listed.
PROMPT;

    /**
     * Classify a single ad creative into the taxonomy.
     *
     * @param  array<string, mixed>  $ad       Keys: name, headline, spend, impressions,
     *                                          clicks, thumbnail_url, video_3s_plays,
     *                                          video_15s_plays, platform, campaign_name
     * @param  array<string, list<string>>  $taxonomy  category_slug → list of allowed tag slugs
     * @return array<string, string|null>  category_slug → matched tag_slug or null
     *
     * @throws RuntimeException On unrecoverable API failure.
     */
    public function classify(array $ad, array $taxonomy): array
    {
        $model  = (string) env('ANTHROPIC_MODEL', 'claude-sonnet-4-6');
        $apiKey = (string) config('services.anthropic.key', env('ANTHROPIC_API_KEY', ''));

        $content = $this->buildContent($ad, $taxonomy);

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => 256,
            'system'     => self::SYSTEM_PROMPT,
            'messages'   => [
                ['role' => 'user', 'content' => $content],
            ],
        ]);

        if (! $response->successful()) {
            Log::error('AiCreativeTaggerService: API request failed', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'ad_name' => $ad['name'] ?? null,
            ]);
            throw new RuntimeException(
                "Anthropic API returned HTTP {$response->status()}: {$response->body()}"
            );
        }

        $body = $response->json();
        $text = $body['content'][0]['text'] ?? null;

        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Anthropic API returned an empty or unexpected response.');
        }

        return $this->parseAndValidate($text, $taxonomy);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the multimodal message content blocks.
     * Prepends an image block when thumbnail_url is available.
     *
     * @param  array<string, mixed>  $ad
     * @param  array<string, list<string>>  $taxonomy
     * @return list<array<string, mixed>>
     */
    private function buildContent(array $ad, array $taxonomy): array
    {
        $blocks = [];

        $thumbnailUrl = $ad['thumbnail_url'] ?? null;
        if (is_string($thumbnailUrl) && $thumbnailUrl !== '') {
            $blocks[] = [
                'type'   => 'image',
                'source' => ['type' => 'url', 'url' => $thumbnailUrl],
            ];
        }

        $blocks[] = [
            'type' => 'text',
            'text' => $this->buildTextBlock($ad, $taxonomy),
        ];

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $ad
     * @param  array<string, list<string>>  $taxonomy
     */
    private function buildTextBlock(array $ad, array $taxonomy): string
    {
        $lines = [];

        $lines[] = 'Ad creative details:';
        $lines[] = '  Name:          ' . ($ad['name'] ?? 'unknown');
        $lines[] = '  Headline:      ' . ($ad['headline'] ?? 'none');
        $lines[] = '  Platform:      ' . ($ad['platform'] ?? 'unknown');
        $lines[] = '  Campaign:      ' . ($ad['campaign_name'] ?? 'unknown');
        $lines[] = '  Spend (range): ' . round((float) ($ad['spend'] ?? 0), 2);
        $lines[] = '  Impressions:   ' . ($ad['impressions'] ?? 0);
        $lines[] = '  Clicks:        ' . ($ad['clicks'] ?? 0);

        $v3s  = $ad['video_3s_plays'] ?? 0;
        $v15s = $ad['video_15s_plays'] ?? 0;
        if ($v3s > 0) {
            $lines[] = '  Video 3s plays:  ' . $v3s;
            $lines[] = '  Video 15s plays: ' . $v15s;
        }

        if (! ($ad['thumbnail_url'] ?? null)) {
            $lines[] = '  (No thumbnail available — classify from text only)';
        }

        $lines[] = '';
        $lines[] = 'Classify into each category using exactly one slug from the allowed list, or null:';
        $lines[] = '';

        foreach ($taxonomy as $categorySlug => $allowedSlugs) {
            $slugList = implode(', ', $allowedSlugs);
            $lines[]  = "  {$categorySlug}: [{$slugList}]";
        }

        $lines[] = '';
        $lines[] = 'Return JSON only. Example: {"asset_type":"video","visual_format":"ugc",...}';

        return implode("\n", $lines);
    }

    /**
     * Parse the model's JSON response and discard any slugs not in the allowed list.
     *
     * @param  array<string, list<string>>  $taxonomy
     * @return array<string, string|null>
     */
    private function parseAndValidate(string $rawText, array $taxonomy): array
    {
        // Strip markdown fences if the model adds them despite instructions.
        $json = preg_replace('/^```(?:json)?\s*/i', '', trim($rawText));
        $json = preg_replace('/\s*```$/', '', $json ?? $rawText);

        $decoded = json_decode((string) $json, true);

        if (! is_array($decoded)) {
            Log::warning('AiCreativeTaggerService: could not parse JSON response', [
                'raw' => $rawText,
            ]);
            // Return all-null so the caller skips this ad gracefully.
            return array_fill_keys(array_keys($taxonomy), null);
        }

        $result = [];
        foreach ($taxonomy as $categorySlug => $allowedSlugs) {
            $value = $decoded[$categorySlug] ?? null;
            // Accept only valid slugs from the seeded list.
            $result[$categorySlug] = (is_string($value) && in_array($value, $allowedSlugs, true))
                ? $value
                : null;
        }

        return $result;
    }
}
