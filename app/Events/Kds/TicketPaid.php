<?php

namespace App\Events\Kds;

use App\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class TicketPaid implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Ticket $ticket) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('kds.orders')];
    }

    public function broadcastAs(): string
    {
        return 'ticket.paid';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'order_number' => $this->ticket->order_number,
        ];
    }
}
