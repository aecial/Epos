<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Item extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'base_price',
        'cost_price',
        'quantity',
        'reserved_quantity',
        'reorder_level',
        'inventory_type',
        'entry_mode',
        'image_url',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'reorder_level' => 'integer',
        ];
    }

    /**
     * A direct-stock item at or below its reorder level, counting reserved stock as already
     * gone. Recipe items run low through their ingredients instead.
     */
    public function isRunningLow(): bool
    {
        return $this->inventory_type === 'direct'
            && $this->reorder_level !== null
            && (int) $this->quantity - (int) $this->reserved_quantity <= (int) $this->reorder_level;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function modifiers(): BelongsToMany
    {
        return $this->belongsToMany(Modifier::class)
            ->using(ItemModifier::class)
            ->withPivot(['price_modifier', 'status', 'display_order'])
            ->withTimestamps();
    }

    /**
     * Modifiers the POS may sell on this item: active globally and active for this item.
     */
    public function sellableModifiers(): BelongsToMany
    {
        return $this->modifiers()
            ->where('modifiers.status', 'active')
            ->wherePivot('status', 'active');
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'item_ingredient')
            ->using(ItemIngredient::class)
            ->withPivot(['quantity_required', 'unit'])
            ->withTimestamps();
    }
}
