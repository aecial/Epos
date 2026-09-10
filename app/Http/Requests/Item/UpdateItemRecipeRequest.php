<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isAdminOrManager();
    }

    public function rules(): array
    {
        return [
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,id'],
            'ingredients.*.quantity_required' => ['required', 'numeric', 'gt:0'],
            'ingredients.*.unit' => ['required', 'in:piece,kg,gram,liter,ml'],
        ];
    }
}
