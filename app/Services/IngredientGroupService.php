<?php

namespace App\Services;

use App\Models\IngredientGroup;

class IngredientGroupService
{
    public function CreateIngredientGroup(array $data): IngredientGroup
    {
        return IngredientGroup::create($data);
    }

    public function ReadAllIngredientGroups()
    {
        return IngredientGroup::withCount('ingredients')->orderBy('name')->get();
    }

    public function UpdateIngredientGroup(array $data, IngredientGroup $group): IngredientGroup
    {
        $group->update($data);

        return $group->refresh();
    }

    public function DeleteIngredientGroup(IngredientGroup $group): bool
    {
        return (bool) $group->delete();
    }
}
