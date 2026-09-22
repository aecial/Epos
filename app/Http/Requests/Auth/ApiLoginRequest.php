<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ApiLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            // Free-text label for the issued token, e.g. "POS-01" or a device id -
            // lets a manager tell tokens apart later without guessing which tablet is which.
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
