<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Orders;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\SyncOrdersRequest;
use App\Http\Requests\Orders\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Models\OrderSyncRun;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderStatusService $statusService,
    ) {
    }

    /**
     * Get all orders with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::query()
            ->with(['user', 'logs' => fn ($q) => $q->latest()->limit(3)]);

        $stores = (string) $request->query('stores', '');
        if (trim($stores) !== '') {
            $slugs = array_values(array_filter(array_map('trim', explode(',', $stores))));
            if (!empty($slugs)) {
                $query->whereIn('store_slug', $slugs);
            }
        }

        $status = (string) $request->query('status', '');
        if ($status !== '') {
            $query->where('status', $status);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($sub) use ($search): void {
                $sub->where('external_id', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        $perPage = max(10, min((int) $request->query('per_page', 50), 200));

        $orders = $query
            ->latest('id')
            ->paginate($perPage);

        return response()->json($orders);
    }

    /**
     * Get a single order with full history.
     */
    public function show(Order $order): JsonResponse
    {
        $order->load(['user', 'timelines', 'logs' => fn ($q) => $q->latest()]);

        return response()->json($order);
    }

    /**
     * Get audit logs for an order.
     */
    public function logs(Order $order): JsonResponse
    {
        $logs = $order->logs()->paginate(50);

        return response()->json($logs);
    }

    /**
     * Sync orders from WooCommerce.
     */
    public function sync(SyncOrdersRequest $request): JsonResponse
    {
        try {
            $run = $this->orderService->syncFromWooCommerce($request);

            return response()->json([
                'message' => 'Sync queued',
                'run' => [
                    'id' => $run->id,
                    'status' => $run->status,
                    'stores' => $run->stores,
                    'from_date' => optional($run->from_date)->toIso8601String(),
                    'to_date' => optional($run->to_date)->toIso8601String(),
                    'created_at' => optional($run->created_at)->toIso8601String(),
                ],
            ], 202);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function syncStatus(OrderSyncRun $syncRun): JsonResponse
    {
        return response()->json([
            'run' => [
                'id' => $syncRun->id,
                'status' => $syncRun->status,
                'stores' => $syncRun->stores,
                'total_orders' => $syncRun->total_orders,
                'synced_orders' => $syncRun->synced_orders,
                'failed_stores' => $syncRun->failed_stores ?? [],
                'error_message' => $syncRun->error_message,
                'from_date' => optional($syncRun->from_date)->toIso8601String(),
                'to_date' => optional($syncRun->to_date)->toIso8601String(),
                'started_at' => optional($syncRun->started_at)->toIso8601String(),
                'finished_at' => optional($syncRun->finished_at)->toIso8601String(),
                'created_at' => optional($syncRun->created_at)->toIso8601String(),
                'updated_at' => optional($syncRun->updated_at)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Update order status with validation and audit logging.
     */
    public function updateStatus(
        Order $order,
        UpdateOrderStatusRequest $request
    ): JsonResponse {
        try {
            $user = auth()->user();

            // Handle image upload if provided
            $evidenceImagePath = null;
            if ($request->hasEvidenceImage()) {
                $imagePathDir = $request->getStatus() === OrderStatus::ENTREGADO
                    ? 'orders/deliveries'
                    : 'orders/errors';

                $evidenceImagePath = $request->file($request->evidenceImageField())
                    ->store($imagePathDir, 'public');
            }

            // Update status with full validation
            $updatedOrder = $this->statusService->updateStatus(
                order: $order,
                newStatus: $request->getStatus(),
                user: $user,
                errorReason: $request->getErrorReason(),
                evidenceImagePath: $evidenceImagePath,
                ipAddress: $request->ip()
            );

            return response()->json([
                'message' => 'Order status updated successfully',
                'order' => $updatedOrder->load('logs'),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'INVALID_STATUS_TRANSITION',
            ], 422);
        } catch (\Throwable $e) {
            \Log::error('Order status update error', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while updating order status',
            ], 500);
        }
    }

    /**
     * Get order status transition history.
     */
    public function history(Order $order): JsonResponse
    {
        $history = $this->statusService->getHistory($order);

        return response()->json($history);
    }

    /**
     * Get available status transitions for current user.
     */
    public function availableTransitions(Order $order): JsonResponse
    {
        $user = auth()->user();
        $currentStatus = $order->status;

        // Get valid transitions from the current status
        $validTransitions = OrderStatus::validTransitions()[$currentStatus->value] ?? [];

        // Filter by user permissions
        $availableTransitions = collect($validTransitions)
            ->filter(fn ($status) => $user->canUpdateOrderStatus($status))
            ->map(fn ($status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ])
            ->values();

        return response()->json([
            'current_status' => [
                'value' => $currentStatus->value,
                'label' => $currentStatus->label(),
            ],
            'available_transitions' => $availableTransitions,
        ]);
    }
}
