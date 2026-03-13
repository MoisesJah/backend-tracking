<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Http\Requests\Orders\SyncOrdersRequest;
use App\Http\Requests\Orders\UpdateStatusRequest;
use App\Jobs\SyncWooOrdersJob;
use App\Models\Order;
use App\Models\OrderSyncRun;
use App\Models\OrderTimeline;
use App\Services\WooCommerceManager;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class OrderService
{
    public function __construct(
        private readonly WooCommerceManager $manager,
    ) {
    }

    public function syncFromWooCommerce(SyncOrdersRequest $request): OrderSyncRun
    {
        $stores = $request->getStores();
        if (empty($stores)) {
            $stores = $this->manager->availableSlugs();
        }

        $invalid = $this->manager->invalidSlugs($stores);
        if (!empty($invalid)) {
            throw new InvalidArgumentException(
                'Tiendas no encontradas: ' . implode(', ', $invalid)
            );
        }

        $run = OrderSyncRun::query()->create([
            'status' => 'queued',
            'requested_by' => auth()->id(),
            'stores' => $stores,
            'from_date' => $request->getFromDate(),
            'to_date' => $request->getToDate(),
            'total_orders' => 0,
            'synced_orders' => 0,
            'failed_stores' => [],
        ]);

        SyncWooOrdersJob::dispatch($run->id);

        return $run;
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

