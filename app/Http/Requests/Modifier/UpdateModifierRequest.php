<?php

namespace App\Http\Requests\Modifier;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateModifierRequest extends FormRequest
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
            'modifier_group_id' => ['sometimes', 'nullable', 'exists:modifier_groups,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
