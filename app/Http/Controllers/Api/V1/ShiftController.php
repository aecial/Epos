<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shift\CloseShiftRequest;
use App\Http\Requests\Shift\OpenShiftRequest;
use App\Models\Shift;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;

class ShiftController extends Controller
{
    use ApiResponses;

    public function __construct(private ShiftService $shiftService) {}

    public function openShift(OpenShiftRequest $request): JsonResponse
    {
        $shift = $this->shiftService->OpenShift($request->user(), (float) $request->validated('starting_cash'));

        return $this->success($shift, 201);
    }

    public function getActiveShift(): JsonResponse
    {
        $shift = $this->shiftService->ActiveShift();

        if ($shift === null) {
            return $this->error('No active shift is open.', status: 404);
        }

        return $this->success($this->withLiveTotals($shift));
    }

    public function getShift(Shift $shift): JsonResponse
    {
        return $this->success($shift->isOpen() ? $this->withLiveTotals($shift) : $shift);
    }

    public function closeShift(CloseShiftRequest $request, Shift $shift): JsonResponse
    {
        $closed = $this->shiftService->CloseShift($shift, $request->user(), (float) $request->validated('closing_cash'));

        return $this->success($closed);
    }

    /**
     * Merges the shift model with its live-computed totals for display while open.
     */
    private function withLiveTotals(Shift $shift): array
    {
        return array_merge($shift->toArray(), $this->shiftService->ComputeTotals($shift));
    }
}
