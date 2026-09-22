<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketItemModifier extends Model
{
    use HasFactory;

    protected $table = 'ticket_item_modifier';

    protected $fillable = [
        'ticket_item_id',
        'modifier_id',
        'name',
        'price',
    ];

    public function ticketItem(): BelongsTo
    {
        return $this->belongsTo(TicketItem::class);
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }
}
