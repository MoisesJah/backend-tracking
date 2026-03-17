<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderTimeline;
use App\Models\User;
use App\Services\WooCommerceManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class OrderStatusService
{
    public function __construct(
        private readonly WooCommerceManager $wooManager,
    ) {
    }

    /**
     * Update the order status with full validation and audit logging.
     *
     * @throws InvalidArgumentException
     */
    public function updateStatus(
        Order $order,
        OrderStatus $newStatus,
        User $user,
        ?string $errorReason = null,
        ?string $evidenceImagePath = null,
        ?string $ipAddress = null
    ): Order {
        // Validate user has permission
        if (!$user->canUpdateOrderStatus($newStatus)) {
            throw new InvalidArgumentException(
                "User with role {$user->role->value} cannot update order to {$newStatus->value}"
            );
        }

        // Validate state transition
        if (!$order->canTransitionTo($newStatus)) {
            throw new InvalidArgumentException(
                "Cannot transition from {$order->status->value} to {$newStatus->value}"
            );
        }

        // Validate required fields
        if ($newStatus === OrderStatus::ERROR && empty($errorReason)) {
            throw new InvalidArgumentException('Error reason is required for error status');
        }

        $wooStatus = $this->mapToWooStatus($newStatus);
        $wooSynced = false;

        // Sync only for selected business transitions.
        if ($wooStatus !== null) {
            $this->syncWooOrderStatus($order, $wooStatus);
            $wooSynced = true;
        }

        return DB::transaction(function () use (
            $order,
            $newStatus,
            $user,
            $errorReason,
            $evidenceImagePath,
            $wooStatus,
            $wooSynced,
            $ipAddress
        ): Order {
            $oldStatus = $order->status;

            // Prepare update data
            $updateData = [
                'status' => $newStatus,
                'user_id' => $user->id,
            ];

            // Handle error status
            if ($newStatus === OrderStatus::ERROR) {
                $updateData['error_reason'] = $errorReason;
                $updateData['error_created_at'] = now();
            } elseif ($oldStatus === OrderStatus::ERROR) {
                // Clear error fields when transitioning away from error status
                $updateData['error_reason'] = null;
                $updateData['error_created_at'] = null;
            }

            if ($wooSynced) {
                $updateData['synced_at'] = now();
            }

            // Keep delivery image at order level only for delivered proof.
            if ($newStatus === OrderStatus::ENTREGADO && $evidenceImagePath) {
                $updateData['delivery_image_path'] = $evidenceImagePath;
            }

            // Update order
            $order->update($updateData);

            // Create audit log
            $this->logStatusChange(
                $order,
                $oldStatus,
                $newStatus,
                $user,
                $errorReason,
                $evidenceImagePath,
                $wooStatus,
                $wooSynced,
                $ipAddress
            );

            OrderTimeline::query()->create([
                'order_id' => $order->id,
                'status' => $newStatus->value,
                'message' => $wooSynced
                    ? "Estado actualizado y sincronizado a Woo ({$wooStatus})"
                    : 'Estado actualizado solo en sistema interno',
                'source' => $wooSynced ? 'system+woo' : 'system',
                'occurred_at' => now('UTC'),
            ]);

            // Dispatch event for real-time updates via WebSockets
            OrderStatusChanged::dispatch(
                $order,
                $oldStatus->value,
                $newStatus->value,
                $user->name
            );

            return $order->refresh();
        });
    }

    /**
     * Create an audit log entry for the status change.
     */
    private function logStatusChange(
        Order $order,
        OrderStatus $oldStatus,
        OrderStatus $newStatus,
        User $user,
        ?string $errorReason = null,
        ?string $evidenceImagePath = null,
        ?string $wooStatus = null,
        bool $wooSynced = false,
        ?string $ipAddress = null
    ): OrderLog {
        $changes = [
            'status' => [
                'old' => $oldStatus->value,
                'new' => $newStatus->value,
            ],
            'actor' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => $user->role->value,
            ],
            'woo_sync' => [
                'applied' => $wooSynced,
                'status' => $wooStatus,
            ],
        ];

        // Track error reason if provided
        if ($errorReason) {
            $changes['error_reason'] = $errorReason;
        }

        if ($evidenceImagePath) {
            $changes['evidence_image_path'] = $evidenceImagePath;
        }

        $description = "{$user->name} ({$user->role->value}) moved order #{$order->id} from {$oldStatus->label()} to {$newStatus->label()}";
        if ($errorReason) {
            $description .= ". Reason: {$errorReason}";
        }
        if ($wooSynced && $wooStatus !== null) {
            $description .= ". Woo status synced to {$wooStatus}";
        } else {
            $description .= '. Woo status not modified';
        }

        return OrderLog::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'action' => 'status_changed',
            'old_status' => $oldStatus->value,
            'new_status' => $newStatus->value,
            'description' => $description,
            'changes' => $changes,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Get the transition history for an order.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getHistory(Order $order)
    {
        return $order->logs;
    }

    private function mapToWooStatus(OrderStatus $status): ?string
    {
        return match ($status) {
            OrderStatus::EN_PROCESO => 'processing',
            OrderStatus::ENTREGADO => 'completed',
            // Business rule: marking with X/error cancels in Woo.
            OrderStatus::ERROR => 'cancelled',
            default => null,
        };
    }

    private function syncWooOrderStatus(Order $order, string $wooStatus): void
    {
        $storeSlug = (string) ($order->store_slug ?? '');
        if ($storeSlug === '') {
            throw new InvalidArgumentException('Order has no store_slug, cannot sync status to WooCommerce.');
        }

        $externalId = (string) ($order->external_id ?? '');
        if ($externalId === '') {
            throw new InvalidArgumentException('Order has no external_id, cannot sync status to WooCommerce.');
        }

        try {
            $this->wooManager
                ->store($storeSlug)
                ->put('orders', $externalId, ['status' => $wooStatus]);
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('Could not update WooCommerce order status: '.$e->getMessage());
        }
    }

    /**
     * Check if an order error has exceeded the timeout (1 day).
     */
    public function hasErrorExpired(Order $order): bool
    {
        if ($order->status !== OrderStatus::ERROR || !$order->error_created_at) {
            return false;
        }

        return $order->error_created_at->addDay()->isPast();
    }

    /**
     * Automatically cancel orders with expired errors.
     */
    public function cancelExpiredErrors(User $systemUser): int
    {
        $expiredOrders = Order::where('status', OrderStatus::ERROR->value)
            ->whereNotNull('error_created_at')
            ->where('error_created_at', '<=', now()->subDay())
            ->get();

        $count = 0;
        foreach ($expiredOrders as $order) {
            try {
                $this->updateStatus(
                    $order,
                    OrderStatus::CANCELADO,
                    $systemUser,
                    'Automatically cancelled due to error timeout (1 day)',
                    null,
                    '127.0.0.1'
                );
                $count++;
            } catch (InvalidArgumentException $e) {
                // Log the error but continue processing
                \Log::warning("Could not auto-cancel order {$order->id}: {$e->getMessage()}");
            }
        }

        return $count;
    }
}
