<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TicketPolicy
{
    /**
     * Whether the user may see or act on this ticket from a POS: a cashier only on tickets they
     * opened, a manager or admin on any. Someone else's ticket is a 404, not a 403, so its
     * existence isn't revealed.
     */
    public function manage(User $user, Ticket $ticket): Response
    {
        return $user->isAdminOrManager() || (int) $ticket->created_by === (int) $user->id
            ? Response::allow()
            : Response::denyAsNotFound('Resource not found.');
    }
}
