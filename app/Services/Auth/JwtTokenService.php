<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

final class JwtTokenService
{
    public function ttlSeconds(): int
    {
        return max(60, (int) config('jwt.ttl_seconds', 900));
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $jwt): array
    {
        $secret = (string) config('jwt.secret', '');
        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET no configurado.');
        }

        $leeway = (int) config('jwt.leeway_seconds', 0);
        JWT::$leeway = max(0, $leeway);

        try {
            /** @var object $decoded */
            $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));
        } catch (Throwable $e) {
            throw $e;
        }

        $payload = (array) $decoded;

        $issuer = (string) config('jwt.issuer', '');
        if ($issuer !== '' && Arr::get($payload, 'iss') !== $issuer) {
            throw new RuntimeException('Issuer inválido.');
        }

        $aud = (string) config('jwt.audience', '');
        if ($aud !== '' && Arr::get($payload, 'aud') !== $aud) {
            throw new RuntimeException('Audience inválido.');
        }

        return $payload;
    }

    public function createAccessToken(User $user): string
    {
        $secret = (string) config('jwt.secret', '');
        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET no configurado.');
        }

        $now = Carbon::now('UTC')->timestamp;
        $ttl = $this->ttlSeconds();

        $payload = array_filter([
            'iss' => (string) config('jwt.issuer', ''),
            'aud' => (string) config('jwt.audience', ''),
            'sub' => (string) $user->getKey(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ], static fn (mixed $v): bool => $v !== '');

        return JWT::encode($payload, $secret, 'HS256');
    }
}

