<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\SyncOrdersRequest;
use App\Http\Requests\Orders\UpdateStatusRequest;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {
    }

    public function index(): JsonResponse
    {
        $orders = Order::query()
            ->latest('id')
            ->paginate(50);

        return response()->json($orders);
    }

    public function sync(SyncOrdersRequest $request): JsonResponse
    {
        $this->orderService->syncFromWooCommerce($request);

        return response()->json(['message' => 'Sync started'], 202);
    }

    public function updateStatus(Order $order, UpdateStatusRequest $request): JsonResponse
    {
        $order = $this->orderService->updateStatus($order, $request);

        return response()->json($order);
    }
}
