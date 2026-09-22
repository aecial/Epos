<?php

namespace App\Http\Requests\Refund;

use Illuminate\Foundation\Http\FormRequest;

class CreateRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        // All roles may request a refund; approval is the passcode-gated step.
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'ticket_id' => ['required', 'integer', 'exists:tickets,id'],
            // A refund always targets one charge, so cash-vs-gcash is known for
            // shift cash reconciliation.
            'charge_id' => ['required', 'integer', 'exists:charges,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ticket_item_id' => ['required', 'integer', 'distinct', 'exists:ticket_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
