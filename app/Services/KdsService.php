<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\TicketItemModifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class KdsService
{
    /**
     * Every open ticket, FIFO by created_at, each with its kitchen-relevant lines. No prices,
     * no terminal_id anywhere in the output - the KDS feed is kitchen-only (CLAUDE.md, ENHANCED_SPEC.md §8).
     *
     * @return array<int, array<string, mixed>>
     */
    public function GetOpenOrders(): array
    {
        $tickets = Ticket::query()
            ->where('status', 'open')
            ->orderBy('created_at')
            ->get();

        // One query for every ticket's items instead of N+1 per card.
        $itemsByTicket = $this->kitchenItemsQuery()
            ->whereIn('ticket_id', $tickets->pluck('id'))
            ->get()
            ->groupBy('ticket_id');

        return $tickets
            ->map(fn (Ticket $ticket): array => $this->presentCard($ticket, $itemsByTicket->get($ticket->id, collect())))
            ->values()
            ->all();
    }

    /**
     * Single-ticket shaping for broadcast events - always re-queries, never trusts a
     * possibly-stale loaded relation on the model passed in.
     *
     * @return array<string, mixed>
     */
    public function PresentTicketCard(Ticket $ticket): array
    {
        $items = $this->kitchenItemsQuery()->where('ticket_id', $ticket->id)->get();

        return $this->presentCard($ticket, $items);
    }

    /**
     * Non-voided, kitchen-visible lines (fee lines are hidden per CLAUDE.md's explicit rule),
     * in the order they were added.
     */
    private function kitchenItemsQuery(): Builder
    {
        return TicketItem::query()
            ->whereNull('voided_at')
            ->whereIn('line_type', ['item', 'custom'])
            ->orderBy('created_at')
            ->with('modifiers');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCard(Ticket $ticket, Collection $items): array
    {
        return [
            'ticket_id' => $ticket->id,
            'order_number' => $ticket->order_number,
            'customer_name' => $ticket->customer_name,
            'order_type' => $ticket->order_type,
            'created_at' => $ticket->created_at,
            'items' => $items->map(fn (TicketItem $item): array => [
                'ticket_item_id' => $item->id,
                'item_name' => $item->item_name,
                'quantity' => $item->quantity,
                'notes' => $item->notes,
                'completed' => $item->isCompleted(),
                'modifiers' => $item->modifiers
                    ->map(fn (TicketItemModifier $modifier): array => ['name' => $modifier->name])
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }
}
