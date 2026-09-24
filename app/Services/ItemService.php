<?php

namespace App\Services;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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

    /**
     * The POS-facing menu: what a terminal is allowed to show. Excludes `hidden` items and
     * items whose category is not visible to the POS; `unavailable` items ARE included
     * (shown greyed out). ReadAllItem stays the unfiltered back-office read.
     *
     * @return Collection<int, Item>
     */
    public function ReadAllMenuItem(?int $categoryId = null): Collection
    {
        return $this->menuQuery()
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->orderBy('name')
            ->get();
    }

    /**
     * One menu item, with the same visibility rules. Throws ModelNotFoundException (404)
     * if the item is hidden or its category is not visible to the POS.
     */
    public function ReadMenuItem(Item $item): Item
    {
        return $this->menuQuery()->findOrFail($item->id);
    }

    private function menuQuery(): Builder
    {
        return Item::query()
            ->where('status', '!=', 'hidden')
            ->whereHas('category', fn ($query) => $query->where('is_visible_to_pos', true))
            ->with([
                'category:id,name',
                // Only modifiers that are active both globally and for this item, in the
                // order the back office arranged them.
                'modifiers' => fn ($query) => $query
                    ->where('modifiers.status', 'active')
                    ->wherePivot('status', 'active')
                    ->orderBy('item_modifier.display_order'),
                'modifiers.group:id,name,is_required',
            ]);
    }
}
