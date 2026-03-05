<?php

declare(strict_types=1);

namespace PointerDev\PointerAI\Tests\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session as SessionContract;
use Illuminate\Http\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PointerDev\PointerAI\Auth\PointerAIRuntimeSessionManager;
use PointerDev\PointerAI\PointerAIClient;

final class PointerAIRuntimeSessionManagerTest extends TestCase
{
    public function test_bootstrap_exchanges_and_persists_when_no_state(): void
    {
        $client = $this->createMock(PointerAIClient::class);
        [$request, $session] = $this->buildRequestWithState([]);
        $config = $this->baseConfig();
        $manager = new PointerAIRuntimeSessionManager($client, $config);
        $user = new RuntimeUser('user-1');

        $client->expects($this->exactly(2))
            ->method('setSessionToken');
        $client->expects($this->once())
            ->method('setEndUserToken')
            ->with($this->isType('string'));
        $client->expects($this->once())
            ->method('exchangeSessionToken')
            ->willReturn([
                'token' => 'session-token-1',
                'expires_at' => '2030-01-01T00:00:00+00:00',
                'refresh_available_at' => '2029-12-31T23:55:00+00:00',
                'session_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            ]);
        $session->expects($this->once())
            ->method('put')
            ->with(
                'pointerai.runtime_session',
                $this->callback(function (array $state): bool {
                    return $state['token'] === 'session-token-1'
                        && $state['session_id'] === 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'
                        && isset($state['identity'])
                        && is_string($state['identity'])
                        && $state['identity'] !== '';
                })
            );
        $session->expects($this->once())->method('forget')->with('pointerai.runtime_session');

        $manager->bootstrapForUser($request, $user);
    }

    public function test_bootstrap_refreshes_when_window_open(): void
    {
        $identity = hash('sha256', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa|user-2');
        [$request, $session] = $this->buildRequestWithState([
            'token' => 'old-session',
            'expires_at' => '2030-01-01T00:00:00+00:00',
            'refresh_available_at' => '2000-01-01T00:00:00+00:00',
            'session_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'identity' => $identity,
        ]);
        $client = $this->createMock(PointerAIClient::class);
        $manager = new PointerAIRuntimeSessionManager($client, $this->baseConfig());
        $user = new RuntimeUser('user-2');

        $client->expects($this->exactly(2))
            ->method('setSessionToken');
        $client->expects($this->once())
            ->method('refreshSessionToken')
            ->with([
                'token' => 'old-session',
                'persist' => true,
            ])
            ->willReturn([
                'token' => 'new-session',
                'expires_at' => '2030-01-01T01:00:00+00:00',
                'refresh_available_at' => '2030-01-01T00:55:00+00:00',
                'session_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            ]);
        $client->expects($this->never())->method('setEndUserToken');
        $client->expects($this->never())->method('exchangeSessionToken');

        $session->expects($this->once())
            ->method('put')
            ->with(
                'pointerai.runtime_session',
                $this->callback(fn (array $state): bool => $state['token'] === 'new-session' && $state['identity'] === $identity)
            );
        $session->expects($this->never())->method('forget');

        $manager->bootstrapForUser($request, $user);
    }

    public function test_bootstrap_clears_mismatched_identity_and_reissues(): void
    {
        [$request, $session] = $this->buildRequestWithState([
            'token' => 'other-user-session',
            'expires_at' => '2030-01-01T00:00:00+00:00',
            'refresh_available_at' => '2030-01-01T00:00:00+00:00',
            'session_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
            'identity' => 'not-matching',
        ]);
        $client = $this->createMock(PointerAIClient::class);
        $manager = new PointerAIRuntimeSessionManager($client, $this->baseConfig());
        $user = new RuntimeUser('user-3');

        $client->expects($this->exactly(2))
            ->method('setSessionToken');
        $client->expects($this->once())
            ->method('setEndUserToken')
            ->with($this->isType('string'));
        $client->expects($this->once())
            ->method('exchangeSessionToken')
            ->willReturn([
                'token' => 'fresh-session',
                'expires_at' => '2030-01-01T00:00:00+00:00',
                'refresh_available_at' => '2029-12-31T23:55:00+00:00',
                'session_id' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            ]);
        $session->expects($this->once())->method('forget')->with('pointerai.runtime_session');
        $session->expects($this->once())
            ->method('put')
            ->with(
                'pointerai.runtime_session',
                $this->callback(fn (array $state): bool => $state['token'] === 'fresh-session')
            );

        $manager->bootstrapForUser($request, $user);
    }

    public function test_revoke_clears_state_and_runtime_token(): void
    {
        [$request, $session] = $this->buildRequestWithState([
            'token' => 'runtime-token',
            'expires_at' => '2030-01-01T00:00:00+00:00',
            'refresh_available_at' => '2030-01-01T00:00:00+00:00',
            'session_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'identity' => 'identity',
        ]);
        $client = $this->createMock(PointerAIClient::class);
        $manager = new PointerAIRuntimeSessionManager($client, $this->baseConfig());

        $client->expects($this->once())
            ->method('revokeSessionToken')
            ->with([
                'token' => 'runtime-token',
                'clear_session' => true,
            ]);
        $client->expects($this->once())->method('clearSessionToken');
        $session->expects($this->once())->method('forget')->with('pointerai.runtime_session');

        $manager->revokeForRequest($request);
    }

    /**
     * @param array<string, mixed> $state
     * @return array{0: Request&MockObject, 1: SessionContract&MockObject}
     */
    private function buildRequestWithState(array $state): array
    {
        /** @var SessionContract&MockObject $session */
        $session = $this->createMock(SessionContract::class);
        $session->method('get')
            ->with('pointerai.runtime_session', [])
            ->willReturn($state);

        /** @var Request&MockObject $request */
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['session'])
            ->getMock();
        $request->expects($this->any())
            ->method('session')
            ->willReturn($session);

        return [$request, $session];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            'runtime_auth_enabled' => true,
            'secret_key' => 'package-test-secret',
            'project_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'runtime_end_user_ttl_minutes' => 60,
            'runtime_refresh_leeway_seconds' => 5,
            'runtime_session_store_key' => 'pointerai.runtime_session',
        ];
    }
}

final class RuntimeUser implements Authenticatable
{
    public function __construct(
        private readonly string $id,
        public ?string $email = null,
        public ?string $name = null,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
