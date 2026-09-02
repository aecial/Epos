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
    public function createItemI(CreateItemRequest $request) {
        return $this->itemService->CreateItem($request->validated());
    }
    public function updateItem(UpdateItemRequest $request, Item $item) {
        return $this->itemService->UpdateItem($request->validated(), $item);
    }
    public function deleteItem(Item $item) {
        return $this->itemService->DeleteItem($item);
    }

}
