<?php

namespace App\Services;

use App\Exceptions\ActiveShiftExistsException;
use App\Exceptions\NoActiveShiftException;
use App\Exceptions\OpenTicketsExistException;
use App\Models\Charge;
use App\Models\Refund;
use App\Models\Shift;
use App\Models\ShiftTransaction;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    public function OpenShift(User $openedBy, float $startingCash): Shift
    {
        if (Shift::query()->where('status', 'open')->exists()) {
            throw new ActiveShiftExistsException;
        }

        try {
            return Shift::create([
                'opened_by' => $openedBy->id,
                'status' => 'open',
                'starting_cash' => $startingCash,
                'opened_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Backstop: the is_open generated-column unique index catches a race the
            // exists() check above missed between two concurrent opens.
            if ((int) $e->getCode() === 23000) {
                throw new ActiveShiftExistsException;
            }

            throw $e;
        }
    }

    public function ActiveShift(): ?Shift
    {
        return Shift::query()->where('status', 'open')->first();
    }

    /**
     * Totals computed live from charges/refunds/transactions. Used for the running
     * shift display while open, and as the basis for the snapshot written at close.
     *
     * @return array{total_revenue: float, total_cash: float, total_gcash: float, total_additions: float, total_expenses: float, total_refunds: float, total_cash_refunds: float, expected_cash: float}
     */
    public function ComputeTotals(Shift $shift): array
    {
        $totalRevenue = (float) Ticket::query()
            ->where('shift_id', $shift->id)
            ->where('status', 'paid')
            ->sum('total');

        $totalCash = (float) Charge::query()
            ->join('tickets', 'tickets.id', '=', 'charges.ticket_id')
            ->where('tickets.shift_id', $shift->id)
            ->where('charges.payment_method', 'cash')
            ->where('charges.status', 'paid')
            ->sum('charges.amount');

        $totalGcash = (float) Charge::query()
            ->join('tickets', 'tickets.id', '=', 'charges.ticket_id')
            ->where('tickets.shift_id', $shift->id)
            ->where('charges.payment_method', 'gcash')
            ->where('charges.status', 'paid')
            ->sum('charges.amount');

        $totalAdditions = (float) ShiftTransaction::query()
            ->where('shift_id', $shift->id)
            ->where('type', 'addition')
            ->whereNull('deleted_at')
            ->sum('amount');

        $totalExpenses = (float) ShiftTransaction::query()
            ->where('shift_id', $shift->id)
            ->where('type', 'expense')
            ->whereNull('deleted_at')
            ->sum('amount');

        $totalRefunds = (float) Refund::query()
            ->where('shift_id', $shift->id)
            ->where('status', 'approved')
            ->sum('amount');

        // Only cash refunds hit the drawer; gcash refunds never touched physical cash.
        $totalCashRefunds = (float) Refund::query()
            ->join('charges', 'charges.id', '=', 'refunds.charge_id')
            ->where('refunds.shift_id', $shift->id)
            ->where('refunds.status', 'approved')
            ->where('charges.payment_method', 'cash')
            ->sum('refunds.amount');

        $expectedCash = round(
            (float) $shift->starting_cash + $totalCash + $totalAdditions - $totalExpenses - $totalCashRefunds,
            2
        );

        return [
            'total_revenue' => round($totalRevenue, 2),
            'total_cash' => round($totalCash, 2),
            'total_gcash' => round($totalGcash, 2),
            'total_additions' => round($totalAdditions, 2),
            'total_expenses' => round($totalExpenses, 2),
            'total_refunds' => round($totalRefunds, 2),
            'total_cash_refunds' => round($totalCashRefunds, 2),
            'expected_cash' => $expectedCash,
        ];
    }

    public function CloseShift(Shift $shift, User $closedBy, float $closingCash): Shift
    {
        return DB::transaction(function () use ($shift, $closedBy, $closingCash): Shift {
            $lockedShift = Shift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $lockedShift->isOpen()) {
                throw new NoActiveShiftException('Shift is already closed.');
            }

            $hasOpenTickets = Ticket::query()
                ->where('shift_id', $lockedShift->id)
                ->where('status', 'open')
                ->exists();

            if ($hasOpenTickets) {
                throw new OpenTicketsExistException;
            }

            $totals = $this->ComputeTotals($lockedShift);

            $lockedShift->update([
                'status' => 'closed',
                'closed_by' => $closedBy->id,
                'closed_at' => now(),
                'closing_cash' => $closingCash,
                'total_revenue' => $totals['total_revenue'],
                'total_cash' => $totals['total_cash'],
                'total_gcash' => $totals['total_gcash'],
                'total_additions' => $totals['total_additions'],
                'total_expenses' => $totals['total_expenses'],
                'total_refunds' => $totals['total_refunds'],
                'expected_cash' => $totals['expected_cash'],
                'discrepancy' => round($closingCash - $totals['expected_cash'], 2),
            ]);

            return $lockedShift;
        });
    }
}
