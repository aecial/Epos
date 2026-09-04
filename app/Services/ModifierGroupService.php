<?php

namespace App\Services;

use App\Models\ModifierGroup;

class ModifierGroupService
{
    public function CreateModifierGroup(array $data)
    {
        return ModifierGroup::create($data);
    }

    public function ReadAllModifierGroups()
    {
        return ModifierGroup::with('modifiers')->get();
    }

    public function ReadModifierGroup(ModifierGroup $modifierGroup)
    {
        return $modifierGroup->load('modifiers');
    }

    public function UpdateModifierGroup(array $data, ModifierGroup $modifierGroup)
    {
        $modifierGroup->update($data);

        return $modifierGroup;
    }

    public function DeleteModifierGroup(ModifierGroup $modifierGroup)
    {
        return $modifierGroup->delete();
    }
}
