# pointerdev/pointerai-laravel

Official Laravel package for PointerAI chat APIs.

## Install

```bash
composer require pointerdev/pointerai-laravel
```

## Publish config (optional)

```bash
php artisan vendor:publish --tag=pointerai-config
```

## Environment variables

```env
POINTERAI_BASE_URL=http://localhost:8000
POINTERAI_PROJECT_ID=your-project-uuid
POINTERAI_PUBLISHABLE_KEY=pk_...
POINTERAI_SECRET_KEY=sk_... # keep server-side only
POINTERAI_END_USER_TOKEN=   # optional test-only fallback
POINTERAI_TIMEOUT=20
POINTERAI_RUNTIME_AUTH_ENABLED=true
POINTERAI_RUNTIME_SESSION_STORE_KEY=pointerai.runtime_session
POINTERAI_RUNTIME_END_USER_TTL_MINUTES=60
POINTERAI_RUNTIME_REFRESH_LEEWAY_SECONDS=5
```

## Quick usage

```php
use PointerDev\PointerAI\Facades\PointerAI;

$result = PointerAI::chat([
    'message' => 'Hello from Laravel',
    'anon_uid' => 'demo-anon-user',
    'metadata' => ['source' => 'laravel-app'],
]);

$answer = $result['answer'] ?? null;
```

## login_required projects

```php
use PointerDev\PointerAI\Facades\PointerAI;

$client = PointerAI::withEndUserToken('<END_USER_JWT>');

// Exchange once for short-lived runtime session token
$client->exchangeSessionToken();

$result = $client->chat([
    'message' => 'Hello from authenticated user',
]);
```

## Runtime Session Token Flow (recommended)

```php
use PointerDev\PointerAI\Facades\PointerAI;

$client = PointerAI::withEndUserToken($endUserJwtFromYourBackend);

// Exchange end-user token for runtime session token
$sessionAuth = $client->exchangeSessionToken();

// Chat now prefers runtime session token automatically
$result = $client->chat([
    'message' => 'Hello from authenticated user',
    'metadata' => ['source' => 'laravel-app'],
]);

// Optional explicit refresh/revoke
$client->refreshSessionToken();
$client->revokeSessionToken();
```

## Phase 3 auth adapter (Laravel middleware)

The package now provides middleware alias `pointerai.runtime-session` that:
- reads `Auth::user()`
- mints a PointerAI end-user token server-side using `POINTERAI_SECRET_KEY`
- exchanges/refreshes runtime session token
- persists runtime session token in Laravel session storage
- binds stored runtime session state to the authenticated user identity

Identity note: Laravel derives end-user `sub` from the auth identifier. WordPress defaults to `blog_id:user_id` for multisite-aware separation.

Register middleware in your route group:

```php
Route::middleware(['web', 'auth', 'pointerai.runtime-session'])->group(function () {
    Route::get('/chat', function () {
        $result = app(\PointerDev\PointerAI\PointerAIClient::class)->chat([
            'message' => 'Hello from middleware flow',
            'metadata' => ['source' => 'laravel-runtime-adapter'],
        ]);

        return response()->json($result);
    });
});
```

Optional logout cleanup (revokes runtime token):

```php
app(\PointerDev\PointerAI\Auth\PointerAIRuntimeSessionManager::class)
    ->revokeForRequest(request());
```

## Available methods

- `createSession(array $options = [])`
- `chat(array $payload)`
- `listSessionsByAnon(string $anonUid, int $limit = 50, ?string $token = null)`
- `listSessionsByUser(int $limit = 50, ?string $token = null)`
- `listMessages(string $sessionUid, int $limit = 200, ?string $token = null)`
- `exchangeSessionToken(array $options = [])`
- `refreshSessionToken(array $options = [])`
- `revokeSessionToken(array $options = [])`
- `withSessionToken(?string $token, ?string $expiresAt = null, ?string $refreshAvailableAt = null, ?string $sessionId = null)`
- `setSessionToken(?string $token, ?string $expiresAt = null, ?string $refreshAvailableAt = null, ?string $sessionId = null)`
- `clearSessionToken()`
- `getSessionTokenState()`
- `withEndUserToken(?string $token)`
- `setEndUserToken(?string $token)`
- `clearEndUserToken()`

## Notes

- Only use publishable keys (`pk_...`) in browser/client flows.
- Keep secret keys server-side only.

## Testing

Run package unit tests locally:

```bash
composer install
composer test
```

