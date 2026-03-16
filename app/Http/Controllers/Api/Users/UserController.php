<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Users;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\CreateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();

        if ($actor === null || ! $actor->isAdmin()) {
            return response()->json([
                'message' => 'No autorizado para listar usuarios.',
            ], 403);
        }

        $windowSeconds = max(30, min((int) $request->query('window_seconds', config('auth.active_user_window_seconds', 120)), 3600));
        $threshold = now()->subSeconds($windowSeconds);

        $query = User::query()->latest('id');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($subQuery) use ($search): void {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = max(10, min((int) $request->query('per_page', 20), 100));
        $users = $query->paginate($perPage);

        $users->getCollection()->transform(function (User $user) use ($threshold): array {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
                'is_admin' => $user->isAdmin(),
                'last_seen_at' => optional($user->last_seen_at)?->toIso8601String(),
                'is_active' => $user->last_seen_at !== null && $user->last_seen_at->greaterThanOrEqualTo($threshold),
                'created_at' => optional($user->created_at)?->toIso8601String(),
            ];
        });

        $activeUsers = User::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $threshold)
            ->count();

        return response()->json([
            'window_seconds' => $windowSeconds,
            'active_users' => $activeUsers,
            'total_users' => User::query()->count(),
            'users' => $users,
        ]);
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        /** @var bool $isAdmin */
        $isAdmin = (bool) ($data['is_admin'] ?? false);

        $role = UserRole::tryFrom((string) ($data['role'] ?? ''));
        if ($role === null) {
            $role = $isAdmin ? UserRole::ADMIN : UserRole::DELIVERY;
        }

        $user = User::query()->create([
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'password' => Hash::make((string) $data['password']),
            'role' => $role,
            'is_admin' => $isAdmin || $role === UserRole::ADMIN,
            'last_seen_at' => null,
        ]);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'is_admin' => $user->isAdmin(),
            'last_seen_at' => optional($user->last_seen_at)?->toIso8601String(),
            'created_at' => optional($user->created_at)?->toIso8601String(),
        ], 201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        $user->forceFill([
            'last_seen_at' => now(),
        ])->saveQuietly();

        return response()->json([
            'user_id' => $user->id,
            'is_active' => true,
            'last_seen_at' => optional($user->last_seen_at)?->toIso8601String(),
        ]);
    }
}
