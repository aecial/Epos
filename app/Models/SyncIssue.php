<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a sync accepted although the server disagreed (stock went below zero, a price
 * changed during the outage, an item removed without a passcode, ...), waiting for a manager.
 */
class SyncIssue extends Model
{
    public const TYPES = [
        'stock_short',
        'price_changed',
        'charge_mismatch',
        'possible_double_payment',
        'offline_void',
        'starting_cash_conflict',
        'old_shift_joined',
        'clock_wrong',
        'synced_after_close',
        'receipt_number_taken',
        'rejected_action',
    ];

    protected $fillable = [
        'sync_action_id',
        'pos_device_id',
        'user_id',
        'type',
        'shift_id',
        'ticket_id',
        'message',
        'details',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<SyncIssue>  $query
     */
    public function scopeUnreviewed(Builder $query): void
    {
        $query->whereNull('reviewed_at');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
