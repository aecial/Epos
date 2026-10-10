<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Item;
use App\Models\Modifier;
use App\Services\InventoryService;
use InvalidArgumentException;

/**
 * The menu item a POS terminal reads, shared by GET /items and the offline snapshot so a phone
 * caches exactly what it would otherwise fetch.
 */
trait PresentsMenuItems
{
    /**
     * What a POS terminal needs to draw and sell an item. Deliberately leaves out
     * cost_price (margin data) and the raw quantity/reserved_quantity columns;
     * available_stock is the number that matters at the till.
     *
     * @return array<string, mixed>
     */
    protected function presentItem(Item $item): array
    {
        return [
            'id' => $item->id,
            'category_id' => $item->category_id,
            'category' => ['id' => $item->category->id, 'name' => $item->category->name, 'type' => $item->category->type],
            'name' => $item->name,
            'base_price' => $item->base_price,
            // available = orderable, unavailable = shown greyed out (hidden never reaches here).
            'status' => $item->status,
            'inventory_type' => $item->inventory_type,
            // fixed = add as-is; price = cashier types the amount (Fee item, base_price is the
            // suggested default); name_price = cashier types name and amount (Custom item).
            'entry_mode' => $item->entry_mode,
            'image_url' => $item->image_url,
            'available_stock' => $this->availableStock($item),
            'modifiers' => $item->sellableModifiers->map(fn (Modifier $modifier): array => [
                'id' => $modifier->id,
                'name' => $modifier->name,
                // A stockless variant ("Lagi") is always ₱0, takes no stock and stays off the
                // receipt; the POS may sell the dish with it even when the dish shows 0 available.
                'price_modifier' => $modifier->is_stockless_variant ? '0.00' : $modifier->pivot->price_modifier,
                'is_stockless_variant' => $modifier->is_stockless_variant,
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
            return app(InventoryService::class)->AvailableFromLoaded($item);
        } catch (InvalidArgumentException) {
            // A recipe item with no ingredients configured yet.
            return null;
        }
    }
}
