<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Api\Concerns\PresentsMenuItems;
use App\Http\Controllers\Controller;
use App\Http\Requests\Item\GetPosItemsRequest;
use App\Models\Item;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;

class ItemController extends Controller
{
    use ApiResponses;
    use PresentsMenuItems;

    public function __construct(private ItemService $itemService) {}

    public function getItems(GetPosItemsRequest $request): JsonResponse
    {
        $items = $this->itemService->ReadAllMenuItem($request->validated('category_id'));

        return $this->success($items->map(fn (Item $item): array => $this->presentItem($item))->values());
    }

    public function getItem(Item $item): JsonResponse
    {
        return $this->success($this->presentItem($this->itemService->ReadMenuItem($item)));
    }
}
