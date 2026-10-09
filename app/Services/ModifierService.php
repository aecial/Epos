<?php

namespace App\Services;

use App\Models\Modifier;
use App\Services\Concerns\DeletesSafely;

class ModifierService
{
    use DeletesSafely;

    public function __construct()
    {
        //
    }

    public function CreateModifier(array $modifierData)
    {
        return Modifier::create($modifierData);
    }

    public function ReadAllModifiers()
    {
        return Modifier::all();
    }

    public function ReadModifier(Modifier $modifier)
    {
        return $modifier;
    }

    public function UpdateModifier(array $modifierData, Modifier $modifier)
    {
        $modifier->update($modifierData);

        // Turning a modifier into a stockless variant makes it ₱0 on every dish it's attached to,
        // so the item forms show what the sale will actually charge.
        if ($modifier->is_stockless_variant) {
            $modifier->items()->newPivotStatement()->where('modifier_id', $modifier->id)->update(['price_modifier' => 0]);
        }

        return $modifier;
    }

    public function DeleteModifier(Modifier $modifier)
    {
        return $this->deleteOrFail($modifier, 'This modifier cannot be deleted because it is referenced by past orders.');
    }
}
