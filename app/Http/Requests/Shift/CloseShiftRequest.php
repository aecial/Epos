<?php

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;

class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Unlike opening a shift, closing is open to any authenticated staff member
        // (cashiers included) - a deliberate business decision, not an oversight.
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // Cash counted by whoever is closing the drawer; compared against the
            // system-computed expected_cash to surface a shortage/overage.
            'closing_cash' => ['required', 'numeric', 'min:0'],
        ];
    }
}
