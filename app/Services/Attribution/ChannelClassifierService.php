<?php

declare(strict_types=1);

namespace App\Services\Attribution;

use App\Models\ChannelMapping;
use Illuminate\Support\Facades\Cache;

/**
 * Classifies a (utm_source, utm_medium) pair into a named channel and channel type.
 *
 * Lookup order (first match wins):
 *   1. Workspace row, exact medium
 *   2. Workspace row, NULL medium (wildcard — matches any medium)
 *   3. Global row,    exact medium  — may use PCRE regex on utm_source_pattern
 *   4. Global row,    NULL medium   — may use PCRE regex on utm_source_pattern
 *
 * All rows for a workspace (global + workspace-specific) are loaded from Redis on
 * the first classify() call, then cached for 60 minutes under
 * "channel_mappings.{workspaceId}". Cache is invalidated by ManageController
 * whenever workspace rows are saved or deleted, and by the global re-seed path
 * for all workspaces.
 *
 * Regex rows (is_regex=true): only used on global seed rows. utm_source_pattern is
 * a PCRE pattern without delimiters; the classifier wraps it as /^{pattern}$/i.
 * Workspace override rows always have is_regex=false, so the UI needs no changes.
 *
 * Reads: channel_mappings (seeded by ChannelMappingsSeeder, overridden by workspace rows).
 * Called by: AttributionParserService::parse() → withChannel().
 *
 * @see PLANNING.md section 16.4
 */
class ChannelClassifierService
{
    private const CACHE_TTL = 3600; // 60 minutes

    /** Shared cache key for global seed rows (workspace_id IS NULL), loaded once for all workspaces. */
    public const GLOBAL_CACHE_KEY = 'channel_mappings.global';

    public static function cacheKey(int $workspaceId): string
    {
        return "channel_mappings.workspace.{$workspaceId}";
    }

    /**
     * Classify a utm_source + utm_medium pair.
     *
     * @return array{channel_name: string|null, channel_type: string|null}
     */
    public function classify(?string $utmSource, ?string $utmMedium, int $workspaceId): array
    {
        if ($utmSource === null || $utmSource === '') {
            return ['channel_name' => null, 'channel_type' => null];
        }

        $source = strtolower(trim($utmSource));
        $medium = ($utmMedium !== null && $utmMedium !== '')
            ? strtolower(trim($utmMedium))
            : null;

        $mappings = $this->loadMappings($workspaceId);
        $match    = $this->findMatch($mappings, $source, $medium, $workspaceId);

        if ($match === null) {
            return ['channel_name' => null, 'channel_type' => 'other'];
        }

        return [
            'channel_name' => $match['channel_name'],
            'channel_type' => $match['channel_type'],
        ];
    }

    /**
     * Load all rows (global + workspace-specific) from cache, or query DB on miss.
     *
     * Global rows (~40 seed rows, shared across all workspaces) are cached once under
     * GLOBAL_CACHE_KEY. Workspace override rows are cached separately per workspace.
     * This prevents ~40 duplicated cache entries per workspace on multi-tenant installs.
     *
     * @return array<int, array{workspace_id: int|null, utm_source_pattern: string, utm_medium_pattern: string|null, channel_name: string, channel_type: string, is_regex: bool}>
     */
    private function loadMappings(int $workspaceId): array
    {
        $globalRows    = $this->loadGlobalMappings();
        $workspaceRows = $this->loadWorkspaceMappings($workspaceId);

        return array_merge($globalRows, $workspaceRows);
    }

    /** @return array<int, array> */
    private function loadGlobalMappings(): array
    {
        $cached = Cache::get(self::GLOBAL_CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        $rows = ChannelMapping::whereNull('workspace_id')
            ->get(['workspace_id', 'utm_source_pattern', 'utm_medium_pattern',
                   'channel_name', 'channel_type', 'is_regex'])
            ->map(fn (ChannelMapping $m) => [
                'workspace_id'       => null,
                'utm_source_pattern' => $m->utm_source_pattern,
                'utm_medium_pattern' => $m->utm_medium_pattern,
                'channel_name'       => $m->channel_name,
                'channel_type'       => $m->channel_type,
                'is_regex'           => (bool) $m->is_regex,
            ])
            ->all();

        Cache::put(self::GLOBAL_CACHE_KEY, $rows, self::CACHE_TTL);

        return $rows;
    }

    /** @return array<int, array> */
    private function loadWorkspaceMappings(int $workspaceId): array
    {
        $cacheKey = self::cacheKey($workspaceId);
        $cached   = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $rows = ChannelMapping::where('workspace_id', $workspaceId)
            ->get(['workspace_id', 'utm_source_pattern', 'utm_medium_pattern',
                   'channel_name', 'channel_type', 'is_regex'])
            ->map(fn (ChannelMapping $m) => [
                'workspace_id'       => $m->workspace_id,
                'utm_source_pattern' => $m->utm_source_pattern,
                'utm_medium_pattern' => $m->utm_medium_pattern,
                'channel_name'       => $m->channel_name,
                'channel_type'       => $m->channel_type,
                'is_regex'           => (bool) $m->is_regex,
            ])
            ->all();

        Cache::put($cacheKey, $rows, self::CACHE_TTL);

        return $rows;
    }

    /**
     * Iterate mappings in explicit priority order and return the first match.
     *
     * Pre-partitions rows into four buckets (O(n), n ≈ 80-100 rows) and searches
     * each tier in order. Within a tier, the first matching row wins — seeder
     * insert order is stable, so literal rows placed before regex rows take priority
     * when both would match the same input.
     *
     * @param array<int, array> $mappings
     * @return array{channel_name: string, channel_type: string}|null
     */
    private function findMatch(array $mappings, string $source, ?string $medium, int $workspaceId): ?array
    {
        $wsExact    = [];
        $wsWildcard = [];
        $gbExact    = [];
        $gbWildcard = [];

        foreach ($mappings as $row) {
            $isWorkspace    = ($row['workspace_id'] === $workspaceId);
            $hasExactMedium = ($row['utm_medium_pattern'] !== null);

            if ($isWorkspace && $hasExactMedium) {
                $wsExact[] = $row;
            } elseif ($isWorkspace) {
                $wsWildcard[] = $row;
            } elseif ($hasExactMedium) {
                $gbExact[] = $row;
            } else {
                $gbWildcard[] = $row;
            }
        }

        // Tier 1: workspace row + exact medium
        if ($medium !== null) {
            foreach ($wsExact as $row) {
                if ($row['utm_source_pattern'] === $source && $row['utm_medium_pattern'] === $medium) {
                    return $row;
                }
            }
        }

        // Tier 2: workspace row + wildcard (any medium)
        foreach ($wsWildcard as $row) {
            if ($row['utm_source_pattern'] === $source) {
                return $row;
            }
        }

        // Tier 3: global row + exact medium (may use regex on source)
        if ($medium !== null) {
            foreach ($gbExact as $row) {
                if ($row['utm_medium_pattern'] === $medium && $this->sourceMatches($row, $source)) {
                    return $row;
                }
            }
        }

        // Tier 4: global row + wildcard (may use regex on source)
        foreach ($gbWildcard as $row) {
            if ($this->sourceMatches($row, $source)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Test whether a mapping row's source pattern matches $source.
     *
     * For is_regex=true rows the stored pattern is a PCRE pattern without
     * delimiters (e.g. "google\.[a-z]{2,3}"). We anchor it with ^…$ so
     * "mygoogle.de" cannot match the Google TLD pattern.
     * The @ suppresses warnings for malformed patterns; the seeder validates
     * patterns at seed time so this is defence-in-depth only.
     */
    private function sourceMatches(array $row, string $source): bool
    {
        if ($row['is_regex']) {
            return (bool) @preg_match('/^' . $row['utm_source_pattern'] . '$/i', $source);
        }

        return $row['utm_source_pattern'] === $source;
    }
}
