<?php

namespace App\Http\Requests\User;

use App\Models\User;
use App\Services\PasscodeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * The POS identifies the approving manager/admin from the passcode alone, so no two
     * active approvers may share one. Hashes are salted, so this cannot be a unique rule.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $passcode = $this->input('passcode');

                if ($this->input('role') !== 'manager' || blank($passcode) || $validator->errors()->has('passcode')) {
                    return;
                }

                if (app(PasscodeService::class)->IsPasscodeTaken((string) $passcode)) {
                    $validator->errors()->add('passcode', 'This passcode is already in use. Choose a different one.');
                }
            },
        ];
    }
}
