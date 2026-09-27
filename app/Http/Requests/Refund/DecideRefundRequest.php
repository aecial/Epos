<?php

namespace App\Http\Requests\Refund;

use Illuminate\Foundation\Http\FormRequest;

class DecideRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The passcode identifies the deciding admin/manager; RefundService verifies it.
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'passcode' => ['required', 'digits:4'],
        ];
    }
}
