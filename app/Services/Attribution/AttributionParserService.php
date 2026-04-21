<?php

declare(strict_types=1);

namespace App\Services\Attribution;

use App\Contracts\AttributionSource;
use App\Models\Order;
use App\ValueObjects\ParsedAttribution;

/**
 * Runs registered attribution sources in priority order and returns the first match.
 *
 * Design rules (PLANNING section 6):
 *   - First-hit-wins: the loop exits immediately when a source returns a non-null result.
 *   - No blending, no cross-source field filling.
 *   - Each source gets the same Order object; they do not share state.
 *   - ChannelClassifierService is called once after the winning source returns,
 *     so sources never deal with channel classification themselves.
 *
 * Sources registered (priority order) in AppServiceProvider:
 *   1. PixelYourSiteSource
 *   2. WooCommerceNativeSource
 *   3. ReferrerHeuristicSource
 *
 * Reads: Order model (utm_* columns, source_type, raw_meta).
 * Writes: nothing — callers write the returned ParsedAttribution to attribution_* columns.
 * Called by: UpsertWooCommerceOrderAction (Step 7), BackfillAttributionDataJob (Step 8).
 *
 * @see PLANNING.md section 6
 */
class AttributionParserService
{
    /**
     * @param AttributionSource[] $sources  Ordered list of sources; first match wins.
     */
    public function __construct(
        private readonly array $sources,
        private readonly ChannelClassifierService $classifier,
    ) {}

    /**
     * Parse attribution for an order and return a normalised result.
     *
     * Always returns a value. When no source matches, returns ParsedAttribution::notTracked().
     * Channel is set via withChannel() immediately after the winning source returns.
     */
    public function parse(Order $order): ParsedAttribution
    {
        foreach ($this->sources as $source) {
            $result = $source->tryParse($order);

            if ($result !== null) {
                return $result->withChannel(
                    $this->classifier->classify(
                        $result->last_touch['source'] ?? null,
                        $result->last_touch['medium'] ?? null,
                        $order->workspace_id,
                    )
                );
            }
        }

        return ParsedAttribution::notTracked();
    }

    /**
     * Run the full parser pipeline and return a trace for each source.
     *
     * Used by /admin/attribution-debug/{order_id} to show every source tried,
     * whether it matched, and what it extracted.
     *
     * @return array<int, array{source: string, matched: bool, result: ParsedAttribution|null}>
     */
    public function debug(Order $order): array
    {
        $pipeline = [];
        $won = false;

        foreach ($this->sources as $source) {
            if ($won) {
                // Remaining sources were skipped (first-hit-wins).
                $pipeline[] = [
                    'source'  => $source::class,
                    'matched' => false,
                    'skipped' => true,
                    'result'  => null,
                ];
                continue;
            }

            $result = $source->tryParse($order);

            if ($result !== null) {
                $result = $result->withChannel(
                    $this->classifier->classify(
                        $result->last_touch['source'] ?? null,
                        $result->last_touch['medium'] ?? null,
                        $order->workspace_id,
                    )
                );
                $won = true;
            }

            $pipeline[] = [
                'source'  => $source::class,
                'matched' => $result !== null,
                'skipped' => false,
                'result'  => $result,
            ];
        }

        return $pipeline;
    }
}
