<?php

namespace App\Events\Kds;

use App\Models\TicketItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ItemUncompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public TicketItem $ticketItem) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('kds.orders')];
    }

    public function broadcastAs(): string
    {
        return 'item.uncompleted';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticketItem->ticket_id,
            'ticket_item_id' => $this->ticketItem->id,
        ];
    }
}
