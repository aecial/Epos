<?php

namespace App\Services;

use App\Models\ModifierGroup;
use App\Services\Concerns\DeletesSafely;

class ModifierGroupService
{
    use DeletesSafely;

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
        return $this->deleteOrFail($modifierGroup, 'This modifier group cannot be deleted because it is still referenced elsewhere.');
    }
}
