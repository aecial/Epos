<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class VoidTicketItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any logged-in staff can operate the terminal and initiate the void; the
        // approver_id + passcode pair below is the actual admin/manager gate,
        // verified in TicketService::VoidItem.
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'approver_id' => ['required', 'integer', 'exists:users,id'],
            'passcode' => ['required', 'digits:4'],
        ];
    }
}
