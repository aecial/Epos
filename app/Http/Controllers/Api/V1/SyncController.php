<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Api\Concerns\PresentsMenuItems;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncRequest;
use App\Models\Category;
use App\Models\Item;
use App\Models\PosDevice;
use App\Models\Ticket;
use App\Services\ItemService;
use App\Services\PosDeviceService;
use App\Services\ShiftService;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Offline-first POS. A phone saves every sale in its own notebook (outbox) and sends it here:
 * within a second while online, or when the connection returns after an outage. The snapshot
 * is what it keeps on hand to keep selling with no server.
 */
class SyncController extends Controller
{
    use ApiResponses;
    use PresentsMenuItems;

    public function __construct(
        private SyncService $syncService,
        private PosDeviceService $posDeviceService,
        private ShiftService $shiftService,
        private ItemService $itemService,
    ) {}

    public function sync(SyncRequest $request): JsonResponse
    {
        $device = $this->device($request);

        $results = $this->syncService->Sync(
            $request->user(),
            $device,
            $request->validated('actions'),
            (int) $request->validated('pending', 0),
        );

        return $this->success([
            'device' => $device->only(['code', 'name']),
            'server_time' => now()->toIso8601String(),
            'results' => $results,
        ]);
    }

    /**
     * Everything a phone needs to keep selling offline: the menu (as GET /items), categories,
     * the open shift, the signed-in user's open tickets, its device code and the server time
     * (so it can correct its own clock). `menu_version` changes whenever the menu does, so the
     * phone only re-downloads when it has to.
     */
    public function snapshot(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $user = $request->user();
        $shift = $this->shiftService->ActiveShift();

        $openTickets = $shift === null ? collect() : Ticket::query()
            ->where('shift_id', $shift->id)
            ->where('status', 'open')
            ->unless($user->isAdminOrManager(), fn ($query) => $query->whereBelongsTo($user, 'createdBy'))
            ->with('items.modifiers')
            ->orderBy('created_at')
            ->get();

        return $this->success([
            'device' => $device->only(['code', 'name']),
            'server_time' => now()->toIso8601String(),
            'menu_version' => $this->menuVersion(),
            'shift' => $shift,
            'categories' => Category::query()
                ->where('status', 'active')
                ->where('is_visible_to_pos', true)
                ->orderBy('name')
                ->get(['id', 'name', 'type']),
            'items' => $this->itemService->ReadAllMenuItem()->map(fn (Item $item): array => $this->presentItem($item))->values(),
            'open_tickets' => $openTickets,
        ]);
    }

    /**
     * The device behind this request's token. Only a real POS login has one: a phone must sign
     * in with POST /auth/login before it can sync.
     */
    private function device(Request $request): PosDevice
    {
        $token = $request->user()->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken && $token->exists, 403, 'Sign in on the POS (POST /auth/login) before syncing.');

        $device = $this->posDeviceService->DeviceForToken($request->user(), $token);
        $device->update(['last_seen_at' => now()]);

        return $device;
    }

    /**
     * Changes whenever anything a phone caches about the menu does: an item, category, modifier
     * or modifier group added, edited or removed, or a modifier attached to or detached from an item.
     */
    private function menuVersion(): string
    {
        $parts = collect(['items', 'categories', 'modifiers', 'modifier_groups', 'item_modifier'])
            ->map(fn (string $table): string => implode('/', (array) DB::table($table)->selectRaw('COUNT(*) as total, MAX(updated_at) as latest')->first()));

        return sha1($parts->implode('|'));
    }
}
