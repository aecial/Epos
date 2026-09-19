<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->isAdminOrManager() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'],
            'passcode' => [Rule::excludeIf(fn (): bool => $this->input('role') !== 'manager'), 'nullable', 'digits:4'],
            'role' => ['required', 'in:manager,cashier'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
