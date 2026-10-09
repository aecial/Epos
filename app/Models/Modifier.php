<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Modifier extends Model
{
    use HasFactory;

    protected $fillable = [
        'modifier_group_id',
        'name',
        'status',
        'is_stockless_variant',
    ];

    protected $attributes = [
        'status' => 'active',
        'is_stockless_variant' => false,
    ];

    protected function casts(): array
    {
        return [
            // A variant like "Lagi": the line it's picked on takes no stock, costs ₱0, and the
            // modifier stays off the customer receipt (the kitchen still sees it). Always ₱0.
            'is_stockless_variant' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class)
            ->using(ItemModifier::class)
            ->withPivot(['price_modifier', 'status', 'display_order'])
            ->withTimestamps();
    }
}
