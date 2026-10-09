<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ticket\GetBackOfficeTicketsRequest;
use App\Models\Charge;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\TicketItemModifier;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Back-office ticket history (view only): every ticket from every terminal and cashier, with
 * its lines, payments, refunds and merges. Tickets are created, changed, paid and refunded on
 * the POS; nothing here writes.
 */
class TicketController extends Controller
{
    public function getTickets(GetBackOfficeTicketsRequest $request): Response
    {
        $filters = array_filter($request->safe()->except('page'), fn ($value): bool => $value !== null && $value !== '');

        $page = Ticket::query()
            ->with(['createdBy:id,name', 'charges' => fn ($query) => $query->where('status', 'paid')->select(['id', 'ticket_id', 'payment_method'])])
            ->withCount(['items' => fn ($query) => $query->whereNull('voided_at')])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['shift_id'] ?? null, fn ($query, $shiftId) => $query->where('shift_id', $shiftId))
            ->when(
                $filters['payment_method'] ?? null,
                fn ($query, string $method) => $query->whereHas('charges', fn ($charges) => $charges->where('status', 'paid')->where('payment_method', $method))
            )
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when(
                $filters['search'] ?? null,
                fn ($query, string $search) => $query->where(fn ($inner) => $inner
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhereHas('receipts', fn ($receipts) => $receipts->where('receipt_number', 'like', "%{$search}%")))
            )
            ->latest()
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('TicketManagementPage', [
            'tickets' => $page->getCollection()->map(fn (Ticket $ticket): array => [
                ...$this->presentTicketSummary($ticket),
                'items_count' => $ticket->items_count,
                'payment_methods' => $ticket->charges->pluck('payment_method')->unique()->sort()->values(),
            ])->values(),
            'filters' => (object) $filters,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function getTicket(Ticket $ticket): Response
    {
        $ticket->load([
            'createdBy:id,name',
            'cancelledBy:id,name',
            'mergedBy:id,name',
            'shift:id,opened_at',
            'mergedInto:id,order_number,customer_name',
            'mergedTickets:id,merged_into_ticket_id,order_number,customer_name',
            'items' => fn ($query) => $query->orderBy('id'),
            'items.modifiers',
            'items.mergedFromTicket:id,order_number',
            'items.voidedBy:id,name',
            'items.voidedRequestedBy:id,name',
            'charges' => fn ($query) => $query->orderBy('id'),
            'charges.createdBy:id,name',
            'charges.receipt:id,charge_id,receipt_number',
            'refunds' => fn ($query) => $query->orderBy('requested_at')->orderBy('id'),
            'refunds.charge:id,payment_method',
            'refunds.requestedBy:id,name',
            'refunds.approvedBy:id,name',
            'refunds.items.ticketItem:id,item_name',
        ]);

        return Inertia::render('TicketDetailPage', [
            'ticket' => [
                ...$this->presentTicketSummary($ticket),
                'shift_opened_at' => $ticket->shift?->opened_at,
                'subtotal' => (float) $ticket->subtotal,
                'discount_amount' => (float) $ticket->discount_amount,
                'discount_percent' => (float) $ticket->discount_percent,
                'notes' => $ticket->notes,
                'cancelled_by' => $ticket->cancelledBy?->name,
                'merged_by' => $ticket->mergedBy?->name,
                'merged_into' => $ticket->mergedInto?->only(['id', 'order_number', 'customer_name']),
                'merged_from' => $ticket->mergedTickets->map(fn (Ticket $source): array => $source->only(['id', 'order_number', 'customer_name']))->values(),
            ],
            'items' => $ticket->items->map(fn (TicketItem $line): array => [
                'id' => $line->id,
                'item_name' => $line->item_name,
                'line_type' => $line->line_type,
                'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
                'notes' => $line->notes,
                'modifiers' => $line->modifiers->map(fn (TicketItemModifier $modifier): array => [
                    'name' => $modifier->name,
                    'price' => (float) $modifier->price,
                    'is_stockless_variant' => $modifier->is_stockless_variant,
                ])->values(),
                'is_stockless' => $line->is_stockless,
                'merged_from_order_number' => $line->mergedFromTicket?->order_number,
                'voided_at' => $line->voided_at,
                'voided_by' => $line->voidedBy?->name,
                'voided_requested_by' => $line->voidedRequestedBy?->name,
            ])->values(),
            'charges' => $ticket->charges->map(fn (Charge $charge): array => [
                'id' => $charge->id,
                'payment_method' => $charge->payment_method,
                'status' => $charge->status,
                'amount' => (float) $charge->amount,
                'tendered_amount' => $charge->tendered_amount === null ? null : (float) $charge->tendered_amount,
                'change_due' => $charge->change_due === null ? null : (float) $charge->change_due,
                'payment_reference' => $charge->payment_reference,
                'paid_at' => $charge->paid_at,
                'created_by' => $charge->createdBy?->name,
                'receipt_id' => $charge->receipt?->id,
                'receipt_number' => $charge->receipt?->receipt_number,
            ])->values(),
            'refunds' => $ticket->refunds->map(fn (Refund $refund): array => [
                'id' => $refund->id,
                'amount' => (float) $refund->amount,
                'status' => $refund->status,
                'payment_method' => $refund->charge?->payment_method,
                'reason' => $refund->reason,
                'requested_at' => $refund->requested_at,
                'requested_by' => $refund->requestedBy?->name,
                'approved_by' => $refund->approvedBy?->name,
                'items' => $refund->items->map(fn (RefundItem $refundItem): array => [
                    'item_name' => $refundItem->ticketItem?->item_name,
                    'quantity' => $refundItem->quantity,
                    'amount' => (float) $refundItem->amount,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * The fields shared by the list row and the detail header. Money as numbers. `ended_at` is
     * when the ticket stopped being open, whichever way it ended (paid, cancelled or merged).
     *
     * @return array<string, mixed>
     */
    private function presentTicketSummary(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'shift_id' => $ticket->shift_id,
            'order_number' => $ticket->order_number,
            'customer_name' => $ticket->customer_name,
            'order_type' => $ticket->order_type,
            'status' => $ticket->status,
            'terminal_id' => $ticket->terminal_id,
            'created_at' => $ticket->created_at,
            'ended_at' => $ticket->closed_at ?? $ticket->cancelled_at ?? $ticket->merged_at,
            'created_by' => $ticket->createdBy?->name,
            'total' => (float) $ticket->total,
        ];
    }
}
