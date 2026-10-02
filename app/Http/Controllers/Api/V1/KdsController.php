<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kds\ToggleItemCompletionRequest;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Services\KdsService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;

class KdsController extends Controller
{
    use ApiResponses;

    public function __construct(
        private KdsService $kdsService,
        private TicketService $ticketService,
    ) {}

    public function getOrders(): JsonResponse
    {
        return $this->success($this->kdsService->GetOpenOrders());
    }

    public function setItemCompletion(ToggleItemCompletionRequest $request, TicketItem $ticketItem): JsonResponse
    {
        $updated = $this->ticketService->SetItemCompletion($ticketItem, (bool) $request->validated('completed'));

        return $this->success(['ticket_item_id' => $updated->id, 'completed' => $updated->isCompleted()]);
    }

    public function completeTicket(Ticket $ticket): JsonResponse
    {
        $completed = $this->ticketService->CompleteTicketItems($ticket);

        return $this->success(['ticket_id' => $completed->id, 'completed' => true]);
    }
}
