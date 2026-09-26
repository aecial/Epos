<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'type',
        'is_visible_to_pos',
    ];


    /** A special category holds Special items: fees and custom items priced by the cashier. */
    public function isSpecial(): bool
    {
        return $this->type === 'special';
    }

    public function items(): HasMany {
        return $this->hasMany(Item::class);
    }
}
