<?php

namespace App\Http\Controllers;

use App\Http\Requests\Item\CreateItemRequest;
use App\Http\Requests\Item\GetItemsRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Models\Item;
use App\Services\ItemModifierService;
use App\Services\ItemRecipeService;
use App\Services\ItemService;
use Illuminate\Support\Facades\DB;

class ItemController extends Controller
{
    protected ItemService $itemService;

    public function __construct(ItemService $itemService) {
        $this->itemService = $itemService;
    }

    public function getItems(GetItemsRequest $request) {
        return $this->itemService->ReadAllItem($request->validated('category_id'));
    }
    public function getItem(Item $item) {
        return $this->itemService->ReadItem($item);
    }
    public function createItem(CreateItemRequest $request, ItemRecipeService $itemRecipeService, ItemModifierService $itemModifierService) {
        $data = $request->validated();
        $ingredients = $data['ingredients'] ?? null;
        $modifiers = $data['modifiers'] ?? null;
        unset($data['ingredients'], $data['modifiers']);

        DB::transaction(function () use ($data, $ingredients, $modifiers, $itemRecipeService, $itemModifierService): void {
            $item = $this->itemService->CreateItem($data);

            if ($item->inventory_type === 'recipe' && $ingredients !== null) {
                $itemRecipeService->ReplaceItemRecipe($item, $ingredients);
            }

            if ($modifiers !== null) {
                $itemModifierService->ReplaceItemModifiers($item, $modifiers);
            }
        });

        return redirect()->route('item-management');
    }
    public function updateItem(UpdateItemRequest $request, Item $item, ItemRecipeService $itemRecipeService, ItemModifierService $itemModifierService) {
        $data = $request->validated();
        $ingredients = $data['ingredients'] ?? null;
        $modifiers = $data['modifiers'] ?? null;
        unset($data['ingredients'], $data['modifiers']);

        DB::transaction(function () use ($data, $ingredients, $modifiers, $item, $itemRecipeService, $itemModifierService): void {
            $this->itemService->UpdateItem($data, $item);

            if ($item->inventory_type === 'recipe' && $ingredients !== null) {
                $itemRecipeService->ReplaceItemRecipe($item->refresh(), $ingredients);
            }

            if ($modifiers !== null) {
                $itemModifierService->ReplaceItemModifiers($item, $modifiers);
            }
        });

        return redirect()->route('item-management');
    }
    public function deleteItem(Item $item) {
        $this->itemService->DeleteItem($item);

        return redirect()->route('item-management');
    }

}
