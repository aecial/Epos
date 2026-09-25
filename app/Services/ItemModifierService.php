<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Modifier;
use InvalidArgumentException;

class ItemModifierService
{
    /**
     * @param  array<int, array{modifier_id: int, price_modifier?: int|float|string|null, display_order?: int|null}>  $modifiers
     */
    public function ReplaceItemModifiers(Item $item, array $modifiers): Item
    {
        $pivot = [];
        $modifierIds = [];

        foreach ($modifiers as $index => $modifierData) {
            $modifierId = (int) ($modifierData['modifier_id'] ?? 0);

            if ($modifierId <= 0 || in_array($modifierId, $modifierIds, true)) {
                throw new InvalidArgumentException('Each item modifier must be unique and valid.');
            }

            $modifierIds[] = $modifierId;
            $pivot[$modifierId] = [
                'price_modifier' => round((float) ($modifierData['price_modifier'] ?? 0), 2),
                'display_order' => (int) ($modifierData['display_order'] ?? $index),
            ];
        }

        if ($modifierIds !== [] && Modifier::query()->whereIn('id', $modifierIds)->count() !== count($modifierIds)) {
            throw new InvalidArgumentException('One or more modifiers do not exist.');
        }

        $item->modifiers()->sync($pivot);

        return $item->load('modifiers.group');
    }
}
