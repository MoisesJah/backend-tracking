<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderSyncRun;
use App\Services\WooCommerceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Throwable;

class SyncWooOrdersJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $runId)
    {
    }

    public function handle(WooCommerceManager $manager): void
    {
        /** @var OrderSyncRun|null $run */
        $run = OrderSyncRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        $run->forceFill([
            'status' => 'running',
            'started_at' => Carbon::now('UTC'),
            'error_message' => null,
        ])->save();

        $totalOrders = 0;
        $syncedOrders = 0;
        $failedStores = [];

        try {
            $wooParams = [];
            if ($run->from_date !== null) {
                $wooParams['after'] = $run->from_date->toIso8601String();
            }
            if ($run->to_date !== null) {
                $wooParams['before'] = $run->to_date->toIso8601String();
            }

            foreach (($run->stores ?? []) as $slug) {
                try {
                    $orders = $manager->store((string) $slug)->getAll('orders', $wooParams, -1);
                    $totalOrders += count($orders);

                    foreach ($orders as $wooOrder) {
                        $externalId = (string) ($wooOrder['id'] ?? '');
                        if ($externalId === '') {
                            continue;
                        }

                        $customerName = trim(
                            ((string) data_get($wooOrder, 'billing.first_name', '')) . ' ' .
                            ((string) data_get($wooOrder, 'billing.last_name', ''))
                        );

                        /** @var Order $order */
                        $order = Order::query()->updateOrCreate(
                            [
                                'store_slug' => (string) $slug,
                                'external_id' => $externalId,
                            ],
                            [
                                'status' => $this->mapWooStatus((string) ($wooOrder['status'] ?? 'processing'))->value,
                                'total' => (float) ($wooOrder['total'] ?? 0),
                                'currency' => (string) ($wooOrder['currency'] ?? 'PEN'),
                                'customer_name' => $customerName !== '' ? $customerName : null,
                                'meta' => $wooOrder,
                                'synced_at' => Carbon::now('UTC'),
                            ]
                        );

                        $syncedOrders++;
                    }
                } catch (Throwable $e) {
                    $failedStores[] = [
                        'store' => (string) $slug,
                        'message' => $e->getMessage(),
                    ];
                }

                $run->forceFill([
                    'total_orders' => $totalOrders,
                    'synced_orders' => $syncedOrders,
                    'failed_stores' => $failedStores,
                ])->save();
            }

            $run->forceFill([
                'status' => empty($failedStores) ? 'completed' : 'completed_with_errors',
                'total_orders' => $totalOrders,
                'synced_orders' => $syncedOrders,
                'failed_stores' => $failedStores,
                'finished_at' => Carbon::now('UTC'),
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'total_orders' => $totalOrders,
                'synced_orders' => $syncedOrders,
                'failed_stores' => $failedStores,
                'finished_at' => Carbon::now('UTC'),
            ])->save();

            throw $e;
        }
    }

    private function mapWooStatus(string $status): OrderStatus
    {
        return match (strtolower($status)) {
            'processing', 'pending', 'on-hold' => OrderStatus::EN_PROCESO,
            'completed' => OrderStatus::ENTREGADO,
            'cancelled', 'cancel' => OrderStatus::CANCELADO,
            'failed', 'refunded' => OrderStatus::ERROR,
            default => OrderStatus::EN_PROCESO,
        };
    }
}
