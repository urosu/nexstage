<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Parses campaign names using the fixed `|` separator naming convention.
 *
 * Three supported shapes:
 *   - Full:    `{country} | {campaign} | {target}`
 *   - Partial: `{campaign} | {target}`
 *   - Minimal: `{campaign}`
 *
 * Country detection: first field is exactly 2 uppercase ASCII letters.
 * Target matching: product slug → category slug → raw fallback.
 *
 * Called on every campaign upsert during ad sync. Writes to `campaigns.parsed_convention` JSONB.
 *
 * @see PLANNING.md section 16.3
 */
class CampaignNameParserService
{
    /**
     * Parse a campaign name into a structured convention array.
     *
     * @param  string  $name         The campaign name to parse.
     * @param  int     $workspaceId  Used for product/category slug lookups.
     * @return array{country: ?string, campaign: string, target_type: ?string, target_id: ?int, target_slug: ?string, raw_target: ?string, shape: string, parse_status: string}
     */
    public function parse(string $name, int $workspaceId): array
    {
        $fields = array_map('trim', explode('|', $name));
        $fields = array_values(array_filter($fields, fn (string $f) => $f !== ''));

        if (count($fields) === 0) {
            return $this->buildResult(
                country: null,
                campaign: $name,
                targetType: null,
                targetId: null,
                targetSlug: null,
                rawTarget: null,
                shape: 'minimal',
                parseStatus: 'minimal',
            );
        }

        $country    = null;
        $campaign   = null;
        $rawTarget  = null;

        if (count($fields) >= 3 && $this->isCountryCode($fields[0])) {
            // Full shape: country | campaign | target
            $country   = strtoupper($fields[0]);
            $campaign  = $fields[1];
            $rawTarget = $fields[2];
        } elseif (count($fields) === 2) {
            if ($this->isCountryCode($fields[0])) {
                // Two fields where first is a country code — treat as country | campaign (no target)
                $country  = strtoupper($fields[0]);
                $campaign = $fields[1];
            } else {
                // campaign | target
                $campaign  = $fields[0];
                $rawTarget = $fields[1];
            }
        } elseif (count($fields) >= 3 && ! $this->isCountryCode($fields[0])) {
            // Three+ fields but first isn't a country — treat as campaign | target (extras ignored)
            $campaign  = $fields[0];
            $rawTarget = $fields[1];
        } else {
            // Single field
            $campaign = $fields[0];
        }

        // Determine shape
        $shape = 'minimal';
        if ($country !== null && $rawTarget !== null) {
            $shape = 'full';
        } elseif ($rawTarget !== null) {
            $shape = 'campaign_target';
        } elseif ($country !== null) {
            $shape = 'campaign_target'; // country + campaign but no target
        }

        // Match target against products and categories
        $targetType = null;
        $targetId   = null;
        $targetSlug = null;
        $parseStatus = $rawTarget !== null ? 'partial' : 'minimal';

        if ($rawTarget !== null) {
            $slug = $this->normalizeSlug($rawTarget);

            // 1. Try product slug
            $product = Product::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('slug', $slug)
                ->select(['id', 'slug'])
                ->first();

            if ($product !== null) {
                $targetType  = 'product';
                $targetId    = $product->id;
                $targetSlug  = $product->slug;
                $parseStatus = 'clean';
            } else {
                // 2. Try category slug
                $category = ProductCategory::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->where('slug', $slug)
                    ->select(['id', 'slug'])
                    ->first();

                if ($category !== null) {
                    $targetType  = 'category';
                    $targetId    = $category->id;
                    $targetSlug  = $category->slug;
                    $parseStatus = 'clean';
                }
                // 3. Raw fallback — stays partial
            }
        }

        return $this->buildResult(
            country: $country,
            campaign: $campaign ?? $name,
            targetType: $targetType,
            targetId: $targetId,
            targetSlug: $targetSlug,
            rawTarget: $rawTarget,
            shape: $shape,
            parseStatus: $parseStatus,
        );
    }

    /**
     * Parse and write parsed_convention for a Campaign model.
     */
    public function parseAndSave(\App\Models\Campaign $campaign): array
    {
        $result = $this->parse($campaign->name, $campaign->workspace_id);
        $campaign->update(['parsed_convention' => $result]);

        return $result;
    }

    /**
     * Check if a string looks like an ISO 3166-1 alpha-2 country code.
     * Exactly 2 uppercase ASCII letters.
     */
    private function isCountryCode(string $value): bool
    {
        return preg_match('/^[A-Z]{2}$/', $value) === 1;
    }

    /**
     * Normalize a target field into a slug-like string for matching.
     * Str::slug uses the same ASCII transliteration as WooCommerce's sanitize_title(),
     * so "München" → "munchen" matches the slug stored for a product named "München".
     */
    private function normalizeSlug(string $value): string
    {
        return Str::slug($value);
    }

    /**
     * @return array{country: ?string, campaign: string, target_type: ?string, target_id: ?int, target_slug: ?string, raw_target: ?string, shape: string, parse_status: string}
     */
    private function buildResult(
        ?string $country,
        string $campaign,
        ?string $targetType,
        ?int $targetId,
        ?string $targetSlug,
        ?string $rawTarget,
        string $shape,
        string $parseStatus,
    ): array {
        return [
            'country'      => $country,
            'campaign'     => $campaign,
            'target_type'  => $targetType,
            'target_id'    => $targetId,
            'target_slug'  => $targetSlug,
            'raw_target'   => $rawTarget,
            'shape'        => $shape,
            'parse_status' => $parseStatus,
        ];
    }
}
