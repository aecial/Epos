<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TicketPolicy
{
    /**
     * Back-office ticket history (view only): admin/manager. Tickets are created and changed on
     * the POS, so there are no create/update/delete abilities here.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdminOrManager();
    }

    public function view(User $user): bool
    {
        return $user->isAdminOrManager();
    }

    /**
     * Bumping kitchen orders from the back office's Kitchen Orders page: admin/manager. The KDS
     * tablet bumps through the API with its own kds-scoped token instead.
     */
    public function bumpKitchenOrders(User $user): bool
    {
        return $user->isAdminOrManager();
    }

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
