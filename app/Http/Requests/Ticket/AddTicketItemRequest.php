<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class AddTicketItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'modifier_ids' => ['sometimes', 'array'],
            'modifier_ids.*' => ['integer', 'distinct', 'exists:modifiers,id'],
            // KDS-only notes: visible in kitchen and back office, never printed on receipts.
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
