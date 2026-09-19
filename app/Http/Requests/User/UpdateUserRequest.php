<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->isAdminOrManager() ?? false;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user)],
            'password' => ['nullable', 'string', 'min:8'],
            'passcode' => [Rule::excludeIf(fn (): bool => $this->input('role') !== 'manager'), 'nullable', 'digits:4'],
            'role' => ['sometimes', 'required', 'in:manager,cashier'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
        ];
    }
}
