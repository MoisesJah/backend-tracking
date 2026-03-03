<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshRequest;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly RefreshTokenService $refreshTokenService,
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');
        $password = (string) $request->validated('password');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, (string) $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        $accessToken = $this->jwtTokenService->createAccessToken($user);
        $refreshToken = $this->refreshTokenService->issueForUser($user);

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtTokenService->ttlSeconds(),
            'refresh_token' => $refreshToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => (bool) ($user->is_admin ?? false),
            ],
        ]);
    }

    public function refresh(RefreshRequest $request): JsonResponse
    {
        $token = (string) $request->validated('refresh_token');

        [$user, $newRefreshToken] = $this->refreshTokenService->rotate($token);
        $accessToken = $this->jwtTokenService->createAccessToken($user);

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtTokenService->ttlSeconds(),
            'refresh_token' => $newRefreshToken,
        ]);
    }
}
