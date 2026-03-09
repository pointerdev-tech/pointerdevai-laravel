<?php

declare(strict_types=1);

namespace PointerDev\AIChat\Tests\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PointerDev\AIChat\Auth\AIChatEndUserTokenFactory;

final class AIChatEndUserTokenFactoryTest extends TestCase
{
    public function test_it_mints_token_with_expected_claims(): void
    {
        $factory = new AIChatEndUserTokenFactory(
            projectId: '11111111-1111-1111-1111-111111111111',
            secretKey: 'test-secret-key',
            ttlMinutes: 30
        );

        $user = new FakeAuthenticatableUser(
            id: 'laravel-user-123',
            email: 'user@example.com',
            name: 'Test User',
            roles: ['owner', 'tester']
        );

        $result = $factory->mintForUser($user);
        $this->assertIsString($result['token']);
        $this->assertIsString($result['expires_at']);
        $this->assertIsString($result['end_user_id']);

        $payload = $this->decodePayload($result['token']);

        $this->assertSame($result['end_user_id'], $payload['sub']);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $payload['project_id']);
        $this->assertSame('end_user', $payload['type']);
        $this->assertSame('pointerai', $payload['iss']);
        $this->assertSame('pointerai:project:11111111-1111-1111-1111-111111111111', $payload['aud']);
        $this->assertSame('user@example.com', $payload['email']);
        $this->assertSame('Test User', $payload['name']);
        $this->assertSame(['owner', 'tester'], $payload['roles']);
        $this->assertSame('ai-chat-laravel', $payload['metadata']['source']);
        $this->assertSame('auth-user', $payload['metadata']['provider']);
        $this->assertIsInt($payload['iat']);
        $this->assertIsInt($payload['exp']);
        $this->assertGreaterThan($payload['iat'], $payload['exp']);
    }

    public function test_it_requires_project_id_and_secret_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AIChatEndUserTokenFactory(
            projectId: '',
            secretKey: 'test-secret-key'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);

        $base64 = strtr($parts[1], '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $payloadRaw = base64_decode($base64, true);
        $this->assertNotFalse($payloadRaw);

        $decoded = json_decode((string) $payloadRaw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}

final class FakeAuthenticatableUser implements Authenticatable
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly string $id,
        public ?string $email = null,
        public ?string $name = null,
        public array $roles = [],
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
