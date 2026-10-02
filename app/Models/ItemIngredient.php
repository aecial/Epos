<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ItemIngredient extends Pivot
{
    protected $table = 'item_ingredient';

    protected function casts(): array
    {
        return [
            'quantity_required' => 'decimal:3',
        ];
    }
}
