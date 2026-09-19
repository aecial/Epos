<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

class GetItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isAdminOrManager();
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
        ];
    }
}