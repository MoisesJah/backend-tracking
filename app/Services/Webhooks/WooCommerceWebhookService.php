<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Models\Order;
use App\Models\OrderTimeline;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class WooCommerceWebhookService
{
    public function handle(Request $request): void
    {
        $this->assertSignatureIsValid($request);

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $externalId = (string) ($payload['id'] ?? '');
        if ($externalId === '') {
            return;
        }

        // traducir estados de WooCommerce a nuestro enum
        $rawStatus = strtolower((string) ($payload['status'] ?? ''));
        $status = $this->mapStatus($rawStatus);

        /** @var Order $order */
        $order = Order::query()->firstOrCreate(
            ['store_slug' => 'legacy', 'external_id' => $externalId],
            [
                'store_slug' => 'legacy',
                'status' => $status,
                'currency' => (string) ($payload['currency'] ?? 'USD'),
                'total' => (float) ($payload['total'] ?? 0),
            ],
        );

        OrderTimeline::query()->create([
            'order_id' => $order->getKey(),
            'status' => $status,
            'message' => 'Webhook WooCommerce',
            'source' => 'webhook',
            'occurred_at' => Carbon::now('UTC'),
        ]);
    }

    private function assertSignatureIsValid(Request $request): void
    {
        $secret = (string) config('woocommerce.webhook_secret', '');
        if ($secret === '') {
            return;
        }

        $header = (string) $request->header('X-WC-Webhook-Signature', '');
        if ($header === '') {
            throw new RuntimeException('Firma de webhook ausente.');
        }

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (! hash_equals($expected, $header)) {
            throw new RuntimeException('Firma de webhook inválida.');
        }
    }

    /**
     * Map WooCommerce status strings into our OrderStatus enum.
     */
    private function mapStatus(string $status): \App\Enums\OrderStatus
    {
        return match ($status) {
            'processing' => \App\Enums\OrderStatus::EN_PROCESO,
            'completed'  => \App\Enums\OrderStatus::ENTREGADO,
            'cancelled', 'cancel' => \App\Enums\OrderStatus::CANCELADO,
            default => \App\Enums\OrderStatus::EN_PROCESO,
        };
    }
}

