<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BsaleService;
use Illuminate\Http\Request;

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

        $result = $this->bsaleService->getOrders($offset, $limit);

        return response()->json($result);
    }
}