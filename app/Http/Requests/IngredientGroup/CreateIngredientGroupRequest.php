<?php

namespace App\Http\Requests\IngredientGroup;

use Illuminate\Foundation\Http\FormRequest;

class CreateIngredientGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isAdminOrManager();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:ingredient_groups,name'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
