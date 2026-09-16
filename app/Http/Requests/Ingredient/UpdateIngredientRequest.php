<?php

namespace App\Http\Requests\Ingredient;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isAdminOrManager();
    }

    public function rules(): array
    {
        return [
            'ingredient_group_id' => ['sometimes', 'exists:ingredient_groups,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'unit' => ['sometimes', 'in:piece,kg,gram,liter,ml'],
            'quantity' => ['sometimes', 'numeric', 'min:0'],
            'reserved_quantity' => ['sometimes', 'numeric', 'min:0'],
            'cost_per_unit' => ['sometimes', 'decimal:0,2', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
