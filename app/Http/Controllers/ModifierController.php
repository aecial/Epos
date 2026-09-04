<?php

namespace App\Http\Controllers;

use App\Http\Requests\Modifier\CreateModifierRequest;
use App\Http\Requests\Modifier\UpdateModifierRequest;
use App\Models\Modifier;
use App\Services\ModifierService;

class ModifierController extends Controller
{
    protected ModifierService $modifierService;

    public function __construct(ModifierService $modifierService)
    {
        $this->modifierService = $modifierService;
    }

    public function getModifiers()
    {
        return $this->modifierService->ReadAllModifiers();
    }

    public function getModifier(Modifier $modifier)
    {
        return $this->modifierService->ReadModifier($modifier);
    }

    public function createModifier(CreateModifierRequest $request)
    {
        return $this->modifierService->CreateModifier($request->validated());
    }

    public function updateModifier(Modifier $modifier, UpdateModifierRequest $request)
    {
        return $this->modifierService->UpdateModifier($request->validated(), $modifier);
    }

    public function deleteModifier(Modifier $modifier)
    {
        return $this->modifierService->DeleteModifier($modifier);
    }
}
