<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | PointerDev AI API Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('AI_CHAT_BASE_URL', 'https://pointerdev.ai'),

    /*
    |--------------------------------------------------------------------------
    | Project Credentials (from PointerDev AI agent)
    |--------------------------------------------------------------------------
    */
    'project_id' => env('AI_CHAT_PROJECT_ID', ''),
    'publishable_key' => env('AI_CHAT_PUBLISHABLE_KEY', ''),
    'secret_key' => env('AI_CHAT_SECRET_KEY', null),

    /*
    |--------------------------------------------------------------------------
    | Optional test token for login_required projects
    |--------------------------------------------------------------------------
    */
    'end_user_token' => env('AI_CHAT_END_USER_TOKEN', null),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout in seconds
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('AI_CHAT_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Runtime Auth Adapter (Phase 3)
    |--------------------------------------------------------------------------
    | When enabled, middleware can map Auth::user() to a PointerDev AI end-user
    | token, exchange it for runtime session token, and persist token state in
    | Laravel session storage.
    */
    'runtime_auth_enabled' => (bool) env('AI_CHAT_RUNTIME_AUTH_ENABLED', true),
    'runtime_session_store_key' => env('AI_CHAT_RUNTIME_SESSION_STORE_KEY', 'ai_chat.runtime_session'),
    'runtime_end_user_ttl_minutes' => (int) env('AI_CHAT_RUNTIME_END_USER_TTL_MINUTES', 60),
    'runtime_refresh_leeway_seconds' => (int) env('AI_CHAT_RUNTIME_REFRESH_LEEWAY_SECONDS', 5),
];
