<?php

namespace App\Services;

use App\Models\Ingredient;
use Illuminate\Support\Facades\DB;

class IngredientService
{
    public function CreateIngredient(array $data): Ingredient
    {
        return Ingredient::create($data);
    }

    public function ReadAllIngredients()
    {
        return Ingredient::with('ingredientGroup')->orderBy('name')->get();
    }

    public function ReadIngredient(Ingredient $ingredient): Ingredient
    {
        return $ingredient->load('ingredientGroup', 'items');
    }

    public function UpdateIngredient(array $data, Ingredient $ingredient): Ingredient
    {
        $ingredient->update($data);

        return $ingredient->refresh();
    }

    public function AdjustIngredientQuantity(Ingredient $ingredient, string|int|float $quantity): Ingredient
    {
        return DB::transaction(function () use ($ingredient, $quantity): Ingredient {
            $lockedIngredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);
            $newQuantity = (float) $quantity;

            if ($newQuantity < (float) $lockedIngredient->reserved_quantity) {
                throw new \InvalidArgumentException('Quantity cannot be lower than reserved quantity.');
            }

            $lockedIngredient->quantity = round($newQuantity, 3);
            $lockedIngredient->save();

            return $lockedIngredient->refresh();
        });
    }

    public function DeleteIngredient(Ingredient $ingredient): bool
    {
        return (bool) $ingredient->delete();
    }
}
