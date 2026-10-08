<?php

namespace App\Http\Controllers;

use App\Http\Requests\Refund\GetBackOfficeRefundsRequest;
use App\Models\Refund;
use App\Models\RefundItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Back-office refund history (view only). Refunds are requested, approved and rejected on the
 * POS (a manager/admin passcode decides them); this page only shows them: what's still waiting,
 * the full history, and who requests and decides refunds how often.
 */
class RefundController extends Controller
{
    public function getRefunds(GetBackOfficeRefundsRequest $request): Response
    {
        $filters = array_filter($request->safe()->except('page'), fn ($value): bool => $value !== null && $value !== '');

        $page = $this->filtered($filters)
            ->with($this->relations())
            ->latest('requested_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // The cards describe the filtered period/method/search across every status, so a status
        // filter narrows the list without hiding the approved/rejected/pending split.
        $forSummary = $this->filtered(array_diff_key($filters, ['status' => true]))
            ->with(['requestedBy:id,name', 'approvedBy:id,name', 'charge:id,payment_method'])
            ->get(['id', 'status', 'amount', 'charge_id', 'requested_by', 'approved_by']);

        return Inertia::render('RefundManagementPage', [
            'pending' => Refund::query()
                ->where('status', 'pending')
                ->with($this->relations())
                ->oldest('requested_at')
                ->oldest('id')
                ->get()
                ->map(fn (Refund $refund): array => $this->presentRefund($refund)),
            'refunds' => $page->getCollection()->map(fn (Refund $refund): array => $this->presentRefund($refund))->values(),
            'summary' => $this->summarize($forSummary),
            'filters' => (object) $filters,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        return Refund::query()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when(
                $filters['payment_method'] ?? null,
                fn ($query, string $method) => $query->whereHas('charge', fn ($charge) => $charge->where('payment_method', $method))
            )
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->where('requested_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->where('requested_at', '<=', Carbon::parse($date)->endOfDay()))
            ->when(
                $filters['search'] ?? null,
                fn ($query, string $search) => $query->where(fn ($inner) => $inner
                    ->whereHas('ticket', fn ($ticket) => $ticket
                        ->where('order_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%"))
                    ->orWhereHas('charge.receipt', fn ($receipt) => $receipt->where('receipt_number', 'like', "%{$search}%")))
            );
    }

    /**
     * @return array<int|string, mixed>
     */
    private function relations(): array
    {
        return [
            'ticket:id,order_number,customer_name',
            'charge:id,payment_method',
            'charge.receipt:id,charge_id,receipt_number',
            'requestedBy:id,name',
            'approvedBy:id,name',
            'items.ticketItem:id,item_name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRefund(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'status' => $refund->status,
            'amount' => (float) $refund->amount,
            'payment_method' => $refund->charge?->payment_method,
            'receipt_number' => $refund->charge?->receipt?->receipt_number,
            'reason' => $refund->reason,
            'requested_at' => $refund->requested_at,
            'requested_by' => $refund->requestedBy?->name,
            // approved_by/approved_at record whoever decided it, approved or rejected.
            'decided_at' => $refund->approved_at,
            'decided_by' => $refund->approvedBy?->name,
            'ticket' => $refund->ticket?->only(['id', 'order_number', 'customer_name']),
            'items' => $refund->items->map(fn (RefundItem $refundItem): array => [
                'item_name' => $refundItem->ticketItem?->item_name,
                'quantity' => $refundItem->quantity,
                'amount' => (float) $refundItem->amount,
            ])->values(),
        ];
    }

    /**
     * Totals by status and payment method, then per person: who asks for refunds and who
     * decides them. Rejected attempts are counted alongside - they're often the telling part.
     *
     * @param  Collection<int, Refund>  $refunds
     * @return array<string, mixed>
     */
    private function summarize(Collection $refunds): array
    {
        $approved = $refunds->where('status', 'approved');
        $total = fn (Collection $set): float => round((float) $set->sum('amount'), 2);

        return [
            'approved_count' => $approved->count(),
            'approved_total' => $total($approved),
            'approved_cash' => $total($approved->filter(fn (Refund $refund): bool => $refund->charge?->payment_method === 'cash')),
            'approved_gcash' => $total($approved->filter(fn (Refund $refund): bool => $refund->charge?->payment_method === 'gcash')),
            'rejected_count' => $refunds->where('status', 'rejected')->count(),
            'rejected_total' => $total($refunds->where('status', 'rejected')),
            'pending_count' => $refunds->where('status', 'pending')->count(),
            'pending_total' => $total($refunds->where('status', 'pending')),
            'by_requester' => $refunds
                ->groupBy('requested_by')
                ->map(fn (Collection $set): array => [
                    'name' => $set->first()->requestedBy?->name ?? 'Unknown',
                    'requested' => $set->count(),
                    'approved' => $set->where('status', 'approved')->count(),
                    'approved_total' => $total($set->where('status', 'approved')),
                    'rejected' => $set->where('status', 'rejected')->count(),
                    'pending' => $set->where('status', 'pending')->count(),
                ])
                ->sortByDesc('requested')
                ->values(),
            'by_decider' => $refunds
                ->whereNotNull('approved_by')
                ->groupBy('approved_by')
                ->map(fn (Collection $set): array => [
                    'name' => $set->first()->approvedBy?->name ?? 'Unknown',
                    'approved' => $set->where('status', 'approved')->count(),
                    'approved_total' => $total($set->where('status', 'approved')),
                    'rejected' => $set->where('status', 'rejected')->count(),
                ])
                ->sortByDesc('approved')
                ->values(),
        ];
    }
}
