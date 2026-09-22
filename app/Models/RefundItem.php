<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'refund_id',
        'ticket_item_id',
        'quantity',
        'amount',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function ticketItem(): BelongsTo
    {
        return $this->belongsTo(TicketItem::class);
    }
}
