<?php

namespace App\Http\Controllers;

use App\Http\Requests\Item\CreateItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Models\Item;
use App\Services\ItemService;

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
    public function updateItem(UpdateItemRequest $request, Item $item) {
        $this->itemService->UpdateItem($request->validated(), $item);

        return redirect()->route('item-management');
    }
    public function deleteItem(Item $item) {
        $this->itemService->DeleteItem($item);

        return redirect()->route('item-management');
    }

}
