<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'merged_from_ticket_id',
        'item_id',
        'item_name',
        'item_cost_price',
        'line_type',
        'is_stockless',
        'quantity',
        'unit_price',
        'notes',
        'line_total',
        'voided_at',
        'voided_by',
        'voided_requested_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'voided_at' => 'datetime',
            // Picked with a stockless variant (e.g. "Lagi"): no reserve, release, deduct or restock.
            'is_stockless' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function mergedFromTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'merged_from_ticket_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function voidedRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_requested_by');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(TicketItemModifier::class);
    }

    /**
     * What this line took from ingredient stock when it was paid (recipe items only).
     */
    public function ingredientUsage(): HasMany
    {
        return $this->hasMany(TicketItemIngredient::class);
    }

    /**
     * The stockless variants (e.g. "Lagi") picked on this line - kitchen-only, never on receipts.
     */
    public function stocklessVariants(): HasMany
    {
        return $this->modifiers()->where('is_stockless_variant', true);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
