<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class VoidTicketItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any logged-in staff can operate the terminal and initiate the void; the passcode
        // below is the actual admin/manager gate. TicketService::VoidItem resolves which
        // active admin/manager it belongs to and records them as the approver.
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'passcode' => ['required', 'digits:4'],
        ];
    }
}
