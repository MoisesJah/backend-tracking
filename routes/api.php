<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::get('/health', static fn () => response()->json(['ok' => true]));

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

Route::middleware(['jwt'])->group(function (): void {
    Route::get('/me', static fn () => response()->json(['user' => request()->user()]));
});

