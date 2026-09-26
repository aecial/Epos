<?php

namespace App\Services;

use App\Models\Category;
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
        $category = Category::query()->findOrFail($itemData['category_id']);

        return Item::create($this->NormalizeForCategory($itemData, $category));
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
        $category = isset($itemData['category_id'])
            ? Category::query()->findOrFail($itemData['category_id'])
            : $item->category;

        $item->update($this->NormalizeForCategory($itemData, $category));

        if ($category->isSpecial()) {
            // A Special item has no recipe or modifiers, even if it used to be a menu item.
            $item->ingredients()->detach();
            $item->modifiers()->detach();
        }

        return $item;
    }
    public function DeleteItem(Item $item) {
        return $item->delete();
    }

    /**
     * A Special item (any item in a special category) never touches stock: no inventory
     * accounting, no cost, no recipe. inventory_type = none already makes reserve, release,
     * deduct and restore no-ops in InventoryService, so payments and refunds need no changes.
     *
     * @param  array<string, mixed>  $itemData
     * @return array<string, mixed>
     */
    public function NormalizeForCategory(array $itemData, Category $category): array
    {
        if (! $category->isSpecial()) {
            return $itemData;
        }

        return array_merge($itemData, [
            'inventory_type' => 'none',
            'quantity' => 0,
            'reserved_quantity' => 0,
            'cost_price' => 0,
        ]);
    }

    /**
     * Rules that depend on the category an item ends up in. Returns field => message so a
     * FormRequest can attach them to the validator. $current is the item being updated.
     *
     * @param  array<string, mixed>  $input  the request data (may be partial on update)
     * @return array<string, string>
     */
    public function SpecialItemViolations(array $input, ?Item $current = null): array
    {
        $categoryId = $input['category_id'] ?? $current?->category_id;
        $category = $categoryId === null ? null : Category::query()->find($categoryId);

        if ($category === null) {
            return [];
        }

        $entryMode = $input['entry_mode'] ?? $current?->entry_mode ?? 'fixed';
        $errors = [];

        if (! $category->isSpecial()) {
            if ($entryMode !== 'fixed') {
                $errors['entry_mode'] = 'Only items in a special category can have the cashier enter the price or name.';
            }

            return $errors;
        }

        if (isset($input['inventory_type']) && $input['inventory_type'] !== 'none') {
            $errors['inventory_type'] = 'Special items do not track inventory.';
        }

        if (! empty($input['ingredients'])) {
            $errors['ingredients'] = 'Special items cannot have a recipe.';
        }

        if (! empty($input['modifiers'])) {
            $errors['modifiers'] = 'Special items cannot have modifiers.';
        }

        return $errors;
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
                'category:id,name,type',
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
