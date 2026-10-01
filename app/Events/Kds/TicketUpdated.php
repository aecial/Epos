<?php

namespace App\Events\Kds;

use App\Models\Ticket;
use App\Services\KdsService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class TicketUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Ticket $ticket) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('kds.orders')];
    }

    public function broadcastAs(): string
    {
        return 'ticket.updated';
    }

    public function broadcastWith(): array
    {
        return app(KdsService::class)->PresentTicketCard($this->ticket);
    }
}
