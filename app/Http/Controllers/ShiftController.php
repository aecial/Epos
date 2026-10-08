<?php

namespace App\Http\Controllers;

use App\Models\Refund;
use App\Models\Shift;
use App\Models\ShiftTransaction;
use App\Services\ShiftService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Back-office shift history and close report (view only). Opening and closing a shift and
 * recording cash movements stay on the POS, at the drawer.
 */
class ShiftController extends Controller
{
    public function __construct(private ShiftService $shiftService) {}

    public function getShifts(): Response
    {
        $page = Shift::query()
            ->with(['openedBy:id,name', 'closedBy:id,name'])
            ->latest('opened_at')
            ->latest('id')
            ->paginate(20);

        return Inertia::render('ShiftManagementPage', [
            'shifts' => $page->getCollection()->map(fn (Shift $shift): array => $this->presentShift($shift))->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function getShift(Shift $shift): Response
    {
        $shift->load(['openedBy:id,name', 'closedBy:id,name']);

        $ticketCounts = $shift->tickets()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('ShiftDetailPage', [
            'shift' => $this->presentShift($shift),
            'transactions' => $shift->transactions()
                ->whereNull('deleted_at')
                ->with('createdBy:id,name')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (ShiftTransaction $transaction): array => [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => (float) $transaction->amount,
                    'reason' => $transaction->reason,
                    'created_at' => $transaction->created_at,
                    'created_by' => $transaction->createdBy?->name,
                ]),
            'refunds' => $shift->refunds()
                ->with(['charge:id,payment_method', 'requestedBy:id,name', 'approvedBy:id,name'])
                ->orderBy('requested_at')
                ->orderBy('id')
                ->get()
                ->map(fn (Refund $refund): array => [
                    'id' => $refund->id,
                    'amount' => (float) $refund->amount,
                    'status' => $refund->status,
                    'payment_method' => $refund->charge?->payment_method,
                    'reason' => $refund->reason,
                    'requested_at' => $refund->requested_at,
                    'requested_by' => $refund->requestedBy?->name,
                    'approved_by' => $refund->approvedBy?->name,
                ]),
            'ticketCounts' => collect(['open', 'paid', 'cancelled', 'merged'])
                ->mapWithKeys(fn (string $status): array => [$status => (int) ($ticketCounts[$status] ?? 0)]),
        ]);
    }

    /**
     * Money as numbers. An open shift gets live totals; a closed shift its closing snapshot,
     * which doesn't store cash refunds separately - they're derived from the snapshot's own
     * expected-cash formula so the breakdown always adds up to what was recorded at close.
     *
     * @return array<string, mixed>
     */
    private function presentShift(Shift $shift): array
    {
        if ($shift->isOpen()) {
            $totals = $this->shiftService->ComputeTotals($shift);
        } else {
            $totals = collect(['total_revenue', 'total_cash', 'total_gcash', 'total_additions', 'total_expenses', 'total_refunds', 'expected_cash'])
                ->mapWithKeys(fn (string $column): array => [$column => $shift->{$column} === null ? null : (float) $shift->{$column}])
                ->all();

            $totals['total_cash_refunds'] = $totals['expected_cash'] === null ? null : round(
                (float) $shift->starting_cash + (float) $totals['total_cash'] + (float) $totals['total_additions']
                - (float) $totals['total_expenses'] - $totals['expected_cash'],
                2
            );
        }

        return [
            'id' => $shift->id,
            'status' => $shift->status,
            'opened_at' => $shift->opened_at,
            'closed_at' => $shift->closed_at,
            'opened_by' => $shift->openedBy?->name,
            'closed_by' => $shift->closedBy?->name,
            'starting_cash' => (float) $shift->starting_cash,
            ...$totals,
            'closing_cash' => $shift->closing_cash === null ? null : (float) $shift->closing_cash,
            'discrepancy' => $shift->discrepancy === null ? null : (float) $shift->discrepancy,
        ];
    }
}
