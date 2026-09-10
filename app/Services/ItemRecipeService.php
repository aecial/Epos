<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ItemRecipeService
{
    /**
     * @param  array<int, array{ingredient_id: int, quantity_required: int|float|string, unit: string}>  $ingredients
     */
    public function ReplaceItemRecipe(Item $item, array $ingredients): Item
    {
        if ($item->inventory_type !== 'recipe') {
            throw new InvalidArgumentException('Only recipe-based items can have ingredients.');
        }

        if ($ingredients === []) {
            throw new InvalidArgumentException('A recipe must contain at least one ingredient.');
        }

        return DB::transaction(function () use ($item, $ingredients): Item {
            $lockedItem = Item::query()->lockForUpdate()->findOrFail($item->id);
            $recipe = [];
            $ingredientIds = [];

            foreach ($ingredients as $ingredientData) {
                $ingredientId = (int) ($ingredientData['ingredient_id'] ?? 0);
                $quantityRequired = (float) ($ingredientData['quantity_required'] ?? 0);
                $unit = $ingredientData['unit'] ?? '';

                if ($ingredientId <= 0 || in_array($ingredientId, $ingredientIds, true)) {
                    throw new InvalidArgumentException('Each recipe ingredient must be unique and valid.');
                }

                if ($quantityRequired <= 0) {
                    throw new InvalidArgumentException('Recipe quantities must be greater than zero.');
                }

                $ingredientIds[] = $ingredientId;
                $recipe[$ingredientId] = [
                    'quantity_required' => round($quantityRequired, 3),
                    'unit' => $unit,
                ];
            }

            $ingredientRecords = Ingredient::query()
                ->whereIn('id', $ingredientIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($ingredientRecords->count() !== count($ingredientIds)) {
                throw new InvalidArgumentException('One or more recipe ingredients do not exist.');
            }

            foreach ($recipe as $ingredientId => $pivot) {
                if ($ingredientRecords[$ingredientId]->unit !== $pivot['unit']) {
                    throw new InvalidArgumentException('Recipe units must match ingredient units.');
                }
            }

            $lockedItem->ingredients()->sync($recipe);

            return $lockedItem->load('ingredients');
        });
    }

    public function ReadItemRecipe(Item $item): Item
    {
        return $item->load('ingredients');
    }
}
