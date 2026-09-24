<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\NoActiveShiftException;
use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\AddTicketItemRequest;
use App\Http\Requests\Ticket\CreateTicketRequest;
use App\Http\Requests\Ticket\GetTicketsRequest;
use App\Http\Requests\Ticket\MergeTicketsRequest;
use App\Http\Requests\Ticket\SetTicketDiscountRequest;
use App\Http\Requests\Ticket\VoidTicketItemRequest;
use App\Models\Item;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use App\Services\ShiftService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;

class TicketController extends Controller
{
    use ApiResponses;

    public function __construct(
        private TicketService $ticketService,
        private ShiftService $shiftService,
    ) {}

    public function getTickets(GetTicketsRequest $request): JsonResponse
    {
        $shift = $request->validated('shift_id') !== null
            ? Shift::query()->findOrFail($request->validated('shift_id'))
            : $this->shiftService->ActiveShift();

        if ($shift === null) {
            return $this->error('No active shift is open.', status: 404);
        }

        $tickets = Ticket::query()
            ->where('shift_id', $shift->id)
            ->when(
                $request->validated('terminal_id'),
                fn ($query, $terminalId) => $query->where('terminal_id', $terminalId)
            )
            ->where('status', $request->validated('status', 'open'))
            ->withCount(['items' => fn ($query) => $query->whereNull('voided_at')])
            ->orderBy('created_at')
            ->get();

        return $this->success($tickets);
    }

    public function getTicket(Ticket $ticket): JsonResponse
    {
        return $this->success($ticket->load(['items.modifiers', 'charges.receipt', 'mergedTickets']));
    }

    public function createTicket(CreateTicketRequest $request): JsonResponse
    {
        $shift = $this->shiftService->ActiveShift();

        if ($shift === null) {
            throw new NoActiveShiftException;
        }

        $ticket = $this->ticketService->CreateTicket(
            $shift,
            $request->user(),
            $request->validated('terminal_id'),
            $request->validated('customer_name'),
            $request->validated('order_type'),
        );

        return $this->success($ticket, 201);
    }

    public function addItem(AddTicketItemRequest $request, Ticket $ticket): JsonResponse
    {
        $item = Item::query()->findOrFail($request->validated('item_id'));

        $ticketItem = $this->ticketService->AddItem(
            $ticket,
            $item,
            (int) $request->validated('quantity'),
            $request->validated('modifier_ids', []),
            $request->validated('notes'),
        );

        return $this->success($ticket->fresh(['items.modifiers']), 201, ['ticket_item_id' => $ticketItem->id]);
    }

    public function voidItem(VoidTicketItemRequest $request, Ticket $ticket, TicketItem $ticketItem): JsonResponse
    {
        $this->assertBelongsToTicket($ticket, $ticketItem);

        $approver = User::query()->findOrFail($request->validated('approver_id'));

        $this->ticketService->VoidItem($ticketItem, $request->user(), $approver, $request->validated('passcode'));

        return $this->success($ticket->fresh(['items.modifiers']));
    }

    public function setDiscount(SetTicketDiscountRequest $request, Ticket $ticket): JsonResponse
    {
        $updated = $this->ticketService->SetDiscount(
            $ticket,
            (float) $request->validated('discount_amount', 0),
            (float) $request->validated('discount_percent', 0),
        );

        return $this->success($updated);
    }

    public function mergeTickets(MergeTicketsRequest $request, Ticket $ticket): JsonResponse
    {
        $merged = $this->ticketService->MergeTickets(
            $ticket,
            $request->validated('merge_from_ticket_ids'),
            $request->user(),
        );

        return $this->success($merged);
    }

    public function cancelTicket(Ticket $ticket): JsonResponse
    {
        $cancelled = $this->ticketService->CancelTicket($ticket, auth()->user());

        return $this->success($cancelled);
    }

    private function assertBelongsToTicket(Ticket $ticket, TicketItem $ticketItem): void
    {
        abort_if((int) $ticketItem->ticket_id !== (int) $ticket->id, 404);
    }
}
