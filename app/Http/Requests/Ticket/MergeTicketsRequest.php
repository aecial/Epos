<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergeTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        // A cashier may only merge tickets they opened. Restricting the lookup here keeps another
        // cashier's ticket ids from being confirmed as existing; a manager/admin may merge any.
        $exists = Rule::exists('tickets', 'id');

        if (! $this->user()->isAdminOrManager()) {
            $exists->where('created_by', $this->user()->id);
        }

        return [
            // The tickets to fold INTO the ticket in the URL. Same-shift / all-open /
            // not-itself / ownership are business rules, enforced under lock in
            // TicketService::MergeTickets.
            'merge_from_ticket_ids' => ['required', 'array', 'min:1'],
            'merge_from_ticket_ids.*' => ['integer', 'distinct', $exists],
        ];
    }
}
