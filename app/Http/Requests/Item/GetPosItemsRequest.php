<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

class GetPosItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // No role restriction: the POS menu is for every staff member, cashiers included.
        // Whether a token is required is decided by the route's auth:sanctum middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
        ];
    }
}
