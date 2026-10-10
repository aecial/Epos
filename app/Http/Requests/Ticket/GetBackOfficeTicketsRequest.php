<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the back-office ticket history (GET /tickets). Every filter is optional; the page
 * only sends the ones that are set.
 */
class GetBackOfficeTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isAdminOrManager();
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:open,paid,cancelled,merged'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'payment_method' => ['nullable', 'in:cash,gcash'],
            // Only tickets taken on a phone while the server was down.
            'offline' => ['nullable', 'in:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            // Matches order number, customer name or receipt number.
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
