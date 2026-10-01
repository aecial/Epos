<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\KdsService;
use Illuminate\Http\JsonResponse;

class KdsController extends Controller
{
    use ApiResponses;

    public function __construct(private KdsService $kdsService) {}

    public function getOrders(): JsonResponse
    {
        return $this->success($this->kdsService->GetOpenOrders());
    }
}
