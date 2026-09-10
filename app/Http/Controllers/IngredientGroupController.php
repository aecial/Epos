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
        return $this->ingredientGroupService->CreateIngredientGroup($request->validated());
    }

    public function updateIngredientGroup(UpdateIngredientGroupRequest $request, IngredientGroup $ingredientGroup)
    {
        return $this->ingredientGroupService->UpdateIngredientGroup($request->validated(), $ingredientGroup);
    }

    public function deleteIngredientGroup(IngredientGroup $ingredientGroup)
    {
        return $this->ingredientGroupService->DeleteIngredientGroup($ingredientGroup);
    }
}
