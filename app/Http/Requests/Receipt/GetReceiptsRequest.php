<?php

namespace App\Http\Requests\Receipt;

use Illuminate\Foundation\Http\FormRequest;

class GetReceiptsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['sometimes', 'integer', 'exists:shifts,id'],
            'ticket_id' => ['sometimes', 'integer', 'exists:tickets,id'],
            'terminal_id' => ['sometimes', 'string', 'max:50'],
            'payment_method' => ['sometimes', 'in:cash,gcash'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            // Matches order number, customer name, or receipt number.
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
