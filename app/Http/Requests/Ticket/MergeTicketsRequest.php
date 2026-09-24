<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class MergeTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // The tickets to fold INTO the ticket in the URL. Same-shift / all-open /
            // not-itself are business rules, enforced under lock in TicketService::MergeTickets.
            'merge_from_ticket_ids' => ['required', 'array', 'min:1'],
            'merge_from_ticket_ids.*' => ['integer', 'distinct', 'exists:tickets,id'],
        ];
    }
}
