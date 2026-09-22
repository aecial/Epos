<?php

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;

class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Open, like close, is available to any authenticated staff member.
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'starting_cash' => ['required', 'numeric', 'min:0'],
        ];
    }
}
