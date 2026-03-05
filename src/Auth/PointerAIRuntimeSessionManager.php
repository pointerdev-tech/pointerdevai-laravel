<?php

declare(strict_types=1);

namespace PointerDev\PointerAI\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PointerDev\PointerAI\Exceptions\PointerAIRequestException;
use PointerDev\PointerAI\PointerAIClient;

class PointerAIRuntimeSessionManager
{
    public function __construct(
        private readonly PointerAIClient $client,
        private readonly array $config
    ) {}

    public function bootstrapForUser(Request $request, Authenticatable $user): void
    {
        if (!($this->config['runtime_auth_enabled'] ?? true)) {
            return;
        }

        $secretKey = isset($this->config['secret_key']) ? trim((string) $this->config['secret_key']) : '';
        $projectId = isset($this->config['project_id']) ? trim((string) $this->config['project_id']) : '';
        if ($secretKey === '' || $projectId === '') {
            return;
        }

        $identity = $this->buildIdentity($user, $projectId);
        $state = $this->loadState($request);
        if (!$this->isStateForIdentity($state, $identity)) {
            $this->clearState($request);
            $state = [];
        }
        $this->applyStateToClient($state);

        if ($this->shouldRefresh($state) || $this->isExpired($state)) {
            try {
                $response = $this->client->refreshSessionToken([
                    'token' => $state['token'] ?? null,
                    'persist' => true,
                ]);
                $this->persistState($request, $response, $identity);
                return;
            } catch (PointerAIRequestException|InvalidArgumentException) {
                $this->clearState($request);
                $this->client->clearSessionToken();
                $state = [];
            }
        }

        if (!isset($state['token']) || !is_string($state['token']) || trim($state['token']) === '') {
            $tokenFactory = new PointerAIEndUserTokenFactory(
                projectId: $projectId,
                secretKey: $secretKey,
                ttlMinutes: (int) ($this->config['runtime_end_user_ttl_minutes'] ?? 60),
            );
            $endUser = $tokenFactory->mintForUser($user);
            $this->client->setEndUserToken($endUser['token']);

            $response = $this->client->exchangeSessionToken();
            $this->persistState($request, $response, $identity);
        }
    }

    public function revokeForRequest(Request $request): void
    {
        $state = $this->loadState($request);
        if (!isset($state['token']) || !is_string($state['token']) || trim($state['token']) === '') {
            $this->clearState($request);
            return;
        }

        try {
            $this->client->revokeSessionToken([
                'token' => $state['token'],
                'clear_session' => true,
            ]);
        } catch (PointerAIRequestException|InvalidArgumentException) {
            // Ignore transport errors during logout cleanup.
        }

        $this->clearState($request);
        $this->client->clearSessionToken();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function applyStateToClient(array $state): void
    {
        $this->client->setSessionToken(
            isset($state['token']) && is_string($state['token']) ? $state['token'] : null,
            isset($state['expires_at']) && is_string($state['expires_at']) ? $state['expires_at'] : null,
            isset($state['refresh_available_at']) && is_string($state['refresh_available_at']) ? $state['refresh_available_at'] : null,
            isset($state['session_id']) && is_string($state['session_id']) ? $state['session_id'] : null
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function shouldRefresh(array $state): bool
    {
        $token = isset($state['token']) && is_string($state['token']) ? trim($state['token']) : '';
        $refreshAvailableAt = isset($state['refresh_available_at']) && is_string($state['refresh_available_at'])
            ? trim($state['refresh_available_at'])
            : '';
        if ($token === '' || $refreshAvailableAt === '') {
            return false;
        }

        $ts = strtotime($refreshAvailableAt);
        if ($ts === false) {
            return false;
        }

        $leeway = (int) ($this->config['runtime_refresh_leeway_seconds'] ?? 5);
        return time() >= ($ts - max($leeway, 0));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isExpired(array $state): bool
    {
        $token = isset($state['token']) && is_string($state['token']) ? trim($state['token']) : '';
        $expiresAt = isset($state['expires_at']) && is_string($state['expires_at']) ? trim($state['expires_at']) : '';
        if ($token === '' || $expiresAt === '') {
            return false;
        }

        $ts = strtotime($expiresAt);
        if ($ts === false) {
            return false;
        }

        return time() >= $ts;
    }

    private function buildIdentity(Authenticatable $user, string $projectId): string
    {
        return hash('sha256', $projectId . '|' . (string) $user->getAuthIdentifier());
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isStateForIdentity(array $state, string $identity): bool
    {
        if (!isset($state['identity']) || !is_string($state['identity'])) {
            return false;
        }

        return hash_equals(trim($state['identity']), $identity);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(Request $request): array
    {
        $key = $this->sessionKey();
        $state = $request->session()->get($key, []);
        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function persistState(Request $request, array $response, string $identity): void
    {
        $state = [
            'token' => isset($response['token']) && is_scalar($response['token']) ? (string) $response['token'] : null,
            'expires_at' => isset($response['expires_at']) && is_scalar($response['expires_at']) ? (string) $response['expires_at'] : null,
            'refresh_available_at' => isset($response['refresh_available_at']) && is_scalar($response['refresh_available_at']) ? (string) $response['refresh_available_at'] : null,
            'session_id' => isset($response['session_id']) && is_scalar($response['session_id']) ? (string) $response['session_id'] : null,
            'identity' => $identity,
        ];

        $request->session()->put($this->sessionKey(), $state);
        $this->applyStateToClient($state);
    }

    private function clearState(Request $request): void
    {
        $request->session()->forget($this->sessionKey());
    }

    private function sessionKey(): string
    {
        $key = isset($this->config['runtime_session_store_key'])
            ? trim((string) $this->config['runtime_session_store_key'])
            : 'pointerai.runtime_session';
        return $key !== '' ? $key : 'pointerai.runtime_session';
    }
}
