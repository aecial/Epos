<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class GetTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // Defaults to the active shift when omitted - the POS never needs to know
            // a shift id, it just asks for "tickets right now".
            'shift_id' => ['sometimes', 'integer', 'exists:shifts,id'],
            // An optional label filter (e.g. a manager narrowing to one counter). Who may see which
            // ticket is decided by the logged-in account, not by this.
            'terminal_id' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', 'in:open,paid,merged,cancelled'],
        ];
    }
}
