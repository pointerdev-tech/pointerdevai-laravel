# pointerdev/ai-chat-laravel

Official Laravel package for PointerDev AI chat APIs.

## Install

```bash
composer require pointerdev/ai-chat-laravel
```

## Publish config (optional)

```bash
php artisan vendor:publish --tag=ai-chat-config
```

## Environment variables

```env
AI_CHAT_BASE_URL=https://pointerdev.ai
AI_CHAT_PROJECT_ID=your-project-uuid
AI_CHAT_PUBLISHABLE_KEY=pk_...
AI_CHAT_SECRET_KEY=sk_... # keep server-side only
AI_CHAT_END_USER_TOKEN=   # optional test-only fallback
AI_CHAT_TIMEOUT=20
AI_CHAT_RUNTIME_AUTH_ENABLED=true
AI_CHAT_RUNTIME_SESSION_STORE_KEY=ai_chat.runtime_session
AI_CHAT_RUNTIME_END_USER_TTL_MINUTES=60
AI_CHAT_RUNTIME_REFRESH_LEEWAY_SECONDS=5
```

## Quick usage

```php
use PointerDev\AIChat\Facades\AIChat;

$result = AIChat::chat([
    'message' => 'Hello from Laravel',
    'anon_uid' => 'demo-anon-user',
    'metadata' => ['source' => 'laravel-app'],
]);

$answer = $result['answer'] ?? null;
```

## login_required projects

```php
use PointerDev\AIChat\Facades\AIChat;

$client = AIChat::withEndUserToken('<END_USER_JWT>');

// Exchange once for short-lived runtime session token
$client->exchangeSessionToken();

$result = $client->chat([
    'message' => 'Hello from authenticated user',
]);
```

## Runtime Session Token Flow (recommended)

```php
use PointerDev\AIChat\Facades\AIChat;

$client = AIChat::withEndUserToken($endUserJwtFromYourBackend);

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

The package now provides middleware alias `ai-chat.runtime-session` that:
- reads `Auth::user()`
- mints a PointerDev AI end-user token server-side using `AI_CHAT_SECRET_KEY`
- exchanges/refreshes runtime session token
- persists runtime session token in Laravel session storage
- binds stored runtime session state to the authenticated user identity

Identity note: Laravel derives end-user `sub` from the auth identifier. WordPress defaults to `blog_id:user_id` for multisite-aware separation.

Register middleware in your route group:

```php
Route::middleware(['web', 'auth', 'ai-chat.runtime-session'])->group(function () {
    Route::get('/chat', function () {
        $result = app(\PointerDev\AIChat\AIChatClient::class)->chat([
            'message' => 'Hello from middleware flow',
            'metadata' => ['source' => 'laravel-runtime-adapter'],
        ]);

        return response()->json($result);
    });
});
```

Optional logout cleanup (revokes runtime token):

```php
app(\PointerDev\AIChat\Auth\AIChatRuntimeSessionManager::class)
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

