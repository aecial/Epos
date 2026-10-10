<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One action a phone sent through POST /api/v1/sync, recorded once: a resent action id returns
 * the stored result instead of applying it again.
 */
class SyncAction extends Model
{
    protected $fillable = [
        'uuid',
        'pos_device_id',
        'user_id',
        'type',
        'offline',
        'happened_at',
        'status',
        'result',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'offline' => 'boolean',
            'happened_at' => 'datetime',
            'result' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
