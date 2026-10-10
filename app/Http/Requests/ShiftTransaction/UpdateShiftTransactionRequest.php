<?php

namespace App\Http\Requests\ShiftTransaction;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShiftTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same as recording one: any staff member; the entry keeps who last changed it.
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
