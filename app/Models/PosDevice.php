<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A POS login (one Sanctum token). Its short `code` prefixes the receipt and order numbers the
 * phone prints while offline, so two phones can never print the same number.
 */
class PosDevice extends Model
{
    protected $fillable = [
        'user_id',
        'personal_access_token_id',
        'name',
        'code',
        'pending_actions',
        'last_seen_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'pending_actions' => 'integer',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function syncActions(): HasMany
    {
        return $this->hasMany(SyncAction::class);
    }
}
