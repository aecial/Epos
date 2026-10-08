<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a paid recipe line took from one ingredient's stock, snapshotted at payment.
 */
class TicketItemIngredient extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_item_id',
        'ingredient_id',
        'quantity_used',
        'unit',
        'cost_per_unit',
    ];

    protected function casts(): array
    {
        return [
            'quantity_used' => 'decimal:3',
            'cost_per_unit' => 'decimal:2',
        ];
    }

    public function ticketItem(): BelongsTo
    {
        return $this->belongsTo(TicketItem::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
