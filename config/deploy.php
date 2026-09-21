<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Image registry
    |--------------------------------------------------------------------------
    |
    | Where built images are pushed and pulled from. GHCR's container storage
    | is currently free, but that is a policy rather than a contract, so this
    | stays configuration and is never hardcoded.
    |
    */

    'registry' => env('DEPLOY_REGISTRY', 'ghcr.io/rnd-experts-team'),

    'registry_username' => env('DEPLOY_REGISTRY_USERNAME'),
    'registry_token' => env('DEPLOY_REGISTRY_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Paths on a client server
    |--------------------------------------------------------------------------
    |
    | Persistent data lives on host bind paths rather than named volumes, so
    | the weekly rsync backup can read it directly. Rendered stack files live
    | beside it.
    |
    */

    'root' => env('DEPLOY_ROOT', '/srv'),

    /*
    |--------------------------------------------------------------------------
    | Host port allocation
    |--------------------------------------------------------------------------
    |
    | Each stack gets a contiguous block; every enabled service is given one
    | port inside its stack's block and nginx proxies to it. Blocks are handed
    | out from `port_base` upwards in `block_size` steps.
    |
    */

    'ports' => [
        'base' => (int) env('DEPLOY_PORT_BASE', 40000),
        'block_size' => (int) env('DEPLOY_PORT_BLOCK_SIZE', 100),
        'max' => (int) env('DEPLOY_PORT_MAX', 60000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health checks
    |--------------------------------------------------------------------------
    |
    | How long a swapped container is given to answer before the release item
    | is considered failed.
    |
    */

    'health' => [
        'attempts' => (int) env('DEPLOY_HEALTH_ATTEMPTS', 12),
        'interval_seconds' => (int) env('DEPLOY_HEALTH_INTERVAL', 5),
    ],

];
