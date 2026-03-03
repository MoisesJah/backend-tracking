<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\CreateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function store(CreateUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        /** @var bool $isAdmin */
        $isAdmin = (bool) ($data['is_admin'] ?? false);

        $user = User::query()->create([
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'password' => Hash::make((string) $data['password']),
            'is_admin' => $isAdmin,
        ]);

        return response()->json($user, 201);
    }
}
