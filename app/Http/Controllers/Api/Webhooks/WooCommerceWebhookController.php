<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Webhooks\WooCommerceWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WooCommerceWebhookController extends Controller
{
    public function __construct(
        private readonly WooCommerceWebhookService $service,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $this->service->handle($request);

        return response()->json(['ok' => true]);
    }
}
