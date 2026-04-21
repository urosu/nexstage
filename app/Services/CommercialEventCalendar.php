<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for globally curated ecommerce commercial events.
 *
 * Covers 40+ ecommerce markets with 5–10 events each. Event rules are either
 * fixed (same month/day every year), floating (nth weekday of a month, supports
 * -1 for "last occurrence"), or special (custom logic). All names are in English.
 *
 * Diwali uses a hardcoded lunisolar lookup table for 2024–2032 since it can't
 * be derived algorithmically without a full Hindu calendar library.
 *
 * Call resolve(int $year) to get concrete rows ready for Holiday::upsert().
 *
 * @see PLANNING.md section 22
 */
class CommercialEventCalendar
{
    /**
     * Diwali dates (India) — lunisolar, can't be computed without a Hindu calendar.
     * Update this lookup when extending coverage past 2032.
     */
    private const DIWALI_DATES = [
        2024 => '2024-11-01',
        2025 => '2025-10-20',
        2026 => '2026-11-07',
        2027 => '2027-10-27',
        2028 => '2028-10-17',
        2029 => '2029-11-05',
        2030 => '2030-10-26',
        2031 => '2031-10-15',
        2032 => '2032-11-02',
    ];

    /**
     * Curated commercial event rules per country.
     *
     * Rule types:
     *   fixed    — same month+day every year
     *   floating — nth occurrence of a weekday within a month (dow: 0=Sun…6=Sat)
     *              use nth=-1 for the last occurrence of the weekday in the month
     *   special  — computed by custom logic referenced in specialDate()
     */
    private const EVENTS = [
        'US' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 8,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => 'Halloween',                'rule' => 'fixed',    'month' => 10, 'day' => 31,                    'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
            ['name' => 'Green Monday',             'rule' => 'floating', 'month' => 12, 'nth' => 2,  'dow' => 1,        'category' => 'shopping'],
            ['name' => 'Free Shipping Day',        'rule' => 'fixed',    'month' => 12, 'day' => 14,                    'category' => 'shopping'],
        ],
        'GB' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Mothering Sunday = Easter − 21 days (Laetare Sunday); not a fixed calendar date
            ['name' => 'Mothering Sunday',         'rule' => 'special',  'key' => 'mothering_sunday',                   'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => 'Halloween',                'rule' => 'fixed',    'month' => 10, 'day' => 31,                    'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
            ['name' => 'Boxing Day Sales',         'rule' => 'fixed',    'month' => 12, 'day' => 26,                    'category' => 'shopping'],
            ['name' => 'January Sales',            'rule' => 'fixed',    'month' => 1,  'day' => 1,                     'category' => 'shopping'],
        ],
        'DE' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Vatertag (Father's Day) in Germany falls on Ascension Day (Easter + 39 days)
            ['name' => "Father's Day",             'rule' => 'special',  'key' => 'ascension_day',                      'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
            ['name' => 'Christmas Shopping Season','rule' => 'fixed',    'month' => 12, 'day' => 1,                     'category' => 'seasonal'],
        ],
        'FR' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Fête des Mères = last Sunday of May (deferred to 1st Sunday of June if it falls on Pentecost)
            ['name' => "Mother's Day",             'rule' => 'special',  'key' => 'fr_mothers_day',                     'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
            ['name' => 'Winter Sales',             'rule' => 'fixed',    'month' => 1,  'day' => 8,                     'category' => 'shopping'],
            ['name' => 'Summer Sales',             'rule' => 'fixed',    'month' => 6,  'day' => 25,                    'category' => 'shopping'],
        ],
        'AU' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Australia: Father's Day = 1st Sunday of September
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 9,  'nth' => 1,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 1,  'day' => 28,                    'category' => 'seasonal'],
            ['name' => 'Halloween',                'rule' => 'fixed',    'month' => 10, 'day' => 31,                    'category' => 'seasonal'],
            ['name' => 'Click Frenzy',             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
            ['name' => 'Boxing Day Sales',         'rule' => 'fixed',    'month' => 12, 'day' => 26,                    'category' => 'shopping'],
        ],
        'CA' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => 'Halloween',                'rule' => 'fixed',    'month' => 10, 'day' => 31,                    'category' => 'seasonal'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
            ['name' => 'Boxing Day Sales',         'rule' => 'fixed',    'month' => 12, 'day' => 26,                    'category' => 'shopping'],
        ],
        'NL' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Netherlands: Father's Day = 3rd Sunday of June
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Sinterklaas Shopping',     'rule' => 'fixed',    'month' => 12, 'day' => 5,                     'category' => 'gifting'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'IT' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Italy: Father's Day = March 19 (St Joseph's Day)
            ['name' => "Father's Day",             'rule' => 'fixed',    'month' => 3,  'day' => 19,                    'category' => 'gifting'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
            ['name' => 'Christmas Shopping Season','rule' => 'fixed',    'month' => 12, 'day' => 8,                     'category' => 'seasonal'],
        ],
        'ES' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Spain: Mother's Day = 1st Sunday of May
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 1,  'dow' => 0,        'category' => 'gifting'],
            // Spain: Father's Day = March 19 (St Joseph's Day)
            ['name' => "Father's Day",             'rule' => 'fixed',    'month' => 3,  'day' => 19,                    'category' => 'gifting'],
            ['name' => 'Three Kings Day',          'rule' => 'fixed',    'month' => 1,  'day' => 6,                     'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'PL' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Poland: Mother's Day = May 26 (fixed)
            ['name' => "Mother's Day",             'rule' => 'fixed',    'month' => 5,  'day' => 26,                    'category' => 'gifting'],
            // Poland: Father's Day = June 23 (fixed)
            ['name' => "Father's Day",             'rule' => 'fixed',    'month' => 6,  'day' => 23,                    'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'BR' => [
            // Brazil: Valentine's Day equivalent = June 12 (Lovers' Day / Dia dos Namorados)
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 6,  'day' => 12,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Brazil: Father's Day = 2nd Sunday of August
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 8,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Children's Day",           'rule' => 'fixed',    'month' => 10, 'day' => 12,                    'category' => 'gifting'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'IN' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Diwali Sale',              'rule' => 'special',  'key' => 'diwali',                              'category' => 'shopping'],
            ['name' => 'Big Billion Days',         'rule' => 'fixed',    'month' => 10, 'day' => 8,                     'category' => 'shopping'],
            ['name' => 'Great Indian Festival',    'rule' => 'fixed',    'month' => 10, 'day' => 1,                     'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'End of Season Sale',       'rule' => 'fixed',    'month' => 1,  'day' => 1,                     'category' => 'shopping'],
        ],
        'CN' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Singles' Day (Double 11)", 'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Double 12',                'rule' => 'fixed',    'month' => 12, 'day' => 12,                    'category' => 'shopping'],
            ['name' => '618 Mid-Year Festival',    'rule' => 'fixed',    'month' => 6,  'day' => 18,                    'category' => 'shopping'],
            ['name' => 'Golden Week Shopping',     'rule' => 'fixed',    'month' => 10, 'day' => 1,                     'category' => 'shopping'],
        ],
        'JP' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => 'White Day',                'rule' => 'fixed',    'month' => 3,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Golden Week Shopping',     'rule' => 'fixed',    'month' => 4,  'day' => 29,                    'category' => 'seasonal'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Christmas Shopping Season','rule' => 'fixed',    'month' => 12, 'day' => 20,                    'category' => 'seasonal'],
        ],
        'ZA' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 1,  'day' => 12,                    'category' => 'seasonal'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
            ['name' => 'Boxing Day Sales',         'rule' => 'fixed',    'month' => 12, 'day' => 26,                    'category' => 'shopping'],
        ],
        'MX' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Mexico: Mother's Day = May 10 (fixed, not floating)
            ['name' => "Mother's Day",             'rule' => 'fixed',    'month' => 5,  'day' => 10,                    'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Children's Day",           'rule' => 'fixed',    'month' => 4,  'day' => 30,                    'category' => 'gifting'],
            // El Buen Fin = Mexico's Black Friday, starts 3rd Friday of November
            ['name' => 'El Buen Fin',              'rule' => 'floating', 'month' => 11, 'nth' => 3,  'dow' => 5,        'category' => 'shopping'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'SE' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Sweden: Mother's Day = last Sunday of May
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => -1, 'dow' => 0,        'category' => 'gifting'],
            // Sweden: Father's Day = 2nd Sunday of November
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 11, 'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'BE' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Belgium: Father's Day = 2nd Sunday of June
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'AT' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Austria: Father's Day = 2nd Sunday of June
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
            ['name' => 'Christmas Shopping Season','rule' => 'fixed',    'month' => 12, 'day' => 1,                     'category' => 'seasonal'],
        ],
        'CH' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Switzerland: Father's Day = 1st Sunday of June
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 1,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'PT' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Portugal: Mother's Day = 1st Sunday of May
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 1,  'dow' => 0,        'category' => 'gifting'],
            // Portugal: Father's Day = March 19 (St Joseph's Day)
            ['name' => "Father's Day",             'rule' => 'fixed',    'month' => 3,  'day' => 19,                    'category' => 'gifting'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'IE' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Ireland follows UK Mothering Sunday = Easter − 21 days
            ['name' => 'Mothering Sunday',         'rule' => 'special',  'key' => 'mothering_sunday',                   'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
            ['name' => 'Boxing Day Sales',         'rule' => 'fixed',    'month' => 12, 'day' => 26,                    'category' => 'shopping'],
        ],
        'DK' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Denmark: Father's Day = 1st Sunday of June (informal but widely observed commercially)
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 1,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'NO' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Norway: Mother's Day = 2nd Sunday of February
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 2,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Norway: Father's Day = 2nd Sunday of November
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 11, 'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'FI' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Finland: Father's Day = 2nd Sunday of November
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 11, 'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'NZ' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // New Zealand: Father's Day = 1st Sunday of September
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 9,  'nth' => 1,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Click Frenzy',             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
            ['name' => 'Boxing Day Sales',         'rule' => 'fixed',    'month' => 12, 'day' => 26,                    'category' => 'shopping'],
        ],
        'SG' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Singles' Day (Double 11)", 'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Double 12',                'rule' => 'fixed',    'month' => 12, 'day' => 12,                    'category' => 'shopping'],
            ['name' => '6.6 Mid-Year Sale',        'rule' => 'fixed',    'month' => 6,  'day' => 6,                     'category' => 'shopping'],
            ['name' => '9.9 Shopping Day',         'rule' => 'fixed',    'month' => 9,  'day' => 9,                     'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
        ],
        'SI' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Slovenia: Mother's Day = March 25 (traditional fixed date)
            ['name' => "Mother's Day",             'rule' => 'fixed',    'month' => 3,  'day' => 25,                    'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'HR' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Croatia: Mother's Day = 2nd Sunday of May
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Croatia: Father's Day = March 19 (St Joseph's Day)
            ['name' => "Father's Day",             'rule' => 'fixed',    'month' => 3,  'day' => 19,                    'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'CZ' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'SK' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'HU' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Hungary: Mother's Day = 1st Sunday of May
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 1,  'dow' => 0,        'category' => 'gifting'],
            // Hungary: Father's Day = 3rd Sunday of June
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'RO' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Romania: Mother's Day = 1st Sunday of May
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 1,  'dow' => 0,        'category' => 'gifting'],
            // Romania: Father's Day = 2nd Sunday of May
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'BG' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "International Women's Day",'rule' => 'fixed',    'month' => 3,  'day' => 8,                     'category' => 'gifting'],
            // Bulgaria: Father's Day = Dec 26 (St Nikola's Day)
            ['name' => "Father's Day",             'rule' => 'fixed',    'month' => 12, 'day' => 26,                    'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 15,                    'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'GR' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 11,                    'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'TR' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Turkey: Mother's Day = 2nd Sunday of May
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Turkey: Father's Day = 3rd Sunday of June
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'UA' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Ukraine: Mother's Day = 2nd Sunday of May
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Ukraine: Father's Day = 3rd Sunday of June
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'KR' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => 'White Day',                'rule' => 'fixed',    'month' => 3,  'day' => 14,                    'category' => 'gifting'],
            // Korea: Parents' Day = May 8 (celebrates both parents together)
            ['name' => "Parents' Day",             'rule' => 'fixed',    'month' => 5,  'day' => 8,                     'category' => 'gifting'],
            ['name' => "Children's Day",           'rule' => 'fixed',    'month' => 5,  'day' => 5,                     'category' => 'gifting'],
            // Chuseok is lunisolar; mid-September is a typical planning anchor
            ['name' => 'Chuseok Shopping',         'rule' => 'fixed',    'month' => 9,  'day' => 17,                    'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'AR' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Argentina: Mother's Day = 3rd Sunday of October
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 10, 'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            // Argentina: Father's Day = 3rd Sunday of June
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 3,  'dow' => 0,        'category' => 'gifting'],
            // Argentina: Children's Day = 2nd Sunday of August
            ['name' => "Children's Day",           'rule' => 'floating', 'month' => 8,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Argentina's own Cyber Monday (fixed late-October date, separate from US)
            ['name' => 'Cyber Monday (AR)',        'rule' => 'fixed',    'month' => 10, 'day' => 28,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
        ],
        'LV' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Latvia: Father's Day = 2nd Sunday of September
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 9,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'LT' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            // Lithuania: Mother's Day = 1st Sunday of May
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 1,  'dow' => 0,        'category' => 'gifting'],
            // Lithuania: Father's Day = 1st Sunday of June
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 6,  'nth' => 1,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
        'EE' => [
            ['name' => "Valentine's Day",          'rule' => 'fixed',    'month' => 2,  'day' => 14,                    'category' => 'gifting'],
            ['name' => "Mother's Day",             'rule' => 'floating', 'month' => 5,  'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            // Estonia: Father's Day = 2nd Sunday of November
            ['name' => "Father's Day",             'rule' => 'floating', 'month' => 11, 'nth' => 2,  'dow' => 0,        'category' => 'gifting'],
            ['name' => 'Back to School',           'rule' => 'fixed',    'month' => 9,  'day' => 1,                     'category' => 'seasonal'],
            ['name' => "Singles' Day",             'rule' => 'fixed',    'month' => 11, 'day' => 11,                    'category' => 'shopping'],
            ['name' => 'Black Friday',             'rule' => 'special',  'key' => 'black_friday',                       'category' => 'shopping'],
            ['name' => 'Cyber Monday',             'rule' => 'special',  'key' => 'cyber_monday',                       'category' => 'shopping'],
        ],
    ];

    /**
     * Resolve all commercial event rules for a given year into insertable rows.
     *
     * @return array<int, array{country_code: string, date: string, name: string, year: int, type: string, category: string|null}>
     */
    public function resolve(int $year): array
    {
        $rows = [];
        $specialCache = [];

        foreach (self::EVENTS as $countryCode => $events) {
            foreach ($events as $event) {
                $date = match ($event['rule']) {
                    'fixed'    => $this->fixedDate($year, $event['month'], $event['day']),
                    'floating' => $this->floatingDate($year, $event['month'], $event['nth'], $event['dow']),
                    'special'  => $this->specialDate($year, $event['key'], $specialCache),
                    default    => null,
                };

                if ($date === null) {
                    continue;
                }

                $rows[] = [
                    'country_code' => $countryCode,
                    'date'         => $date,
                    'name'         => $event['name'],
                    'year'         => $year,
                    'type'         => 'commercial',
                    'category'     => $event['category'] ?? null,
                    'created_at'   => now()->toDateTimeString(),
                ];
            }
        }

        return $rows;
    }

    /**
     * Returns all country codes this calendar covers.
     *
     * @return string[]
     */
    public function supportedCountries(): array
    {
        return array_keys(self::EVENTS);
    }

    /**
     * Gregorian Easter Sunday date using the Anonymous Gregorian algorithm.
     * PHP's easter_days() requires the calendar extension which may not be loaded.
     */
    private function easterDate(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day);
    }

    private function fixedDate(int $year, int $month, int $day): string
    {
        return Carbon::create($year, $month, $day)->toDateString();
    }

    /**
     * Nth occurrence of a weekday within a month. E.g. 2nd Sunday of May.
     * $dow: 0=Sunday, 1=Monday … 6=Saturday (matches Carbon::SUNDAY etc.)
     * Pass $nth=-1 for the last occurrence of the weekday in the month.
     */
    private function floatingDate(int $year, int $month, int $nth, int $dow): string
    {
        if ($nth === -1) {
            // Last occurrence: start from the last day of the month and walk backwards
            $date = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
            while ($date->dayOfWeek !== $dow) {
                $date->subDay();
            }
            return $date->toDateString();
        }

        $date = Carbon::create($year, $month, 1);

        // Advance to the first occurrence of the target weekday in the month
        while ($date->dayOfWeek !== $dow) {
            $date->addDay();
        }

        // Then skip forward (nth - 1) more weeks
        $date->addWeeks($nth - 1);

        return $date->toDateString();
    }

    /**
     * Dates that require custom logic beyond fixed/floating rules.
     *
     * Results are cached within a single resolve() call to avoid recomputing
     * Black Friday/Cyber Monday for every country that references them.
     *
     * @param array<string, string|null> $cache
     */
    private function specialDate(int $year, string $key, array &$cache): ?string
    {
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $cache[$key] = match ($key) {
            // US Thanksgiving = 4th Thursday of November; Black Friday = day after
            'black_friday' => Carbon::create($year, 11, 1)
                ->next(Carbon::THURSDAY)
                ->addWeeks(3)
                ->addDay()
                ->toDateString(),

            // Cyber Monday = Monday after Black Friday = Thanksgiving + 4 days
            'cyber_monday' => Carbon::create($year, 11, 1)
                ->next(Carbon::THURSDAY)
                ->addWeeks(3)
                ->addDays(4)
                ->toDateString(),

            // French Mother's Day = last Sunday of May
            // (technically deferred to 1st Sunday of June if it falls on Pentecost — omitted here for simplicity)
            'fr_mothers_day' => (function () use ($year): string {
                $date = Carbon::create($year, 5, 31);
                while ($date->dayOfWeek !== Carbon::SUNDAY) {
                    $date->subDay();
                }
                return $date->toDateString();
            })(),

            // Ascension Day = Easter + 39 days; used for Father's Day (Vatertag) in Germany
            'ascension_day' => $this->easterDate($year)->addDays(39)->toDateString(),

            // Mothering Sunday = Easter − 21 days (Laetare Sunday); used in GB and IE
            'mothering_sunday' => $this->easterDate($year)->subDays(21)->toDateString(),

            'diwali' => self::DIWALI_DATES[$year] ?? null,

            default => (function () use ($key, $year): null {
                Log::warning("CommercialEventCalendar: unknown special key '{$key}' for year {$year}");
                return null;
            })(),
        };

        return $cache[$key];
    }
}
