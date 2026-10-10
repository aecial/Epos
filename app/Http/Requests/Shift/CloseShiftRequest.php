<?php

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Unlike opening a shift, closing is open to any authenticated staff member
        // (cashiers included) - a deliberate business decision, not an oversight.
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // Cash counted by whoever is closing the drawer; compared against the
            // system-computed expected_cash to surface a shortage/overage.
            'closing_cash' => ['required', 'numeric', 'min:0'],
            // Close although a device still reports offline actions (e.g. a broken phone).
            // Managers/admins only; whatever that device sends later is flagged for review.
            'force' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($this->boolean('force') && ! $this->user()->isAdminOrManager()) {
                    $validator->errors()->add('force', 'Only a manager or admin can close a shift with unsynced devices.');
                }
            },
        ];
    }
}
