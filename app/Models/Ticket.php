<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'shift_id',
        'created_by',
        'terminal_id',
        'customer_name',
        'order_number',
        'order_type',
        'status',
        'discount_amount',
        'discount_percent',
        'subtotal',
        'total',
        'notes',
        'merged_into_ticket_id',
        'cancelled_by',
        'cancelled_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'merged_into_ticket_id');
    }

    public function mergedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'merged_into_ticket_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TicketItem::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
