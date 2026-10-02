<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ItemModifier extends Pivot
{
    protected $table = 'item_modifier';

    protected function casts(): array
    {
        return [
            'price_modifier' => 'decimal:2',
            'display_order' => 'integer',
        ];
    }
}
