<?php

namespace App\Http\Controllers;

use App\Http\Requests\IngredientGroup\CreateIngredientGroupRequest;
use App\Http\Requests\IngredientGroup\UpdateIngredientGroupRequest;
use App\Models\IngredientGroup;
use App\Services\IngredientGroupService;

class IngredientGroupController extends Controller
{
    protected IngredientGroupService $ingredientGroupService;

    public function __construct(IngredientGroupService $ingredientGroupService)
    {
        $this->ingredientGroupService = $ingredientGroupService;
    }

    public function getIngredientGroups()
    {
        return $this->ingredientGroupService->ReadAllIngredientGroups();
    }

    public function createIngredientGroup(CreateIngredientGroupRequest $request)
    {
        $this->ingredientGroupService->CreateIngredientGroup($request->validated());
        return redirect()->route('ingredient-management');
    }

    public function updateIngredientGroup(UpdateIngredientGroupRequest $request, IngredientGroup $ingredientGroup)
    {
        $this->ingredientGroupService->UpdateIngredientGroup($request->validated(), $ingredientGroup);

        return redirect()->route('ingredient-management');
    }

    public function deleteIngredientGroup(IngredientGroup $ingredientGroup)
    {
        return $this->ingredientGroupService->DeleteIngredientGroup($ingredientGroup);
    }
}
