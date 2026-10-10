<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shift a phone opened offline that was joined into the real one on sync; its client uuid
 * resolves to that shift.
 */
class ShiftAlias extends Model
{
    protected $fillable = [
        'client_uuid',
        'shift_id',
        'pos_device_id',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
