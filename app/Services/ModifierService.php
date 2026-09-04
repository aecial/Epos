<?php

namespace App\Services;

use App\Models\Modifier;

class ModifierService
{
    public function __construct()
    {
        //
    }
    public function CreateModifier(array $modifierData) {
        return Modifier::create($modifierData);
    }
    public function ReadAllModifiers() {
        return Modifier::all();
    }
    public function ReadModifier(Modifier $modifier) {
        return $modifier;
    }
    public function UpdateModifier(array $modifierData, Modifier $modifier) {
        $modifier->update($modifierData);
        return $modifier;
    }
    public function DeleteModifier(Modifier $modifier) {
        return $modifier->delete();
    }
}
