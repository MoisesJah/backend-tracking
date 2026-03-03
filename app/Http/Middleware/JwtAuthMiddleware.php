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

        Auth::setUser($user);
        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }
}
