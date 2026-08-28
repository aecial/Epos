<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModifierGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'is_required',
    ];

    protected $attributes = [
        'is_required' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class);
    }
}
