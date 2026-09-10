<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ingredient\CreateIngredientRequest;
use App\Http\Requests\Ingredient\UpdateIngredientRequest;
use App\Models\Ingredient;
use App\Services\IngredientService;

class IngredientController extends Controller
{
    protected IngredientService $ingredientService;

    public function __construct(IngredientService $ingredientService)
    {
        $this->ingredientService = $ingredientService;
    }

    public function getIngredients()
    {
        return $this->ingredientService->ReadAllIngredients();
    }

    public function getIngredient(Ingredient $ingredient)
    {
        return $this->ingredientService->ReadIngredient($ingredient);
    }

    public function createIngredient(CreateIngredientRequest $request)
    {
        return $this->ingredientService->CreateIngredient($request->validated());
    }

    public function updateIngredient(UpdateIngredientRequest $request, Ingredient $ingredient)
    {
        return $this->ingredientService->UpdateIngredient($request->validated(), $ingredient);
    }

    public function deleteIngredient(Ingredient $ingredient)
    {
        return $this->ingredientService->DeleteIngredient($ingredient);
    }
}
