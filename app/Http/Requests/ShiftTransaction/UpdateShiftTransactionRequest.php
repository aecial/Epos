<?php

namespace App\Http\Requests\ShiftTransaction;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShiftTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isAdminOrManager();
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
