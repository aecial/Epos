<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Item\GetPosItemsRequest;
use App\Models\Item;
use App\Models\Modifier;
use App\Services\InventoryService;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ItemController extends Controller
{
    use ApiResponses;

    public function __construct(
        private ItemService $itemService,
        private InventoryService $inventoryService,
    ) {}

    public function getItems(GetPosItemsRequest $request): JsonResponse
    {
        $items = $this->itemService->ReadAllMenuItem($request->validated('category_id'));

        return $this->success($items->map(fn (Item $item): array => $this->presentItem($item))->values());
    }

    public function getItem(Item $item): JsonResponse
    {
        return $this->success($this->presentItem($this->itemService->ReadMenuItem($item)));
    }

    /**
     * What a POS terminal needs to draw and sell an item. Deliberately leaves out
     * cost_price (margin data) and the raw quantity/reserved_quantity columns;
     * available_stock is the number that matters at the till.
     */
    private function presentItem(Item $item): array
    {
        return [
            'id' => $item->id,
            'category_id' => $item->category_id,
            'category' => ['id' => $item->category->id, 'name' => $item->category->name],
            'name' => $item->name,
            'base_price' => $item->base_price,
            // available = orderable, unavailable = shown greyed out (hidden never reaches here).
            'status' => $item->status,
            'inventory_type' => $item->inventory_type,
            'image_url' => $item->image_url,
            'available_stock' => $this->availableStock($item),
            'modifiers' => $item->modifiers->map(fn (Modifier $modifier): array => [
                'id' => $modifier->id,
                'name' => $modifier->name,
                'price_modifier' => $modifier->pivot->price_modifier,
                'group' => $modifier->group === null ? null : [
                    'id' => $modifier->group->id,
                    'name' => $modifier->group->name,
                    'is_required' => $modifier->group->is_required,
                ],
            ])->values()->all(),
        ];
    }

    /**
     * Sellable units right now (on hand minus reserved; for recipes, complete servings the
     * ingredients allow). null means "not tracked": inventory_type 'none' is unlimited, and
     * INF can't be encoded as JSON anyway.
     */
    private function availableStock(Item $item): ?float
    {
        if ($item->inventory_type === 'none') {
            return null;
        }

        try {
            return $this->inventoryService->AvailableForItem($item);
        } catch (InvalidArgumentException) {
            // A recipe item with no ingredients configured yet.
            return null;
        }
    }
}
