<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * Normalised attribution record produced by AttributionParserService.
 *
 * Plain PHP value object — no Eloquent, no DB interaction.
 *
 * source_type values: pys / wc_native / referrer / none
 *   (shopify_journey / shopify_landing added in Phase 2)
 *
 * Touch shape: {source, medium, campaign, content, term, landing_page}
 *   All fields optional; only non-null values are present.
 *   For single-touch sources (WC native, referrer) first_touch === last_touch.
 *
 * click_ids shape: {fbc, fbp, gclid, msclkid}  — Phase 4 CAPI enabler.
 *
 * channel / channel_type: set by AttributionParserService via withChannel()
 *   after the source loop. NULL until withChannel() is called.
 *
 * raw_data: optional debug blob included by sources for the debug route.
 *   Never written to the DB.
 *
 * @see PLANNING.md section 6
 */
final class ParsedAttribution
{
    public function __construct(
        public readonly string  $source_type,
        public readonly ?array  $first_touch,
        public readonly ?array  $last_touch,
        public readonly ?array  $click_ids,
        public readonly ?string $channel,
        public readonly ?string $channel_type,
        public readonly ?array  $raw_data,
    ) {}

    /**
     * Return a copy with channel_name and channel_type set from the classifier result.
     *
     * Called by AttributionParserService immediately after a source returns a match.
     *
     * @param array{channel_name: string|null, channel_type: string|null} $channelResult
     */
    public function withChannel(array $channelResult): self
    {
        return new self(
            source_type:  $this->source_type,
            first_touch:  $this->first_touch,
            last_touch:   $this->last_touch,
            click_ids:    $this->click_ids,
            channel:      $channelResult['channel_name'],
            channel_type: $channelResult['channel_type'],
            raw_data:     $this->raw_data,
        );
    }

    /**
     * Canonical "not tracked" result returned when no source matches.
     *
     * Corresponds to "Not Tracked" terminology across the UI.
     * @see PLANNING.md section 13
     */
    public static function notTracked(): self
    {
        return new self(
            source_type:  'none',
            first_touch:  null,
            last_touch:   null,
            click_ids:    null,
            channel:      null,
            channel_type: null,
            raw_data:     null,
        );
    }

    /**
     * True when this result represents untracked/unattributable traffic.
     */
    public function isNotTracked(): bool
    {
        return $this->source_type === 'none';
    }

    /**
     * Serialise to the array written to orders.attribution_last_touch / first_touch JSONB.
     *
     * Channel is included in the JSONB so Step 14 reads don't need to re-classify.
     */
    public function toTouchArray(?array $touch): ?array
    {
        if ($touch === null) {
            return null;
        }

        $result = $touch;

        if ($this->channel !== null) {
            $result['channel']      = $this->channel;
            $result['channel_type'] = $this->channel_type;
        }

        return $result;
    }
}
