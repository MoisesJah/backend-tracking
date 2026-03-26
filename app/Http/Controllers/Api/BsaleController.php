<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BsaleService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class BsaleController extends Controller
{
    protected $bsaleService;

    public function __construct(BsaleService $bsaleService)
    {
        $this->bsaleService = $bsaleService;
    }

    public function index(Request $request)
    {
        $offset = $request->query('offset', 0);
        $limit = $request->query('limit', 50);

        try {
            $result = $this->bsaleService->getOrders($offset, $limit);

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'No se pudo conectar con Bsale. Revisa BSALE_BASE_URL y la conectividad.',
            ], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Error inesperado al obtener ordenes de Bsale.',
            ], 500);
        }
    }
}