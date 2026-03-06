<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | PointerAI API Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('POINTERAI_BASE_URL', 'https://pointerdev.ai'),

    /*
    |--------------------------------------------------------------------------
    | Project Credentials (from PointerAI agent)
    |--------------------------------------------------------------------------
    */
    'project_id' => env('POINTERAI_PROJECT_ID', ''),
    'publishable_key' => env('POINTERAI_PUBLISHABLE_KEY', ''),
    'secret_key' => env('POINTERAI_SECRET_KEY', null),

    /*
    |--------------------------------------------------------------------------
    | Optional test token for login_required projects
    |--------------------------------------------------------------------------
    */
    'end_user_token' => env('POINTERAI_END_USER_TOKEN', null),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout in seconds
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('POINTERAI_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Runtime Auth Adapter (Phase 3)
    |--------------------------------------------------------------------------
    | When enabled, middleware can map Auth::user() to a PointerAI end-user
    | token, exchange it for runtime session token, and persist token state in
    | Laravel session storage.
    */
    'runtime_auth_enabled' => (bool) env('POINTERAI_RUNTIME_AUTH_ENABLED', true),
    'runtime_session_store_key' => env('POINTERAI_RUNTIME_SESSION_STORE_KEY', 'pointerai.runtime_session'),
    'runtime_end_user_ttl_minutes' => (int) env('POINTERAI_RUNTIME_END_USER_TTL_MINUTES', 60),
    'runtime_refresh_leeway_seconds' => (int) env('POINTERAI_RUNTIME_REFRESH_LEEWAY_SECONDS', 5),
];
