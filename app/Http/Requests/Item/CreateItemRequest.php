<?php

namespace App\Http\Requests\Item;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'quantity' => ['sometimes', 'number'],
            'reserved_quantity' => ['sometimes', 'number'],
            'image_url' => ['sometimes'],
            'status' => ['sometimes', 'in:available,unavailable,hidden'],
        ];
    }
}
