<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class SetTicketDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'discount_amount' => ['sometimes', 'numeric', 'min:0'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
