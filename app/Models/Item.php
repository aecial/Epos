<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'base_price',
        'cost_price',
        'quantity',
        'reserved_quantity',
        'image_url',
        'status',
    ];

    
    public function category(): BelongsTo {
        return $this->belongsTo(Category::class);
    }   


}
