<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketItem;
use App\Services\KdsService;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Back-office view of the kitchen feed: the same cards the KDS tablet gets from
 * GET /api/v1/kds/orders, refreshed by polling. A manager/admin can bump from here too, through
 * the same TicketService methods (and broadcasts) as the tablet.
 */
class KitchenOrderController extends Controller
{
    public function __construct(
        private KdsService $kdsService,
        private TicketService $ticketService,
    ) {}

    public function getOrders(): Response
    {
        return Inertia::render('KitchenOrdersPage', [
            'orders' => $this->kdsService->GetOpenOrders(),
        ]);
    }

    public function completeItem(TicketItem $ticketItem): RedirectResponse
    {
        try {
            $this->ticketService->SetItemCompletion($ticketItem, true);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function completeTicket(Ticket $ticket): RedirectResponse
    {
        try {
            $this->ticketService->CompleteTicketItems($ticket);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }
}
