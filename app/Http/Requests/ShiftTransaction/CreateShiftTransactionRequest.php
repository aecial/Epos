<?php

namespace App\Http\Requests\ShiftTransaction;

use Illuminate\Foundation\Http\FormRequest;

class CreateShiftTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any staff member at the drawer may record a cash addition or expense; every entry
        // keeps who recorded it and shows on the shift report and drawer count.
        return auth()->check();
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
