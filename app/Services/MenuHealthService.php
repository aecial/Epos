<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\ModifierGroup;
use Illuminate\Support\Collection;

/**
 * The back-office hub's "menu & stock health": counts of what's set up, the setup problems that
 * break the POS or the reports, and the full stock list worst first. Every check mirrors what the
 * POS actually does (ItemService::menuQuery visibility, InventoryService availability), so the
 * hub never disagrees with the till.
 */
class MenuHealthService
{
    public function __construct(private InventoryService $inventoryService) {}

    /**
     * @return array<string, mixed>
     */
    public function Build(): array
    {
        $items = Item::query()->with(['category:id,name,type,status,is_visible_to_pos', 'ingredients'])->orderBy('name')->get();

        return [
            'counts' => $this->counts($items),
            'issues' => $this->issues($items),
            'stock' => $this->stock($items),
        ];
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return array<string, int>
     */
    private function counts($items): array
    {
        return [
            'categories' => Category::query()->count(),
            'items' => $items->count(),
            'items_available' => $items->where('status', 'available')->count(),
            'items_unavailable' => $items->where('status', 'unavailable')->count(),
            'items_hidden' => $items->where('status', 'hidden')->count(),
            'modifier_groups' => ModifierGroup::query()->count(),
            'ingredient_groups' => IngredientGroup::query()->count(),
            'ingredients' => Ingredient::query()->count(),
        ];
    }

    /**
     * Each check is a list of offending records with a link to fix them; a check with nothing
     * wrong is left out, so an empty list means everything's set up.
     *
     * @param  Collection<int, Item>  $items
     * @return array<int, array{key: string, title: string, help: string, records: array<int, array{name: string, detail: string|null, url: string}>}>
     */
    private function issues($items): array
    {
        $isSpecial = fn (Item $item): bool => $item->category?->isSpecial() ?? false;
        $recipeWithoutIngredients = fn (Item $item): bool => $item->inventory_type === 'recipe' && $item->ingredients->isEmpty();
        $itemLink = fn (Item $item, ?string $detail = null): array => ['name' => $item->name, 'detail' => $detail, 'url' => route('items.edit', $item)];

        $checks = [
            [
                'key' => 'recipe_without_ingredients',
                'title' => 'Recipe items with no ingredients',
                'help' => "The POS can't sell these - adding one to a ticket fails.",
                'records' => $items->filter($recipeWithoutIngredients)->map(fn (Item $item) => $itemLink($item)),
            ],
            [
                'key' => 'available_but_cannot_be_made',
                'title' => "Marked available but can't be made",
                'help' => 'The POS shows these as orderable, then refuses them for lack of stock.',
                'records' => $items
                    ->filter(fn (Item $item): bool => $item->status === 'available'
                        && in_array($item->inventory_type, ['direct', 'recipe'], true)
                        && ! $recipeWithoutIngredients($item)
                        && $this->inventoryService->AvailableFromLoaded($item) < 1)
                    ->map(fn (Item $item) => $itemLink($item, $item->inventory_type === 'direct' ? 'out of stock' : 'a raw material has run out')),
            ],
            [
                'key' => 'no_cost',
                'title' => 'Items with no cost set',
                'help' => 'Their profit shows as 100% on Items Sold.',
                'records' => $items
                    ->filter(fn (Item $item): bool => ! $isSpecial($item) && ! $recipeWithoutIngredients($item) && $this->unitCost($item) <= 0)
                    ->map(fn (Item $item) => $itemLink($item, $item->inventory_type === 'recipe' ? 'its raw materials have no cost' : null)),
            ],
            [
                'key' => 'required_group_without_modifiers',
                'title' => 'Required modifier groups with no active modifiers',
                'help' => 'The cashier is asked to pick from nothing.',
                'records' => ModifierGroup::query()
                    ->where('is_required', true)
                    ->whereDoesntHave('modifiers', fn ($query) => $query->where('status', 'active'))
                    ->orderBy('name')
                    ->get()
                    ->map(fn (ModifierGroup $group): array => ['name' => $group->name, 'detail' => null, 'url' => route('modifier-groups.edit', $group)]),
            ],
            [
                'key' => 'hidden_category_with_available_items',
                'title' => 'Hidden or inactive categories with available items',
                'help' => "These items won't show on the POS - usually not what's intended.",
                'records' => $items
                    ->filter(fn (Item $item): bool => $item->status === 'available'
                        && $item->category !== null
                        && (! $item->category->is_visible_to_pos || $item->category->status !== 'active'))
                    ->groupBy('category_id')
                    ->map(fn ($categoryItems): array => [
                        'name' => $categoryItems->first()->category->name,
                        'detail' => $categoryItems->count().' available '.($categoryItems->count() === 1 ? 'item' : 'items'),
                        'url' => route('categories.edit', $categoryItems->first()->category),
                    ]),
            ],
            [
                'key' => 'unused_raw_materials',
                'title' => 'Raw materials no recipe uses',
                'help' => 'Usually leftovers - nothing will ever take stock from them.',
                'records' => Ingredient::query()
                    ->whereDoesntHave('items')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Ingredient $ingredient): array => ['name' => $ingredient->name, 'detail' => null, 'url' => route('ingredients.edit', $ingredient)]),
            ],
        ];

        return collect($checks)
            ->map(fn (array $check): array => [...$check, 'records' => collect($check['records'])->values()->all()])
            ->filter(fn (array $check): bool => $check['records'] !== [])
            ->values()
            ->all();
    }

    /**
     * Every raw material and direct-stock item, worst first: out, then low, then OK.
     *
     * @param  Collection<int, Item>  $items
     * @return array<int, array<string, mixed>>
     */
    private function stock($items): array
    {
        $rawMaterials = Ingredient::query()->with('ingredientGroup:id,name')->get()->map(fn (Ingredient $ingredient): array => $this->stockRow(
            'raw material',
            $ingredient->name,
            $ingredient->ingredientGroup?->name,
            $ingredient->unit,
            (float) $ingredient->quantity,
            (float) $ingredient->reserved_quantity,
            $ingredient->reorder_level === null ? null : (float) $ingredient->reorder_level,
            $ingredient->isRunningLow(),
            route('ingredients.edit', $ingredient),
        ));

        $directItems = $items
            ->where('inventory_type', 'direct')
            ->reject(fn (Item $item): bool => $item->category?->isSpecial() ?? false)
            ->map(fn (Item $item): array => $this->stockRow(
                'item',
                $item->name,
                $item->category?->name,
                null,
                (float) $item->quantity,
                (float) $item->reserved_quantity,
                $item->reorder_level === null ? null : (float) $item->reorder_level,
                $item->isRunningLow(),
                route('items.edit', $item),
            ));

        $rank = ['out' => 0, 'low' => 1, 'ok' => 2];

        return $rawMaterials->concat($directItems)
            ->sortBy([fn (array $a, array $b): int => $rank[$a['status']] <=> $rank[$b['status']], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function stockRow(string $kind, string $name, ?string $group, ?string $unit, float $onHand, float $reserved, ?float $reorderLevel, bool $isRunningLow, string $url): array
    {
        $available = round($onHand - $reserved, 3);

        return [
            'kind' => $kind,
            'name' => $name,
            'group' => $group,
            'unit' => $unit,
            'on_hand' => round($onHand, 3),
            'reserved' => round($reserved, 3),
            'available' => $available,
            'reorder_level' => $reorderLevel,
            'status' => $available <= 0 ? 'out' : ($isRunningLow ? 'low' : 'ok'),
            'url' => $url,
        ];
    }

    /**
     * What one serving costs: a recipe item's ingredients, otherwise its cost_price - the same
     * figure TicketService snapshots onto a sale.
     */
    private function unitCost(Item $item): float
    {
        if ($item->inventory_type !== 'recipe') {
            return (float) $item->cost_price;
        }

        return (float) $item->ingredients->sum(fn (Ingredient $ingredient): float => (float) $ingredient->cost_per_unit * (float) $ingredient->pivot->quantity_required);
    }
}
