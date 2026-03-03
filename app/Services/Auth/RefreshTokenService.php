<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RefreshTokenService
{
    public function issueForUser(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $plain = $this->generateToken();

            RefreshToken::query()->create([
                'user_id' => $user->getKey(),
                'token_hash' => $this->hash($plain),
                'expires_at' => $this->expiresAt(),
                'revoked_at' => null,
            ]);

            return $plain;
        });
    }

    /**
     * @return array{0: User, 1: string}
     */
    public function rotate(string $refreshToken): array
    {
        return DB::transaction(function () use ($refreshToken): array {
            $hash = $this->hash($refreshToken);

            /** @var RefreshToken|null $stored */
            $stored = RefreshToken::query()
                ->where('token_hash', $hash)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', Carbon::now('UTC'))
                ->first();

            if ($stored === null) {
                throw new RuntimeException('Refresh token inválido o expirado.');
            }

            /** @var User|null $user */
            $user = User::query()->find($stored->user_id);
            if ($user === null) {
                throw new RuntimeException('Refresh token inválido.');
            }

            $stored->forceFill(['revoked_at' => Carbon::now('UTC')])->save();

            $newPlain = $this->generateToken();
            RefreshToken::query()->create([
                'user_id' => $user->getKey(),
                'token_hash' => $this->hash($newPlain),
                'expires_at' => $this->expiresAt(),
                'revoked_at' => null,
            ]);

            return [$user, $newPlain];
        });
    }

    private function expiresAt(): Carbon
    {
        $seconds = (int) config('jwt.refresh_ttl_seconds', 60 * 60 * 24 * 30);
        $seconds = max(60 * 10, $seconds);

        return Carbon::now('UTC')->addSeconds($seconds);
    }

    private function generateToken(): string
    {
        // 64 chars URL-safe aprox; suficiente entropía para refresh tokens.
        return Str::random(96);
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}

