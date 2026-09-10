<?php

namespace App\Http\Requests\IngredientGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIngredientGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isAdminOrManager();
    }

    public function rules(): array
    {
        $group = $this->route('ingredientGroup');

        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('ingredient_groups', 'name')->ignore($group)],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
