<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            throw new AuthenticationException('Missing bearer token.');
        }

        try {
            $payload = app(JwtTokenService::class)->decode($token);
        } catch (Throwable) {
            throw new AuthenticationException('Invalid token.');
        }

        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId <= 0) {
            throw new AuthenticationException('Invalid token.');
        }

        /** @var User|null $user */
        $user = User::query()->find($userId);
        if ($user === null) {
            throw new AuthenticationException('Invalid token.');
        }

        $touchIntervalSeconds = max(15, (int) config('auth.user_activity_touch_interval_seconds', 30));
        $shouldTouch = $user->last_seen_at === null
            || $user->last_seen_at->lt(now()->subSeconds($touchIntervalSeconds));

        if ($shouldTouch) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }
}
