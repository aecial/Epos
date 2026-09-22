<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class CreateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'terminal_id' => ['required', 'string', 'max:50'],
            'customer_name' => ['required', 'string', 'max:255'],
            'order_type' => ['required', 'in:dine_in,takeout'],
        ];
    }
}
