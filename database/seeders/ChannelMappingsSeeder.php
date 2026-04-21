<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds global channel mappings used by ChannelClassifierService.
 *
 * These rows map (utm_source, utm_medium) pairs to named channels and channel types.
 * Workspace-scoped overrides are created via the admin UI, not this seeder.
 *
 * is_regex=true rows: utm_source_pattern is a PCRE pattern without delimiters.
 * ChannelClassifierService wraps it as /^…$/i. Only global seed rows use regex;
 * workspace override rows always have is_regex=false.
 *
 * Ordering matters: ChannelClassifierService iterates rows in DB load order within
 * each priority tier. Place literal rows before regex rows so literals win when both
 * would match the same input (e.g. "google.com" before the Google TLD regex).
 *
 * Safe to re-run: deletes all global rows first, then bulk-inserts.
 *
 * @see PLANNING.md section 16.4
 */
class ChannelMappingsSeeder extends Seeder
{
    public function run(): void
    {
        // Clear existing global seed rows (preserves workspace overrides)
        DB::table('channel_mappings')
            ->whereNull('workspace_id')
            ->where('is_global', true)
            ->delete();

        $now = now()->toDateTimeString();

        $rows = [
            // ── Email ──────────────────────────────────────────────────────────
            ['utm_source_pattern' => 'klaviyo',         'utm_medium_pattern' => 'email',     'channel_name' => 'Email — Klaviyo',          'channel_type' => 'email'],
            ['utm_source_pattern' => 'mailchimp',       'utm_medium_pattern' => 'email',     'channel_name' => 'Email — Mailchimp',         'channel_type' => 'email'],
            ['utm_source_pattern' => 'omnisend',        'utm_medium_pattern' => 'email',     'channel_name' => 'Email — Omnisend',          'channel_type' => 'email'],
            ['utm_source_pattern' => 'activecampaign',  'utm_medium_pattern' => 'email',     'channel_name' => 'Email — ActiveCampaign',    'channel_type' => 'email'],
            ['utm_source_pattern' => 'brevo',           'utm_medium_pattern' => 'email',     'channel_name' => 'Email — Brevo',             'channel_type' => 'email'],
            ['utm_source_pattern' => 'convertkit',      'utm_medium_pattern' => 'email',     'channel_name' => 'Email — ConvertKit',        'channel_type' => 'email'],
            ['utm_source_pattern' => 'sendinblue',      'utm_medium_pattern' => 'email',     'channel_name' => 'Email — Sendinblue',        'channel_type' => 'email'],
            ['utm_source_pattern' => 'hubspot',         'utm_medium_pattern' => 'email',     'channel_name' => 'Email — HubSpot',           'channel_type' => 'email'],

            // ── Newsletter / Email marketing (generic source) ──────────────────
            // "newsletter" is a common utm_source for stores without a dedicated ESP.
            ['utm_source_pattern' => 'newsletter',      'utm_medium_pattern' => 'email',     'channel_name' => 'Email — Newsletter',        'channel_type' => 'email'],
            ['utm_source_pattern' => 'newsletter',      'utm_medium_pattern' => 'cpc',       'channel_name' => 'Email — Newsletter',        'channel_type' => 'email'],
            ['utm_source_pattern' => 'newsletter',      'utm_medium_pattern' => 'referral',  'channel_name' => 'Email — Newsletter',        'channel_type' => 'email'],
            ['utm_source_pattern' => 'newsletter',      'utm_medium_pattern' => 'organic',   'channel_name' => 'Email — Newsletter',        'channel_type' => 'email'],
            ['utm_source_pattern' => 'newsletter',      'utm_medium_pattern' => 'social',    'channel_name' => 'Email — Newsletter',        'channel_type' => 'email'],
            ['utm_source_pattern' => 'newsletter',      'utm_medium_pattern' => null,        'channel_name' => 'Email — Newsletter',        'channel_type' => 'email'],

            // ── Paid Social ────────────────────────────────────────────────────
            // "cpc" is the GA4 convention; "paid" is common in Facebook/Meta URL templates.
            ['utm_source_pattern' => 'facebook',        'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Facebook',           'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'facebook',        'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — Facebook',           'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'facebook',        'utm_medium_pattern' => 'paidsocial','channel_name' => 'Paid — Facebook',           'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'meta',            'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Facebook',           'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'meta',            'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — Facebook',           'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'fb',              'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Facebook',           'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'fb',              'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — Facebook',           'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'instagram',       'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Instagram',          'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'instagram',       'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — Instagram',          'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'ig',              'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Instagram',          'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'ig',              'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — Instagram',          'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'tiktok',          'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — TikTok',             'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'tiktok',          'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — TikTok',             'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'linkedin',        'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — LinkedIn',           'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'linkedin',        'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — LinkedIn',           'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'pinterest',       'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Pinterest',          'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'pinterest',       'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — Pinterest',          'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'twitter',         'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Twitter/X',          'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'twitter',         'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — Twitter/X',          'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'x',               'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Twitter/X',          'channel_type' => 'paid_social'],
            ['utm_source_pattern' => 'x',               'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — Twitter/X',          'channel_type' => 'paid_social'],

            // ── Paid Search ────────────────────────────────────────────────────
            ['utm_source_pattern' => 'google',          'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Google Ads',         'channel_type' => 'paid_search'],
            ['utm_source_pattern' => 'google',          'utm_medium_pattern' => 'ppc',       'channel_name' => 'Paid — Google Ads',         'channel_type' => 'paid_search'],
            ['utm_source_pattern' => 'google',          'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — Google Ads',         'channel_type' => 'paid_search'],
            ['utm_source_pattern' => 'adwords',         'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Google Ads',         'channel_type' => 'paid_search'],
            ['utm_source_pattern' => 'bing',            'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Microsoft Ads',      'channel_type' => 'paid_search'],
            ['utm_source_pattern' => 'bing',            'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — Microsoft Ads',      'channel_type' => 'paid_search'],
            ['utm_source_pattern' => 'microsoft',       'utm_medium_pattern' => 'cpc',       'channel_name' => 'Paid — Microsoft Ads',      'channel_type' => 'paid_search'],
            ['utm_source_pattern' => 'microsoft',       'utm_medium_pattern' => 'paid',      'channel_name' => 'Paid — Microsoft Ads',      'channel_type' => 'paid_search'],

            // ── Organic Search ─────────────────────────────────────────────────
            ['utm_source_pattern' => 'google',          'utm_medium_pattern' => 'organic',   'channel_name' => 'Organic — Google',          'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'google',          'utm_medium_pattern' => 'referral',  'channel_name' => 'Organic — Google',          'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'google',          'utm_medium_pattern' => 'email',     'channel_name' => 'Organic — Google',          'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'google',          'utm_medium_pattern' => 'social',    'channel_name' => 'Organic — Google',          'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'bing',            'utm_medium_pattern' => 'organic',   'channel_name' => 'Organic — Bing',            'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'bing',            'utm_medium_pattern' => 'referral',  'channel_name' => 'Organic — Bing',            'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'bing',            'utm_medium_pattern' => 'email',     'channel_name' => 'Organic — Bing',            'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'bing',            'utm_medium_pattern' => 'social',    'channel_name' => 'Organic — Bing',            'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'duckduckgo',      'utm_medium_pattern' => 'organic',   'channel_name' => 'Organic — DuckDuckGo',      'channel_type' => 'organic_search'],

            // ── Organic Social ─────────────────────────────────────────────────
            ['utm_source_pattern' => 'facebook',        'utm_medium_pattern' => 'social',    'channel_name' => 'Social — Facebook',         'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'facebook',        'utm_medium_pattern' => 'organic',   'channel_name' => 'Social — Facebook',         'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'facebook',        'utm_medium_pattern' => 'referral',  'channel_name' => 'Social — Facebook',         'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'facebook',        'utm_medium_pattern' => 'email',     'channel_name' => 'Social — Facebook',         'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'instagram',       'utm_medium_pattern' => 'social',    'channel_name' => 'Social — Instagram',        'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'instagram',       'utm_medium_pattern' => 'organic',   'channel_name' => 'Social — Instagram',        'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'instagram',       'utm_medium_pattern' => 'referral',  'channel_name' => 'Social — Instagram',        'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'instagram',       'utm_medium_pattern' => 'email',     'channel_name' => 'Social — Instagram',        'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'tiktok',          'utm_medium_pattern' => 'organic',   'channel_name' => 'Social — TikTok',           'channel_type' => 'organic_social'],

            // ── SMS ────────────────────────────────────────────────────────────
            ['utm_source_pattern' => 'postscript',      'utm_medium_pattern' => 'sms',       'channel_name' => 'SMS — Postscript',          'channel_type' => 'sms'],
            ['utm_source_pattern' => 'attentive',       'utm_medium_pattern' => 'sms',       'channel_name' => 'SMS — Attentive',           'channel_type' => 'sms'],
            ['utm_source_pattern' => 'klaviyo',         'utm_medium_pattern' => 'sms',       'channel_name' => 'SMS — Klaviyo',             'channel_type' => 'sms'],
            ['utm_source_pattern' => 'klaviyo-sms',     'utm_medium_pattern' => 'sms',       'channel_name' => 'SMS — Klaviyo',             'channel_type' => 'sms'],

            // ── Affiliate ──────────────────────────────────────────────────────
            ['utm_source_pattern' => 'impact',          'utm_medium_pattern' => 'affiliate', 'channel_name' => 'Affiliate — Impact',        'channel_type' => 'affiliate'],
            ['utm_source_pattern' => 'cj',              'utm_medium_pattern' => 'affiliate', 'channel_name' => 'Affiliate — CJ',            'channel_type' => 'affiliate'],
            ['utm_source_pattern' => 'shareasale',      'utm_medium_pattern' => 'affiliate', 'channel_name' => 'Affiliate — ShareASale',    'channel_type' => 'affiliate'],
            ['utm_source_pattern' => 'awin',            'utm_medium_pattern' => 'affiliate', 'channel_name' => 'Affiliate — Awin',          'channel_type' => 'affiliate'],
            ['utm_source_pattern' => 'partnerize',      'utm_medium_pattern' => 'affiliate', 'channel_name' => 'Affiliate — Partnerize',    'channel_type' => 'affiliate'],

            // ── Direct ─────────────────────────────────────────────────────────
            ['utm_source_pattern' => 'direct',          'utm_medium_pattern' => null,        'channel_name' => 'Direct',                    'channel_type' => 'direct'],

            // ── Referral ───────────────────────────────────────────────────────
            ['utm_source_pattern' => 'referral',        'utm_medium_pattern' => 'referral',  'channel_name' => 'Referral',                  'channel_type' => 'referral'],

            // ── Domain-style referrers ─────────────────────────────────────────
            // Literal rows first so they win over the regex wildcard below in the
            // same priority tier (global + null-medium). ChannelClassifierService
            // iterates rows in insert order within each tier.
            ['utm_source_pattern' => 'google.com',      'utm_medium_pattern' => null, 'channel_name' => 'Organic — Google',    'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'www.google.com',  'utm_medium_pattern' => null, 'channel_name' => 'Organic — Google',    'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'bing.com',        'utm_medium_pattern' => null, 'channel_name' => 'Organic — Bing',      'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'www.bing.com',    'utm_medium_pattern' => null, 'channel_name' => 'Organic — Bing',      'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'duckduckgo.com',  'utm_medium_pattern' => null, 'channel_name' => 'Organic — DuckDuckGo','channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'yahoo.com',       'utm_medium_pattern' => null, 'channel_name' => 'Organic — Yahoo',     'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'www.yahoo.com',   'utm_medium_pattern' => null, 'channel_name' => 'Organic — Yahoo',     'channel_type' => 'organic_search'],

            // Regex: covers google.de, google.co.uk, google.fr, etc. — any country TLD.
            // Must come AFTER the google.com literal rows above.
            ['utm_source_pattern' => 'google\.[a-z]{2,3}(\.[a-z]{2,3})?', 'utm_medium_pattern' => null,
             'channel_name' => 'Organic — Google', 'channel_type' => 'organic_search', 'is_regex' => true],

            // ── Social platform domains ────────────────────────────────────────
            // Regex: covers facebook.com, l.facebook.com, m.facebook.com, lm.facebook.com, www.facebook.com
            ['utm_source_pattern' => '(?:www\.|l\.|m\.|lm\.)?facebook\.com', 'utm_medium_pattern' => null,
             'channel_name' => 'Social — Facebook', 'channel_type' => 'organic_social', 'is_regex' => true],

            // Regex: covers instagram.com, l.instagram.com
            ['utm_source_pattern' => '(?:l\.)?instagram\.com', 'utm_medium_pattern' => null,
             'channel_name' => 'Social — Instagram', 'channel_type' => 'organic_social', 'is_regex' => true],

            ['utm_source_pattern' => 'tiktok.com',      'utm_medium_pattern' => null, 'channel_name' => 'Social — TikTok',     'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'twitter.com',     'utm_medium_pattern' => null, 'channel_name' => 'Social — Twitter/X',  'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'x.com',           'utm_medium_pattern' => null, 'channel_name' => 'Social — Twitter/X',  'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 't.co',            'utm_medium_pattern' => null, 'channel_name' => 'Social — Twitter/X',  'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'linkedin.com',    'utm_medium_pattern' => null, 'channel_name' => 'Social — LinkedIn',   'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'pinterest.com',   'utm_medium_pattern' => null, 'channel_name' => 'Social — Pinterest',  'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'youtube.com',     'utm_medium_pattern' => null, 'channel_name' => 'Social — YouTube',    'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'reddit.com',      'utm_medium_pattern' => null, 'channel_name' => 'Social — Reddit',     'channel_type' => 'organic_social'],

            // ── Source-only wildcards for major platforms ──────────────────────
            // Catches orders where medium is missing/null — treated as organic.
            ['utm_source_pattern' => 'facebook',        'utm_medium_pattern' => null, 'channel_name' => 'Social — Facebook',   'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'instagram',       'utm_medium_pattern' => null, 'channel_name' => 'Social — Instagram',  'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'google',          'utm_medium_pattern' => null, 'channel_name' => 'Organic — Google',    'channel_type' => 'organic_search'],
            ['utm_source_pattern' => 'bing',            'utm_medium_pattern' => null, 'channel_name' => 'Organic — Bing',      'channel_type' => 'organic_search'],

            // ── Short aliases (wildcard fallback) ──────────────────────────────
            ['utm_source_pattern' => 'fb',              'utm_medium_pattern' => null, 'channel_name' => 'Social — Facebook',   'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'ig',              'utm_medium_pattern' => null, 'channel_name' => 'Social — Instagram',  'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'yt',              'utm_medium_pattern' => null, 'channel_name' => 'Social — YouTube',    'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'tt',              'utm_medium_pattern' => null, 'channel_name' => 'Social — TikTok',     'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'li',              'utm_medium_pattern' => null, 'channel_name' => 'Social — LinkedIn',   'channel_type' => 'organic_social'],
            ['utm_source_pattern' => 'pin',             'utm_medium_pattern' => null, 'channel_name' => 'Social — Pinterest',  'channel_type' => 'organic_social'],

            // ── Payment / misc referrers ───────────────────────────────────────
            ['utm_source_pattern' => 'paypal.com',      'utm_medium_pattern' => null, 'channel_name' => 'Referral — PayPal',   'channel_type' => 'referral'],
            ['utm_source_pattern' => 'chatgpt.com',     'utm_medium_pattern' => null, 'channel_name' => 'Referral — ChatGPT',  'channel_type' => 'referral'],
            ['utm_source_pattern' => 'android-app:',    'utm_medium_pattern' => null, 'channel_name' => 'Referral — Android App', 'channel_type' => 'referral'],
            ['utm_source_pattern' => 'rest api',        'utm_medium_pattern' => null, 'channel_name' => 'Direct — API',        'channel_type' => 'direct'],
        ];

        // Validate regex patterns before inserting — catches typos at seed time.
        foreach ($rows as $row) {
            if (($row['is_regex'] ?? false) === true) {
                $pattern = '/^' . $row['utm_source_pattern'] . '$/i';
                if (@preg_match($pattern, '') === false) {
                    throw new \RuntimeException(
                        "Invalid regex in ChannelMappingsSeeder: {$row['utm_source_pattern']}"
                    );
                }
            }
        }

        $insert = array_map(fn (array $row) => array_merge([
            'workspace_id' => null,
            'is_global'    => true,
            'is_regex'     => false,
            'created_at'   => $now,
            'updated_at'   => $now,
        ], $row), $rows);

        DB::table('channel_mappings')->insert($insert);
    }
}
