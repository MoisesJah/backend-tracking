<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Http\Requests\Orders\SyncOrdersRequest;
use App\Http\Requests\Orders\UpdateStatusRequest;
use App\Models\Order;
use App\Models\OrderTimeline;
use Illuminate\Support\Carbon;

final class OrderService
{
    public function syncFromWooCommerce(SyncOrdersRequest $request): void
    {
        // Punto de entrada para sincronizar órdenes desde WooCommerce
        // Aquí se usará el cliente de WooCommerce y se poblará la tabla orders.
    }

    public function updateStatus(Order $order, UpdateStatusRequest $request): Order
    {
        $status = (string) $request->validated('status');

        $order->forceFill([
            'status' => $status,
        ])->save();

        OrderTimeline::query()->create([
            'order_id' => $order->getKey(),
            'status' => $status,
            'message' => $request->validated('message', null),
            'source' => 'manual',
            'occurred_at' => Carbon::now('UTC'),
        ]);

        return $order->refresh();
    }
}

