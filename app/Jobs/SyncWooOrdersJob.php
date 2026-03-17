<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderSyncChange;
use App\Models\OrderSyncRun;
use App\Services\WooCommerceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                        try {
                            $result = $this->syncOrder($wooOrder, (string) $slug, $run);

                            if (in_array($result['action'], ['created', 'changed', 'no_change'], true)) {
                                $syncedOrders++;
                            }
                        } catch (Throwable $itemError) {
                            Log::warning(sprintf(
                                'Error syncing order %s from %s: %s',
                                (string) ($wooOrder['id'] ?? 'unknown'),
                                (string) $slug,
                                $itemError->getMessage(),
                            ));
                        }
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

    /**
     * @return array{action: string, order: Order|null, changes: array<string, mixed>}
     */
    private function syncOrder(array $wooOrder, string $slug, OrderSyncRun $run): array
    {
        $externalId = (string) ($wooOrder['id'] ?? '');

        if ($externalId === '') {
            return [
                'action' => 'skipped',
                'order' => null,
                'changes' => [],
            ];
        }

        return DB::transaction(function () use ($wooOrder, $slug, $externalId, $run): array {
            $existingOrder = Order::query()
                ->where('store_slug', $slug)
                ->where('external_id', $externalId)
                ->first();

            $syncData = $this->extractSyncableData($wooOrder, $slug);
            $wooCreatedAt = $this->extractWooCreatedAt($wooOrder);
            $currentWooMappedStatus = $this->mapWooStatus((string) ($wooOrder['status'] ?? 'processing'));

            if ($existingOrder === null) {
                $order = new Order();
                $order->fill($syncData + [
                    'status' => $currentWooMappedStatus,
                ]);
                $order->forceFill([
                    'synced_at' => Carbon::now('UTC'),
                    'created_at' => $wooCreatedAt ?? Carbon::now('UTC'),
                ]);
                $order->save();

                foreach (($syncData + ['status' => $currentWooMappedStatus]) as $field => $value) {
                    if ($value !== null) {
                        $this->createSyncChange(
                            orderId: $order->id,
                            syncRunId: $run->id,
                            field: $field,
                            oldValue: null,
                            newValue: $value,
                            action: 'created',
                        );
                    }
                }

                return [
                    'action' => 'created',
                    'order' => $order,
                    'changes' => $syncData,
                ];
            }

            $changes = [];
            $updateData = [
                'synced_at' => Carbon::now('UTC'),
            ];

            if ($this->shouldSyncInternalStatus($existingOrder)) {
                $oldStatus = $existingOrder->status;

                if ($this->valuesAreDifferent($oldStatus, $currentWooMappedStatus)) {
                    $changes['status'] = [
                        'old' => $oldStatus,
                        'new' => $currentWooMappedStatus,
                    ];

                    $updateData['status'] = $currentWooMappedStatus;

                    $this->createSyncChange(
                        orderId: $existingOrder->id,
                        syncRunId: $run->id,
                        field: 'status',
                        oldValue: $oldStatus->value,
                        newValue: $currentWooMappedStatus->value,
                        action: 'updated',
                    );
                }
            }

            if ($wooCreatedAt !== null && $this->valuesAreDifferent($existingOrder->created_at, $wooCreatedAt)) {
                $changes['created_at'] = [
                    'old' => $existingOrder->created_at,
                    'new' => $wooCreatedAt,
                ];

                $updateData['created_at'] = $wooCreatedAt;

                $this->createSyncChange(
                    orderId: $existingOrder->id,
                    syncRunId: $run->id,
                    field: 'created_at',
                    oldValue: $existingOrder->created_at,
                    newValue: $wooCreatedAt,
                    action: 'updated',
                );
            }

            foreach ($syncData as $field => $newValue) {
                $oldValue = $existingOrder->getAttribute($field);

                if ($this->valuesAreDifferent($oldValue, $newValue)) {
                    $changes[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];

                    $updateData[$field] = $newValue;

                    $this->createSyncChange(
                        orderId: $existingOrder->id,
                        syncRunId: $run->id,
                        field: $field,
                        oldValue: $oldValue,
                        newValue: $newValue,
                        action: 'updated',
                    );
                }
            }

            if ($changes === []) {
                $existingOrder->forceFill([
                    'synced_at' => Carbon::now('UTC'),
                ])->save();

                return [
                    'action' => 'no_change',
                    'order' => $existingOrder,
                    'changes' => [],
                ];
            }

            $existingOrder->forceFill($updateData)->save();

            return [
                'action' => 'changed',
                'order' => $existingOrder->refresh(),
                'changes' => $changes,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSyncableData(array $wooOrder, string $slug): array
    {
        $customerName = trim(
            ((string) data_get($wooOrder, 'billing.first_name', '')) . ' ' .
            ((string) data_get($wooOrder, 'billing.last_name', ''))
        );

        return [
            'store_slug' => $slug,
            'external_id' => (string) ($wooOrder['id'] ?? ''),
            'total' => (float) ($wooOrder['total'] ?? 0),
            'currency' => (string) ($wooOrder['currency'] ?? 'PEN'),
            'customer_name' => $customerName !== '' ? $customerName : null,
            'meta' => $wooOrder,
        ];
    }

    private function shouldSyncInternalStatus(Order $order): bool
    {
        $previousWooStatus = data_get($order->meta, 'status');

        if (! is_string($previousWooStatus) || $previousWooStatus === '') {
            return true;
        }

        return $order->status === $this->mapWooStatus($previousWooStatus);
    }

    private function extractWooCreatedAt(array $wooOrder): ?Carbon
    {
        $rawDate = (string) (
            $wooOrder['date_created_gmt']
            ?? $wooOrder['date_created']
            ?? ''
        );

        if ($rawDate === '') {
            return null;
        }

        try {
            return Carbon::parse($rawDate, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    private function createSyncChange(
        int $orderId,
        int $syncRunId,
        string $field,
        mixed $oldValue,
        mixed $newValue,
        string $action,
    ): void {
        OrderSyncChange::query()->create([
            'order_id' => $orderId,
            'sync_run_id' => $syncRunId,
            'field' => $field,
            'old_value' => $this->stringifyValue($oldValue),
            'new_value' => $this->stringifyValue($newValue),
            'action' => $action,
            'source' => 'woo_sync',
        ]);
    }

    private function stringifyValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }

    private function valuesAreDifferent(mixed $oldValue, mixed $newValue): bool
    {
        if ($oldValue instanceof Carbon && $newValue instanceof Carbon) {
            return ! $oldValue->equalTo($newValue);
        }

        if (is_array($oldValue) || is_array($newValue)) {
            return $this->stringifyValue($oldValue) !== $this->stringifyValue($newValue);
        }

        return $oldValue !== $newValue;
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