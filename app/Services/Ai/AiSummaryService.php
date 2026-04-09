<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Calls the Anthropic Messages API to generate a daily store performance summary.
 *
 * The caller is responsible for providing a ready-to-send payload array.
 * This service only handles the HTTP call and response extraction.
 */
class AiSummaryService
{
    private const SYSTEM_PROMPT = 'You are a senior ecommerce analyst reviewing daily store performance. Be concise and direct. Highlight the single most important change, flag anomalies, give one actionable recommendation. 3–4 short paragraphs, plain business English, no bullet points, no generic filler.';

    /**
     * Generate a daily summary for the given data payload.
     *
     * @param  array<string, mixed>  $data  Pre-assembled metrics for yesterday,
     *                                      day-before, and same-weekday-last-week.
     * @return array{text: string, model: string}
     *
     * @throws RuntimeException When the API call fails or returns an unexpected structure.
     */
    public function generate(array $data): array
    {
        $model     = (string) env('ANTHROPIC_MODEL', 'claude-sonnet-4-6');
        $apiKey    = (string) config('services.anthropic.key', env('ANTHROPIC_API_KEY', ''));

        $userMessage = $this->buildUserMessage($data);

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => 600,
            'system'     => self::SYSTEM_PROMPT,
            'messages'   => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ]);

        if (! $response->successful()) {
            Log::error('AiSummaryService: API request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException(
                "Anthropic API returned HTTP {$response->status()}: {$response->body()}"
            );
        }

        $body = $response->json();

        $text = $body['content'][0]['text'] ?? null;

        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Anthropic API returned an empty or unexpected response structure.');
        }

        return [
            'text'  => $text,
            'model' => $model,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert the metrics array into a plain-English prompt for the model.
     *
     * @param  array<string, mixed>  $data
     */
    private function buildUserMessage(array $data): string
    {
        $workspace = $data['workspace'] ?? [];
        $currency  = $workspace['reporting_currency'] ?? 'EUR';
        $name      = $workspace['name'] ?? 'this store';

        $lines   = [];
        $lines[] = "Store: {$name} | Reporting currency: {$currency}";
        $lines[] = '';

        foreach (['yesterday', 'day_before', 'same_weekday_last_week'] as $key) {
            $day = $data['days'][$key] ?? null;
            if (! is_array($day)) {
                continue;
            }

            $label = match ($key) {
                'yesterday'              => 'Yesterday',
                'day_before'             => 'Day before yesterday',
                'same_weekday_last_week' => 'Same weekday last week',
            };

            $lines[] = "{$label} ({$day['date']}):";
            $lines[] = "  Revenue: {$day['revenue']} {$currency}";
            $lines[] = "  Orders: {$day['orders_count']}";
            $lines[] = "  AOV: " . ($day['aov'] !== null ? "{$day['aov']} {$currency}" : 'N/A');
            $lines[] = "  New customers: {$day['new_customers']}";
            $lines[] = "  Returning customers: {$day['returning_customers']}";

            if (isset($day['ad_spend']) && $day['ad_spend'] !== null) {
                $lines[] = "  Ad spend: {$day['ad_spend']} {$currency}";
                $roas    = $day['roas'] ?? null;
                $lines[] = "  ROAS: " . ($roas !== null ? (string) $roas : 'N/A');
            }
            $lines[] = '';
        }

        if (isset($data['gsc']) && is_array($data['gsc'])) {
            $lines[] = 'Google Search Console:';
            foreach (['yesterday', 'day_before', 'same_weekday_last_week'] as $key) {
                $gsc = $data['gsc'][$key] ?? null;
                if (! is_array($gsc)) {
                    continue;
                }
                $label   = match ($key) {
                    'yesterday'              => 'Yesterday',
                    'day_before'             => 'Day before yesterday',
                    'same_weekday_last_week' => 'Same weekday last week',
                };
                $lines[] = "  {$label} ({$gsc['date']}): clicks={$gsc['clicks']}, impressions={$gsc['impressions']}, position={$gsc['position']}";
            }
            $lines[] = '';
        }

        $lines[] = 'Please review the above metrics and provide a brief analysis.';

        return implode("\n", $lines);
    }
}
