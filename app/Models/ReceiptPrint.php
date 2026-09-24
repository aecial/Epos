<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptPrint extends Model
{
    use HasFactory;

    protected $fillable = [
        'receipt_id',
        'printed_by',
        'is_reprint',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_reprint' => 'boolean',
            'printed_at' => 'datetime',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
