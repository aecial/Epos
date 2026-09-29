<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketItemQuantityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // No passcode: lowering (or raising) a line's quantity is not a removal. Dropping to
        // zero is a void, which stays passcode-gated on DELETE /tickets/{id}/items/{itemId}.
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
