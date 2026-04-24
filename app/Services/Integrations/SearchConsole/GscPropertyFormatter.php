<?php

namespace App\Services\Integrations\SearchConsole;

/**
 * Format GSC property URLs for display.
 *
 * Google Search Console properties come in two formats:
 *   - Domain properties:     "sc-domain:example.com"
 *   - URL-prefix properties: "https://www.example.com/"
 *
 * Store raw values in the DB and pass to APIs untouched; use this only for display.
 *
 * @see resources/js/lib/gsc.ts (JavaScript equivalent)
 */
class GscPropertyFormatter
{
    /** Returns a clean, human-readable label for a GSC property URL. */
    public static function format(string $propertyUrl): string
    {
        if (str_starts_with($propertyUrl, 'sc-domain:')) {
            return substr($propertyUrl, strlen('sc-domain:'));
        }
        return preg_replace(['/^https?:\/\//', '/\/$/'], '', $propertyUrl);
    }

    /** Returns the property type based on its URL format. */
    public static function getType(string $propertyUrl): string
    {
        return str_starts_with($propertyUrl, 'sc-domain:') ? 'domain' : 'url_prefix';
    }
}
