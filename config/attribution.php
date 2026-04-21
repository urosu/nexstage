<?php

declare(strict_types=1);

return [
    /*
     * Feature flag: enable the AttributionParserService pipeline in sync jobs.
     *
     * When true, UpsertWooCommerceOrderAction calls AttributionParserService on every
     * order and writes the result to attribution_source, attribution_first_touch,
     * attribution_last_touch, attribution_click_ids, and attribution_parsed_at.
     *
     * Set to false in production until BackfillAttributionDataJob (Phase 1.5 Step 8)
     * has completed for all workspaces. Once backfill is verified, flip to true and
     * deploy. Phase 1.5 Step 14 then switches RevenueAttributionService to read from
     * the new attribution_* columns instead of utm_*.
     *
     * Default: true in dev/test so local syncs immediately populate attribution_* columns.
     *
     * @see PLANNING.md section 6
     */
    'parser_enabled' => (bool) env('ATTRIBUTION_PARSER_ENABLED', true),
];
