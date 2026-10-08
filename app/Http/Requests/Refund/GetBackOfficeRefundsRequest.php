<?php

namespace App\Http\Requests\Refund;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the back-office refund history (GET /refunds). Every filter is optional; the
 * dates are the day a refund was requested (app timezone).
 */
class GetBackOfficeRefundsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isAdminOrManager();
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'payment_method' => ['nullable', 'in:cash,gcash'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            // Matches order number, customer name or receipt number.
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
