<?php

namespace App\Services;

use App\Models\Item;

class ItemService
{
    public function __construct()
    {
        //
    }
    public function CreateItem(array $itemData) {
        return Item::create($itemData);
    }
    public function ReadAllItem(?int $categoryId = null) {
        return Item::query()
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->get();
    }
    public function ReadItem(Item $item) {
        return $item;
    }
    public function UpdateItem(array $itemData, Item $item)  {
        $item->update($itemData);
        return $item;
    }
    public function DeleteItem(Item $item) {
        return $item->delete();
    }
}
