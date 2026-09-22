<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Refund\CreateRefundRequest;
use App\Http\Requests\Refund\DecideRefundRequest;
use App\Http\Requests\Refund\GetRefundsRequest;
use App\Models\Charge;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;

class RefundController extends Controller
{
    use ApiResponses;

    public function __construct(private RefundService $refundService) {}

    public function getRefunds(GetRefundsRequest $request): JsonResponse
    {
        $refunds = Refund::query()
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->validated('shift_id'), fn ($query, $shiftId) => $query->where('shift_id', $shiftId))
            ->with('items')
            ->latest('requested_at')
            ->get();

        return $this->success($refunds);
    }

    public function getRefund(Refund $refund): JsonResponse
    {
        return $this->success($refund->load('items'));
    }

    public function requestRefund(CreateRefundRequest $request): JsonResponse
    {
        $ticket = Ticket::query()->findOrFail($request->validated('ticket_id'));
        $charge = Charge::query()->findOrFail($request->validated('charge_id'));

        $refund = $this->refundService->RequestRefund(
            $ticket,
            $charge,
            $request->user(),
            $request->validated('items'),
            $request->validated('reason'),
        );

        return $this->success($refund, 201);
    }

    public function approveRefund(DecideRefundRequest $request, Refund $refund): JsonResponse
    {
        $approver = User::query()->findOrFail($request->validated('approver_id'));

        $approved = $this->refundService->ApproveRefund($refund, $approver, $request->validated('passcode'));

        return $this->success($approved);
    }

    public function rejectRefund(DecideRefundRequest $request, Refund $refund): JsonResponse
    {
        $approver = User::query()->findOrFail($request->validated('approver_id'));

        $rejected = $this->refundService->RejectRefund($refund, $approver, $request->validated('passcode'));

        return $this->success($rejected);
    }
}
