<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    | Sent as `Authorization: Bearer <key>`. Keep it in your .env, never in code.
    */
    'api_key' => env('TYPESAFE_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    | `jev-latest`, `jev-preview`, or a pinned version such as `jev-1.13.0`.
    | Pin a version if you need answers to stay identical between deploys.
    */
    'model' => env('TYPESAFE_MODEL', 'jev-latest'),

    'base_url' => env('TYPESAFE_BASE_URL', 'https://api.typesafe.ai'),

    /*
    | Seconds to wait for a response before giving up.
    */
    'timeout' => (int) env('TYPESAFE_TIMEOUT', 20),

    /*
    | Extra attempts after the first one, used only when the API answers 429
    | (rate limited) or 529 (overloaded), with exponential backoff and jitter.
    */
    'retries' => (int) env('TYPESAFE_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Validation rule behaviour
    |--------------------------------------------------------------------------
    | What NoulRule does when TypeSafe cannot be reached (connection failure,
    | timeout, 429/529 after retries, 5xx): false rejects the input, true lets
    | it through. Authentication and request-validation errors always throw.
    */
    'fail_open' => (bool) env('TYPESAFE_FAIL_OPEN', false),

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    | Store used by ->cacheFor(). Null means the default cache store.
    */
    'cache_store' => env('TYPESAFE_CACHE_STORE'),

];
