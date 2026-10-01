<?php

namespace App\Events\Kds;

use App\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class TicketMerged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<int, int>  $removedTicketIds
     * @param  array<int, string>  $removedOrderNumbers
     */
    public function __construct(
        public Ticket $ticket,
        public array $removedTicketIds,
        public array $removedOrderNumbers,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('kds.orders')];
    }

    public function broadcastAs(): string
    {
        return 'ticket.merged';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'order_number' => $this->ticket->order_number,
            'removed_ticket_ids' => $this->removedTicketIds,
            'removed_order_numbers' => $this->removedOrderNumbers,
        ];
    }
}
