<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ingredient extends Model
{
    use HasFactory;

    protected $fillable = [
        'ingredient_group_id',
        'name',
        'unit',
        'quantity',
        'reserved_quantity',
        'reorder_level',
        'cost_per_unit',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reserved_quantity' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'cost_per_unit' => 'decimal:2',
        ];
    }

    /**
     * At or below its reorder level, counting reserved stock as already gone. No level, no warning.
     */
    public function isRunningLow(): bool
    {
        return $this->reorder_level !== null
            && (float) $this->quantity - (float) $this->reserved_quantity <= (float) $this->reorder_level + 0.000001;
    }

    public function ingredientGroup(): BelongsTo
    {
        return $this->belongsTo(IngredientGroup::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_ingredient')
            ->using(ItemIngredient::class)
            ->withPivot(['quantity_required', 'unit'])
            ->withTimestamps();
    }
}
