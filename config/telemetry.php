<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telemetry driver
    |--------------------------------------------------------------------------
    | `database` reads whatever has been written into `sensor_readings` — by the
    | seeder, by the simulator command, or by devices posting to /api/ingest.
    | `http` pulls straight from an upstream logger API instead; point it at the
    | real endpoint and the dashboard payloads stay identical.
    */

    'driver' => env('TELEMETRY_DRIVER', 'database'),

    'http' => [
        'base_url' => env('TELEMETRY_BASE_URL'),
        'token' => env('TELEMETRY_TOKEN'),
        'timeout' => env('TELEMETRY_TIMEOUT', 8),
        'cache_ttl' => env('TELEMETRY_CACHE_TTL', 20),

        // Endpoint templates. {station} / {metric} / {from} / {to} are replaced.
        'endpoints' => [
            'latest' => env('TELEMETRY_ENDPOINT_LATEST', '/stations/{station}/latest'),
            'series' => env('TELEMETRY_ENDPOINT_SERIES', '/stations/{station}/series?metric={metric}&from={from}&to={to}'),
        ],

        // Map upstream field names onto the local metric keys when they differ.
        'field_map' => [
            // 'water_level' => 'wl',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingest endpoint
    |--------------------------------------------------------------------------
    | Shared secret expected in the X-Ingest-Token header of POST /api/ingest.
    */

    'ingest_token' => env('TELEMETRY_INGEST_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Weather driver
    |--------------------------------------------------------------------------
    | `synthetic` derives plausible conditions from the on-site AWR station and
    | the solar clock. `http` calls an external forecast API.
    */

    'weather' => [
        'driver' => env('WEATHER_DRIVER', 'synthetic'),
        'base_url' => env('WEATHER_BASE_URL'),
        'api_key' => env('WEATHER_API_KEY'),
        'cache_ttl' => env('WEATHER_CACHE_TTL', 600),
    ],

];
