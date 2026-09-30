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
            // 0 is allowed: a ticket discounted to zero (a comp) is still closed through this
            // endpoint, with a single zero-amount charge — PaymentService enforces that "single
            // charge" rule, since the sum-equals-total check alone can't rule out e.g. two
            // zero-amount charges against a zero total.
            'charges.*.amount' => ['required', 'numeric', 'gte:0'],
            'charges.*.tendered_amount' => ['sometimes', 'nullable', 'numeric', 'gte:charges.*.amount'],
            'charges.*.payment_reference' => ['required_if:charges.*.payment_method,gcash', 'nullable', 'string', 'max:255'],
        ];
    }
}
