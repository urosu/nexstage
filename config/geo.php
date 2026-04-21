<?php

return [
    /*
    |--------------------------------------------------------------------------
    | IP Geolocation Lookup
    |--------------------------------------------------------------------------
    |
    | Controls whether GeoDetectionService makes an outbound HTTP call to
    | ip-api.com when no Cloudflare/proxy country header is present.
    |
    | Set to true in production. Local dev typically returns 127.0.0.1 which
    | ip-api rejects anyway, so the service silently returns null in dev.
    |
    | @see App\Services\GeoDetectionService
    |
    */
    'lookup_enabled' => (bool) env('GEO_LOOKUP_ENABLED', env('APP_ENV') === 'production'),
];
