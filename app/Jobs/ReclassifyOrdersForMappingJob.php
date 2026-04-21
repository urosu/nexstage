<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Re-stamps `orders.attribution_last_touch.channel` / `.channel_type` for historical
 * orders whose parsed UTM source+medium matches a newly-created workspace-scoped
 * channel mapping.
 *
 * Dispatched by: ManageController::storeChannelMapping()/updateChannelMapping()
 *                after a workspace adds or updates a row in `channel_mappings`.
 *
 * Matches on the JSONB shape written by AttributionParserService:
 *   attribution_last_touch->>'source' = :source
 *   AND attribution_last_touch->>'medium' = :medium   (or IS NULL when wildcard)
 *
 * Queue: `low` — reclassification is background hygiene, not user-facing latency.
 *
 * @see PLANNING.md section 16.7 (inline classify writes workspace row + re-classifies)
 */
class ReclassifyOrdersForMappingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $source,
        private readonly ?string $medium,
        private readonly string $channelName,
        private readonly string $channelType,
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        // Postgres JSONB update — merge the new channel/channel_type keys into
        // the existing attribution_last_touch object. `||` on jsonb merges right
        // side into left, so existing keys are overwritten.
        //
        // Match condition is case-insensitive on source/medium to mirror
        // ChannelClassifierService (which lowercases both before lookup).
        $mediumClause = $this->medium === null
            ? "(attribution_last_touch->>'medium' IS NULL OR attribution_last_touch->>'medium' = '')"
            : "LOWER(attribution_last_touch->>'medium') = ?";

        $bindings = [
            json_encode([
                'channel'      => $this->channelName,
                'channel_type' => $this->channelType,
            ], JSON_UNESCAPED_UNICODE),
            $this->workspaceId,
            strtolower($this->source),
        ];

        if ($this->medium !== null) {
            $bindings[] = strtolower($this->medium);
        }

        DB::update(
            <<<SQL
                UPDATE orders
                SET attribution_last_touch = attribution_last_touch || ?::jsonb,
                    updated_at = NOW()
                WHERE workspace_id = ?
                  AND attribution_last_touch IS NOT NULL
                  AND LOWER(attribution_last_touch->>'source') = ?
                  AND {$mediumClause}
            SQL,
            $bindings,
        );
    }
}
