<?php

namespace App\Http\Controllers;

use App\Http\Requests\ModifierGroup\CreateModifierGroupRequest;
use App\Http\Requests\ModifierGroup\UpdateModifierGroupRequest;
use App\Models\ModifierGroup;
use App\Services\ModifierGroupService;

class ModifierGroupController extends Controller
{
    protected ModifierGroupService $modifierGroupService;

    public function __construct(ModifierGroupService $modifierGroupService)
    {
        $this->modifierGroupService = $modifierGroupService;
    }

    public function getModifierGroups()
    {
        return $this->modifierGroupService->ReadAllModifierGroups();
    }

    public function getModifierGroup(ModifierGroup $modifierGroup)
    {
        return $this->modifierGroupService->ReadModifierGroup($modifierGroup);
    }

    public function createModifierGroup(CreateModifierGroupRequest $request)
    {
        return $this->modifierGroupService->CreateModifierGroup($request->validated());
    }

    public function updateModifierGroup(ModifierGroup $modifierGroup, UpdateModifierGroupRequest $request)
    {
        return $this->modifierGroupService->UpdateModifierGroup($request->validated(), $modifierGroup);
    }

    public function deleteModifierGroup(ModifierGroup $modifierGroup)
    {
        return $this->modifierGroupService->DeleteModifierGroup($modifierGroup);
    }
}
