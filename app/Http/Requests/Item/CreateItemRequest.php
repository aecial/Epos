<?php

namespace App\Http\Requests\Item;

use App\Services\ItemService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isAdminOrManager();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'base_price' => ['required', 'decimal:0,2'],
            'cost_price' => ['sometimes', 'decimal:0,2'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'reserved_quantity' => ['sometimes', 'integer', 'min:0'],
            'inventory_type' => ['sometimes', 'in:direct,recipe,none'],
            // fixed = normal item; price = Fee item; name_price = Custom item (special categories only).
            'entry_mode' => ['sometimes', 'in:fixed,price,name_price'],
            'image_url' => ['sometimes'],
            'status' => ['sometimes', 'in:available,unavailable,hidden'],
            'ingredients' => ['required_if:inventory_type,recipe', 'array', 'min:1'],
            'ingredients.*.ingredient_id' => ['required_if:inventory_type,recipe', 'integer', 'distinct', 'exists:ingredients,id'],
            'ingredients.*.quantity_required' => ['required_if:inventory_type,recipe', 'numeric', 'gt:0'],
            'ingredients.*.unit' => ['required_if:inventory_type,recipe', 'in:piece,kg,gram,liter,ml'],
            'modifiers' => ['sometimes', 'array'],
            'modifiers.*.modifier_id' => ['required', 'integer', 'distinct', 'exists:modifiers,id'],
            'modifiers.*.price_modifier' => ['sometimes', 'nullable', 'decimal:0,2'],
            'modifiers.*.display_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Category-dependent rules for Special items (see ItemService::SpecialItemViolations).
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (app(ItemService::class)->SpecialItemViolations($this->all(), null) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }
}
