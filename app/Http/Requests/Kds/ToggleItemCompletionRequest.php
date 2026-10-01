<?php

namespace App\Http\Requests\Kds;

use Illuminate\Foundation\Http\FormRequest;

class ToggleItemCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'completed' => ['required', 'boolean'],
        ];
    }
}
