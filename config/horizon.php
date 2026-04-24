<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web', 'super_admin'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | Per PLANNING section 22.5 — alert when a queue waits too long.
    | Webhooks get a tight threshold (30 s); sync queues allow 5 min.
    |
    */

    'waits' => [
        'redis:critical-webhooks' => 30,
        'redis:sync-facebook'     => 300,
        'redis:sync-google-ads'   => 300,
        'redis:sync-google-search'=> 300,
        'redis:sync-store'        => 300,
        'redis:sync-psi'          => 300,
        'redis:imports-store'     => 600,
        'redis:imports-ads'       => 600,
        'redis:imports-gsc'       => 600,
        'redis:default'           => 120,
        'redis:low'               => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Per-provider supervisors per PLANNING section 22.1.
    |
    | Shape rationale:
    |  - critical-webhooks: fast lane for incoming webhooks; high worker count for
    |    low latency, tight timeout because webhook payloads are small.
    |  - sync-facebook / sync-google-ads: deliberately capped at 2 workers because
    |    we are on dev apps with strict API quotas. Raise after production app approval.
    |  - sync-google-search / sync-psi: quota-sensitive; 2 workers is safe.
    |  - sync-store: no shared external rate limit (each WP site is independent);
    |    runs wide so onboarding imports feel fast.
    |  - imports: isolated so a 37-month ad-history import never starves regular sync.
    |  - default: everything not needing provider-specific handling.
    |  - low: non-urgent background work (snapshots, AI, FX, cleanup).
    |
    | @see PLANNING.md section 22
    |
    */

    'defaults' => [
        // Webhook processing — 10 workers, 30 s timeout.
        'supervisor-webhooks' => [
            'connection' => 'redis',
            'queue'      => ['critical-webhooks'],
            'balance'    => 'simple',
            'processes'  => 10,
            'tries'      => 5,
            'timeout'    => 30,
            'nice'       => 0,
        ],
        // Facebook Marketing API — 2 workers (dev app quota).
        'supervisor-facebook' => [
            'connection' => 'redis',
            'queue'      => ['sync-facebook'],
            'balance'    => 'simple',
            'processes'  => 2,
            'tries'      => 3,
            'timeout'    => 300,
            'nice'       => 0,
        ],
        // Google Ads API — 2 workers (dev app quota).
        'supervisor-google-ads' => [
            'connection' => 'redis',
            'queue'      => ['sync-google-ads'],
            'balance'    => 'simple',
            'processes'  => 2,
            'tries'      => 3,
            'timeout'    => 300,
            'nice'       => 0,
        ],
        // Google Search Console — 2 workers (quota sensitive).
        'supervisor-google-search' => [
            'connection' => 'redis',
            'queue'      => ['sync-google-search'],
            'balance'    => 'simple',
            'processes'  => 2,
            'tries'      => 3,
            'timeout'    => 300,
            'nice'       => 0,
        ],
        // WooCommerce / Shopify store sync — 10 workers (no shared API rate limit).
        'supervisor-store' => [
            'connection' => 'redis',
            'queue'      => ['sync-store'],
            'balance'    => 'simple',
            'processes'  => 10,
            'tries'      => 3,
            'timeout'    => 300,
            'nice'       => 0,
        ],
        // Lighthouse / PageSpeed Insights — 2 workers (quota sensitive).
        'supervisor-psi' => [
            'connection' => 'redis',
            'queue'      => ['sync-psi'],
            'balance'    => 'simple',
            'processes'  => 2,
            'tries'      => 3,
            'timeout'    => 300,
            'nice'       => 0,
        ],
        // Shopify/WooCommerce historical imports — no shared API quota, runs wide (same as sync-store).
        'supervisor-imports-store' => [
            'connection' => 'redis',
            'queue'      => ['imports-store'],
            'balance'    => 'simple',
            'processes'  => 10,
            'tries'      => 3,
            'timeout'    => 7200,
            'nice'       => 0,
        ],
        // Facebook/Google Ads historical imports — quota-sensitive, same cap as sync.
        'supervisor-imports-ads' => [
            'connection' => 'redis',
            'queue'      => ['imports-ads'],
            'balance'    => 'simple',
            'processes'  => 2,
            'tries'      => 3,
            'timeout'    => 7200,
            'nice'       => 0,
        ],
        // GSC historical imports — quota-sensitive, isolated from ad imports.
        'supervisor-imports-gsc' => [
            'connection' => 'redis',
            'queue'      => ['imports-gsc'],
            'balance'    => 'simple',
            'processes'  => 2,
            'tries'      => 3,
            'timeout'    => 7200,
            'nice'       => 0,
        ],
        // Default — everything not needing provider-specific handling.
        'supervisor-default' => [
            'connection' => 'redis',
            'queue'      => ['default'],
            'balance'    => 'simple',
            'processes'  => 5,
            'tries'      => 3,
            'timeout'    => 300,
            'nice'       => 0,
        ],
        // Low — snapshots, AI summaries, FX rates, cleanup, backfill.
        'supervisor-low' => [
            'connection' => 'redis',
            'queue'      => ['low'],
            'balance'    => 'simple',
            'processes'  => 3,
            'tries'      => 3,
            'timeout'    => 7200,
            'nice'       => 0,
        ],
    ],

    'environments' => [
        // Production: full process counts per PLANNING section 22.1.
        'production' => [
            'supervisor-webhooks'       => ['processes' => 10],
            'supervisor-facebook'       => ['processes' => 2],
            'supervisor-google-ads'     => ['processes' => 2],
            'supervisor-google-search'  => ['processes' => 2],
            'supervisor-store'          => ['processes' => 10],
            'supervisor-psi'            => ['processes' => 2],
            'supervisor-imports-store'  => ['processes' => 10],
            'supervisor-imports-ads'    => ['processes' => 2],
            'supervisor-imports-gsc'    => ['processes' => 2],
            'supervisor-default'        => ['processes' => 5],
            'supervisor-low'            => ['processes' => 3],
        ],

        // Local / dev: reduced process counts to stay within memory budget.
        'local' => [
            'supervisor-webhooks'       => ['processes' => 2],
            'supervisor-facebook'       => ['processes' => 1],
            'supervisor-google-ads'     => ['processes' => 1],
            'supervisor-google-search'  => ['processes' => 1],
            'supervisor-store'          => ['processes' => 3],
            'supervisor-psi'            => ['processes' => 1],
            'supervisor-imports-store'  => ['processes' => 3],
            'supervisor-imports-ads'    => ['processes' => 1],
            'supervisor-imports-gsc'    => ['processes' => 1],
            'supervisor-default'        => ['processes' => 2],
            'supervisor-low'            => ['processes' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
    ],
];
