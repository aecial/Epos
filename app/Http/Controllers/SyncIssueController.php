<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sync\GetSyncIssuesRequest;
use App\Models\SyncIssue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sync review: what a phone sold or changed offline that the server accepted although it
 * disagreed - stock that went below zero, a price that changed during the outage, an item removed
 * without a passcode, and so on. The sale itself is already recorded; a manager reads each note,
 * deals with it (recount stock, talk to the cashier, refund on the POS) and marks it reviewed.
 */
class SyncIssueController extends Controller
{
    public function getIssues(GetSyncIssuesRequest $request): Response
    {
        $status = $request->validated('status') ?? 'unreviewed';
        $type = $request->validated('type');

        $page = SyncIssue::query()
            ->when($status === 'unreviewed', fn ($query) => $query->whereNull('reviewed_at'))
            ->when($status === 'reviewed', fn ($query) => $query->whereNotNull('reviewed_at'))
            ->when($type, fn ($query, string $value) => $query->where('type', $value))
            ->with([
                'device:id,code,name',
                'user:id,name',
                'shift:id,opened_at',
                'ticket:id,order_number,customer_name,offline_label',
                'reviewedBy:id,name',
            ])
            ->latest()
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('SyncIssuesPage', [
            'issues' => $page->getCollection()->map(fn (SyncIssue $issue): array => [
                'id' => $issue->id,
                'type' => $issue->type,
                'message' => $issue->message,
                'details' => $issue->details,
                'created_at' => $issue->created_at,
                'device' => $issue->device?->only(['code', 'name']),
                'user' => $issue->user?->name,
                'shift' => $issue->shift?->only(['id', 'opened_at']),
                'ticket' => $issue->ticket?->only(['id', 'order_number', 'customer_name', 'offline_label']),
                'reviewed_at' => $issue->reviewed_at,
                'reviewed_by' => $issue->reviewedBy?->name,
            ])->values(),
            'counts' => SyncIssue::query()
                ->whereNull('reviewed_at')
                ->selectRaw('type, COUNT(*) as total')
                ->groupBy('type')
                ->pluck('total', 'type'),
            'filters' => ['status' => $status, 'type' => $type],
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function reviewIssue(Request $request, SyncIssue $syncIssue): RedirectResponse
    {
        if ($syncIssue->reviewed_at === null) {
            $syncIssue->update(['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        }

        return back();
    }
}
