<?php

namespace App\Http\Controllers;

use App\Services\WooCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WooCommerceController extends Controller
{
    public function __construct(
        private readonly WooCommerceService $woo
    ) {}

    /**
     * GET /api/woo/orders
     * 
     */
    public function getOrders(Request $request): JsonResponse
    {
        try {
            // WooCommerce API limits per_page to max 100
            $perPage = (int) $request->get('per_page', 20);
            $perPage = min($perPage, 100);
            $perPage = max($perPage, 1);

            $result = $this->woo->getPaginated(
                endpoint: 'orders',
                page    : (int) $request->get('page', 1),
                perPage : $perPage,
                params  : $request->only(['status', 'customer', 'search', 'orderby', 'order'])
            );

            return response()->json($result);

        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    //
     private function errorResponse(Throwable $e): JsonResponse
    {
        $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600)
            ? $e->getCode()
            : 500;

        return response()->json([
            'error'   => true,
            'message' => $e->getMessage(),
        ], $statusCode);
    }
}
