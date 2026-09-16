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
        $this->modifierService->CreateModifier($request->validated());

        return redirect()->route('modifier-management');
    }

    public function updateModifier(Modifier $modifier, UpdateModifierRequest $request)
    {
        $this->modifierService->UpdateModifier($request->validated(), $modifier);

        return redirect()->route('modifier-management');
    }

    public function deleteModifier(Modifier $modifier)
    {
        $this->modifierService->DeleteModifier($modifier);

        return redirect()->route('modifier-management');
    }
}
