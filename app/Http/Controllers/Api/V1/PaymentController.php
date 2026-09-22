<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ChargeTicketRequest;
use App\Models\Ticket;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    use ApiResponses;

    public function __construct(private PaymentService $paymentService) {}

    public function chargeTicket(ChargeTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $paid = $this->paymentService->ChargeTicket(
            $ticket,
            $request->user(),
            $request->validated('charges'),
        );

        return $this->success($paid->load('charges'));
    }
}
