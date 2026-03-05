<?php

declare(strict_types=1);

namespace PointerDev\PointerAI\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

class PointerAIEndUserTokenFactory
{
    private const ALGORITHM = 'HS256';

    public function __construct(
        private readonly string $projectId,
        private readonly string $secretKey,
        private readonly int $ttlMinutes = 60,
    ) {
        if (trim($this->projectId) === '') {
            throw new InvalidArgumentException('PointerAI project_id is required for end-user token minting.');
        }
        if (trim($this->secretKey) === '') {
            throw new InvalidArgumentException('PointerAI secret_key is required for end-user token minting.');
        }
    }

    /**
     * @return array{token: string, expires_at: string, end_user_id: string}
     */
    public function mintForUser(Authenticatable $user): array
    {
        $now = time();
        $ttl = max($this->ttlMinutes, 1) * 60;
        $exp = $now + $ttl;
        $projectId = trim($this->projectId);

        $endUserId = $this->deterministicUuid(
            'pointerai:' . $projectId,
            (string) $user->getAuthIdentifier()
        );

        $roles = [];
        $candidateRoles = $user->roles ?? null;
        if (is_array($candidateRoles)) {
            foreach ($candidateRoles as $role) {
                if (is_scalar($role) && trim((string) $role) !== '') {
                    $roles[] = trim((string) $role);
                }
            }
        }

        $payload = [
            'sub' => $endUserId,
            'project_id' => $projectId,
            'type' => 'end_user',
            'iat' => $now,
            'exp' => $exp,
            'iss' => 'pointerai',
            'aud' => 'pointerai:project:' . $projectId,
            'email' => is_scalar($user->email ?? null) ? (string) $user->email : null,
            'name' => is_scalar($user->name ?? null) ? (string) $user->name : null,
            'roles' => count($roles) > 0 ? $roles : null,
            'metadata' => [
                'source' => 'pointerai-laravel',
                'provider' => 'auth-user',
            ],
        ];

        return [
            'token' => $this->encodeJwt($payload, trim($this->secretKey)),
            'expires_at' => gmdate('c', $exp),
            'end_user_id' => $endUserId,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJwt(array $payload, string $secret): string
    {
        $header = [
            'alg' => self::ALGORITHM,
            'typ' => 'JWT',
        ];

        $segments = [
            $this->base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function deterministicUuid(string $namespace, string $name): string
    {
        $hashHex = sha1($namespace . '|' . $name);
        $bytes = hex2bin(substr($hashHex, 0, 32));
        if ($bytes === false || strlen($bytes) !== 16) {
            throw new InvalidArgumentException('Unable to derive deterministic UUID for end-user.');
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50); // version 5
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC4122

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

