<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class ChargeTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // Amounts-only split: each charge covers a portion of the ticket total.
            // No item assignment - every receipt printed from a charge lists all
            // ticket items with a prorated discount.
            'charges' => ['required', 'array', 'min:1'],
            'charges.*.payment_method' => ['required', 'in:cash,gcash'],
            'charges.*.amount' => ['required', 'numeric', 'gt:0'],
            'charges.*.tendered_amount' => ['sometimes', 'nullable', 'numeric', 'gte:charges.*.amount'],
            'charges.*.payment_reference' => ['required_if:charges.*.payment_method,gcash', 'nullable', 'string', 'max:255'],
        ];
    }
}
