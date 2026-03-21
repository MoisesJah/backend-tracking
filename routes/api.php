<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Orders\OrderController;
use App\Http\Controllers\Api\Orders\PublicOrderLookupController;
use App\Http\Controllers\Api\Users\UserController;
use App\Http\Controllers\Api\Webhooks\WooCommerceWebhookController;
use App\Http\Controllers\WooCommerceController;
use App\Http\Controllers\Api\BsaleController;

Route::prefix('v1')->group(function (): void {
    Route::get('/', static fn () => response()->json([
        'message' => 'Debes iniciar sesion para usar la API.',
        'login_endpoint' => '/api/v1/auth/login',
       
    ]));

    Route::get('/bsale/pedido/{offset}', [BsaleController::class, 'getOrderDetails']);
    Route::get('/health', static fn () => response()->json(['ok' => true]));

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });

    Route::post('/orders/public/lookup', [PublicOrderLookupController::class, 'lookup'])
        ->middleware('throttle:30,1');

    Route::middleware(['jwt'])->group(function (): void {
        Route::get('/me', static fn () => response()->json(['user' => request()->user()]));

        // Orders endpoints
        Route::prefix('orders')->group(function (): void {
            Route::get('/', [OrderController::class, 'index']);
            Route::get('/sync/{syncRun}', [OrderController::class, 'syncStatus']);
            Route::get('/{order}', [OrderController::class, 'show']);
            Route::get('/{order}/history', [OrderController::class, 'history']);
            Route::get('/{order}/logs', [OrderController::class, 'logs']);
            Route::get('/{order}/available-transitions', [OrderController::class, 'availableTransitions']);
            Route::post('/sync', [OrderController::class, 'sync']);
            Route::put('/{order}/status', [OrderController::class, 'updateStatus']);
        });

        Route::prefix('auth')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
        });

        Route::prefix('users')->group(function (): void {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::post('/heartbeat', [UserController::class, 'heartbeat']);
        });

        Route::get('/stores', [WooCommerceController::class, 'listStores']);
        Route::prefix('woo')->group(function () {
            Route::get('/orders/all', [WooCommerceController::class, 'getAllOrders']);
            Route::get('/orders', [WooCommerceController::class, 'getOrders']);
            Route::get('/orders/{id}',    [WooCommerceController::class, 'showOrder']);
            Route::put('/orders/{id}', [WooCommerceController::class, 'updateOrder']);
        });
    });

    Route::post(
        '/webhooks/woocommerce',
        [WooCommerceWebhookController::class, 'handle'],
    );
});
