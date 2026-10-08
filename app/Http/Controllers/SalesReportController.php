<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sales\GetSalesReportRequest;
use App\Services\SalesReportService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Back-office Items Sold report: what sold in a period (default today), its sales, cost and
 * profit, then expenses and net profit. Live - tickets count the moment they're paid, whether
 * or not their shift has closed.
 */
class SalesReportController extends Controller
{
    public function __construct(private SalesReportService $salesReportService) {}

    public function getItemsSold(GetSalesReportRequest $request): Response
    {
        $today = Carbon::today()->toDateString();
        $dateFrom = $request->validated('date_from') ?? $request->validated('date_to') ?? $today;
        $dateTo = $request->validated('date_to') ?? $dateFrom;

        $report = $this->salesReportService->ItemsSold(
            Carbon::parse($dateFrom)->startOfDay(),
            Carbon::parse($dateTo)->endOfDay(),
        );

        return Inertia::render('SalesReportPage', [
            ...$report,
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'today' => $today],
            // Only meaningful while the period reaches today: orders taken but not yet paid.
            'openTickets' => $dateFrom <= $today && $today <= $dateTo ? $this->salesReportService->OpenTickets() : null,
        ]);
    }
}
