<?php

namespace App\Http\Requests\ShiftTransaction;

use Illuminate\Foundation\Http\FormRequest;

class CreateShiftTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isAdminOrManager();
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:expense,addition'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
