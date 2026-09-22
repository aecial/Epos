<?php

namespace App\Http\Requests\Refund;

use Illuminate\Foundation\Http\FormRequest;

class DecideRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
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
