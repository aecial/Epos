<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'exists:categories,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'base_price' => ['sometimes', 'decimal:0,2'],
            'cost_price' => ['sometimes', 'decimal:0,2'],
            'quantity' => ['sometimes', 'number'],
            'reserved_quantity' => ['sometimes', 'number'],
            'image_url' => ['sometimes'],
            'status' => ['sometimes', 'in:available,unavailable,hidden'],
        ];
    }
}
