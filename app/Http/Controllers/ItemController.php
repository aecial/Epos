<?php

namespace App\Http\Controllers;

use App\Http\Requests\Item\CreateItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Models\Item;
use App\Services\ItemRecipeService;
use App\Services\ItemService;
use Illuminate\Support\Facades\DB;

class ItemController extends Controller
{
    protected ItemService $itemService;

    public function __construct(ItemService $itemService) {
        $this->itemService = $itemService;
    }

    public function getItems() {
        return $this->itemService->ReadAllItem();
    }
    public function getItem(Item $item) {
        return $this->itemService->ReadItem($item);
    }
    public function createItem(CreateItemRequest $request) {
        $this->itemService->CreateItem($request->validated());

        return redirect()->route('item-management');
    }
    public function updateItem(UpdateItemRequest $request, Item $item, ItemRecipeService $itemRecipeService) {
        $data = $request->validated();
        $ingredients = $data['ingredients'] ?? null;
        unset($data['ingredients']);

        DB::transaction(function () use ($data, $ingredients, $item, $itemRecipeService): void {
            $this->itemService->UpdateItem($data, $item);

            if ($item->inventory_type === 'recipe' && $ingredients !== null) {
                $itemRecipeService->ReplaceItemRecipe($item->refresh(), $ingredients);
            }
        });

        return redirect()->route('item-management');
    }
    public function deleteItem(Item $item) {
        $this->itemService->DeleteItem($item);

        return redirect()->route('item-management');
    }

}
