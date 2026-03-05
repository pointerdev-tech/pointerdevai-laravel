<?php

declare(strict_types=1);

namespace PointerDev\PointerAI;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PointerDev\PointerAI\Exceptions\PointerAIRequestException;

class PointerAIClient
{
    private string $baseUrl;
    private string $projectId;
    private string $publishableKey;
    // Reserved for upcoming server-side /server/* exchange flows (Phase 2/3).
    private ?string $secretKey;
    private ?string $endUserToken;
    private ?string $sessionToken = null;
    private ?string $sessionExpiresAt = null;
    private ?string $sessionRefreshAvailableAt = null;
    private ?string $sessionId = null;
    private int $timeoutSeconds;

    public function __construct(
        string $baseUrl,
        string $projectId,
        string $publishableKey,
        ?string $secretKey = null,
        ?string $endUserToken = null,
        int $timeoutSeconds = 20
    ) {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->projectId = trim($projectId);
        $this->publishableKey = trim($publishableKey);
        $this->secretKey = $secretKey !== null ? trim($secretKey) : null;
        $this->endUserToken = $endUserToken !== null ? trim($endUserToken) : null;
        $this->timeoutSeconds = max($timeoutSeconds, 1);
    }

    public function withEndUserToken(?string $token): self
    {
        $clone = clone $this;
        $clone->setEndUserToken($token);

        return $clone;
    }

    public function setEndUserToken(?string $token): void
    {
        $this->endUserToken = $token !== null ? trim($token) : null;
    }

    public function clearEndUserToken(): void
    {
        $this->endUserToken = null;
    }

    public function withSessionToken(
        ?string $token,
        ?string $expiresAt = null,
        ?string $refreshAvailableAt = null,
        ?string $sessionId = null
    ): self {
        $clone = clone $this;
        $clone->setSessionToken($token, $expiresAt, $refreshAvailableAt, $sessionId);

        return $clone;
    }

    public function setSessionToken(
        ?string $token,
        ?string $expiresAt = null,
        ?string $refreshAvailableAt = null,
        ?string $sessionId = null
    ): void {
        $this->sessionToken = $token !== null ? trim($token) : null;
        $this->sessionExpiresAt = $expiresAt !== null ? trim($expiresAt) : null;
        $this->sessionRefreshAvailableAt = $refreshAvailableAt !== null ? trim($refreshAvailableAt) : null;
        $this->sessionId = $sessionId !== null ? trim($sessionId) : null;
    }

    public function clearSessionToken(): void
    {
        $this->sessionToken = null;
        $this->sessionExpiresAt = null;
        $this->sessionRefreshAvailableAt = null;
        $this->sessionId = null;
    }

    /**
     * @return array<string, string|null>
     */
    public function getSessionTokenState(): array
    {
        return [
            'token' => $this->sessionToken,
            'expires_at' => $this->sessionExpiresAt,
            'refresh_available_at' => $this->sessionRefreshAvailableAt,
            'session_id' => $this->sessionId,
        ];
    }

    /**
     * Exchange end-user token for short-lived runtime session token.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function exchangeSessionToken(array $options = []): array
    {
        $token = $this->extractEndUserToken($options) ?? $this->endUserToken;
        if ($token === null || trim($token) === '') {
            throw new InvalidArgumentException('end_user_token is required to exchange a session token.');
        }

        $payload = [];
        if (isset($options['session_id']) && is_scalar($options['session_id']) && trim((string) $options['session_id']) !== '') {
            $payload['session_id'] = trim((string) $options['session_id']);
        }

        $response = $this->request(
            method: 'POST',
            path: '/api/runtime/sessions',
            payload: $payload,
            endUserToken: $token,
            authMode: 'end-user',
            retryOnAuthFailure: false
        );

        $this->applySessionTokenResponse($response);

        return $response;
    }

    /**
     * Refresh runtime session token.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function refreshSessionToken(array $options = []): array
    {
        $persist = !isset($options['persist']) || (bool) $options['persist'];
        $token = $this->extractRuntimeSessionTokenOption($options) ?? $this->sessionToken;
        if ($token === null || trim($token) === '') {
            throw new InvalidArgumentException('session token is required for refresh.');
        }

        $response = $this->request(
            method: 'POST',
            path: '/api/runtime/sessions/refresh',
            payload: ['token' => $token],
            authMode: 'none',
            retryOnAuthFailure: false
        );

        if ($persist) {
            $this->applySessionTokenResponse($response);
        }

        return $response;
    }

    /**
     * Revoke runtime session token.
     *
     * @param array<string, mixed> $options
     */
    public function revokeSessionToken(array $options = []): void
    {
        $clearSession = !isset($options['clear_session']) || (bool) $options['clear_session'];
        $token = $this->extractRuntimeSessionTokenOption($options) ?? $this->sessionToken;
        if ($token === null || trim($token) === '') {
            return;
        }

        $this->request(
            method: 'POST',
            path: '/api/runtime/sessions/revoke',
            payload: ['token' => $token],
            authMode: 'none',
            retryOnAuthFailure: false
        );

        $overrideToken = $this->extractRuntimeSessionTokenOption($options);
        if ($clearSession && ($overrideToken === null || $overrideToken === $this->sessionToken)) {
            $this->clearSessionToken();
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function createSession(array $options = []): array
    {
        $payload = [
            'metadata' => isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [],
        ];

        if (isset($options['anon_uid']) && is_string($options['anon_uid']) && trim($options['anon_uid']) !== '') {
            $payload['anon_uid'] = trim($options['anon_uid']);
        }

        return $this->request(
            method: 'POST',
            path: '/api/chat/sessions',
            payload: $payload,
            endUserToken: $this->extractEndUserToken($options),
            sessionToken: $this->extractSessionToken($options)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessionsByAnon(string $anonUid, int $limit = 50, ?string $endUserToken = null): array
    {
        $anonUid = trim($anonUid);
        if ($anonUid === '') {
            throw new InvalidArgumentException('anonUid is required.');
        }

        $result = $this->request(
            method: 'GET',
            path: '/api/chat/sessions/by-anon',
            payload: [
                'anon_uid' => $anonUid,
                'limit' => max($limit, 1),
            ],
            endUserToken: $endUserToken
        );

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessionsByUser(int $limit = 50, ?string $endUserToken = null): array
    {
        $result = $this->request(
            method: 'GET',
            path: '/api/chat/sessions/by-user',
            payload: ['limit' => max($limit, 1)],
            endUserToken: $endUserToken
        );

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listMessages(string $sessionUid, int $limit = 200, ?string $endUserToken = null): array
    {
        $sessionUid = trim($sessionUid);
        if ($sessionUid === '') {
            throw new InvalidArgumentException('sessionUid is required.');
        }

        $result = $this->request(
            method: 'GET',
            path: "/api/chat/sessions/{$sessionUid}/messages",
            payload: ['limit' => max($limit, 1)],
            endUserToken: $endUserToken
        );

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function chat(array $payload): array
    {
        $message = isset($payload['message']) && is_scalar($payload['message'])
            ? trim((string) $payload['message'])
            : '';

        if ($message === '') {
            throw new InvalidArgumentException('message is required.');
        }

        $body = [
            'message' => $message,
            'metadata' => isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [],
        ];

        if (isset($payload['session_uid']) && is_scalar($payload['session_uid']) && trim((string) $payload['session_uid']) !== '') {
            $body['session_uid'] = trim((string) $payload['session_uid']);
        }

        if (isset($payload['anon_uid']) && is_scalar($payload['anon_uid']) && trim((string) $payload['anon_uid']) !== '') {
            $body['anon_uid'] = trim((string) $payload['anon_uid']);
        }

        return $this->request(
            method: 'POST',
            path: '/api/chat',
            payload: $body,
            endUserToken: $this->extractEndUserToken($payload),
            sessionToken: $this->extractSessionToken($payload)
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $payload = [],
        ?string $endUserToken = null,
        ?string $sessionToken = null,
        string $authMode = 'auto',
        bool $retryOnAuthFailure = true
    ): array {
        $this->assertConfigured();

        $method = strtoupper($method);
        $resolvedAuth = $this->resolveAuthToken($authMode, $endUserToken, $sessionToken);
        $headers = [
            'X-Project-Id' => $this->projectId,
            'X-Project-Key' => $this->publishableKey,
            'Accept' => 'application/json',
        ];

        if ($resolvedAuth['token'] !== null && $resolvedAuth['token'] !== '') {
            $headers['Authorization'] = 'Bearer ' . $resolvedAuth['token'];
        }

        $http = Http::withHeaders($headers)->timeout($this->timeoutSeconds);
        $url = $this->baseUrl . $path;

        $response = $method === 'GET'
            ? $http->send('GET', $url, ['query' => $payload])
            : $http->send($method, $url, ['json' => $payload]);

        if (! $response->successful()) {
            if ($this->shouldRefreshAndRetry($path, $response->status(), $resolvedAuth['source'], $retryOnAuthFailure, $sessionToken)) {
                $refreshToken = $this->resolveSessionTokenCandidate($sessionToken);
                if ($refreshToken !== null && $refreshToken !== '') {
                    try {
                        $this->refreshSessionToken(['token' => $refreshToken, 'persist' => true]);

                        return $this->request(
                            method: $method,
                            path: $path,
                            payload: $payload,
                            endUserToken: $endUserToken,
                            // Drop stale per-call override so refreshed in-memory token is used.
                            sessionToken: null,
                            authMode: $authMode,
                            retryOnAuthFailure: false
                        );
                    } catch (PointerAIRequestException|InvalidArgumentException) {
                        // Fall through and throw original request error.
                    }
                }
            }

            throw new PointerAIRequestException(
                status: $response->status(),
                responseBody: (string) $response->body(),
                responseData: $response->json()
            );
        }

        if ($response->status() === 204 || trim((string) $response->body()) === '') {
            return [];
        }

        $decoded = $response->json();

        if (is_array($decoded)) {
            return $decoded;
        }

        return ['raw' => (string) $response->body()];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function applySessionTokenResponse(array $response): void
    {
        $token = isset($response['token']) && is_scalar($response['token']) ? trim((string) $response['token']) : '';
        if ($token === '') {
            throw new InvalidArgumentException('Session token exchange did not return a token.');
        }

        $expiresAt = isset($response['expires_at']) && is_scalar($response['expires_at']) ? (string) $response['expires_at'] : null;
        $refreshAvailableAt = isset($response['refresh_available_at']) && is_scalar($response['refresh_available_at']) ? (string) $response['refresh_available_at'] : null;
        $sessionId = isset($response['session_id']) && is_scalar($response['session_id']) ? (string) $response['session_id'] : null;

        $this->setSessionToken($token, $expiresAt, $refreshAvailableAt, $sessionId);
    }

    /**
     * @return array{token: string|null, source: 'none'|'session'|'end-user'}
     */
    private function resolveAuthToken(string $authMode, ?string $endUserToken, ?string $sessionToken): array
    {
        $mode = trim(strtolower($authMode));
        $sessionCandidate = $this->resolveSessionTokenCandidate($sessionToken);
        $endUserCandidate = $this->trimOrNull($endUserToken) ?? $this->endUserToken;

        if ($mode === 'none') {
            return ['token' => null, 'source' => 'none'];
        }
        if ($mode === 'session') {
            return $sessionCandidate !== null
                ? ['token' => $sessionCandidate, 'source' => 'session']
                : ['token' => null, 'source' => 'none'];
        }
        if ($mode === 'end-user') {
            return $endUserCandidate !== null
                ? ['token' => $endUserCandidate, 'source' => 'end-user']
                : ['token' => null, 'source' => 'none'];
        }

        if ($sessionCandidate !== null) {
            return ['token' => $sessionCandidate, 'source' => 'session'];
        }

        if ($endUserCandidate !== null) {
            return ['token' => $endUserCandidate, 'source' => 'end-user'];
        }

        return ['token' => null, 'source' => 'none'];
    }

    private function resolveSessionTokenCandidate(?string $sessionToken): ?string
    {
        return $this->trimOrNull($sessionToken) ?? $this->sessionToken;
    }

    private function shouldRefreshAndRetry(
        string $path,
        int $status,
        string $tokenSource,
        bool $retryOnAuthFailure,
        ?string $sessionToken = null
    ): bool {
        if (! $retryOnAuthFailure) {
            return false;
        }
        if ($status !== 401) {
            return false;
        }
        if ($tokenSource !== 'session') {
            return false;
        }
        if ($path === '/api/runtime/sessions/refresh' || $path === '/api/runtime/sessions/revoke') {
            return false;
        }

        $candidate = $this->resolveSessionTokenCandidate($sessionToken);

        return $candidate !== null && $candidate !== '';
    }

    private function assertConfigured(): void
    {
        if ($this->baseUrl === '') {
            throw new InvalidArgumentException('PointerAI base URL is missing.');
        }

        if ($this->projectId === '') {
            throw new InvalidArgumentException('PointerAI project ID is missing.');
        }

        if ($this->publishableKey === '') {
            throw new InvalidArgumentException('PointerAI publishable key is missing.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractEndUserToken(array $payload): ?string
    {
        if (isset($payload['end_user_token']) && is_scalar($payload['end_user_token'])) {
            return $this->trimOrNull((string) $payload['end_user_token']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractSessionToken(array $payload): ?string
    {
        if (isset($payload['session_token']) && is_scalar($payload['session_token'])) {
            return $this->trimOrNull((string) $payload['session_token']);
        }

        return null;
    }

    /**
     * Runtime session APIs use `token` in request bodies/options.
     * Keep `session_token` as fallback for backward compatibility.
     *
     * @param array<string, mixed> $payload
     */
    private function extractRuntimeSessionTokenOption(array $payload): ?string
    {
        if (isset($payload['token']) && is_scalar($payload['token'])) {
            return $this->trimOrNull((string) $payload['token']);
        }
        if (isset($payload['session_token']) && is_scalar($payload['session_token'])) {
            return $this->trimOrNull((string) $payload['session_token']);
        }

        return null;
    }

    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
