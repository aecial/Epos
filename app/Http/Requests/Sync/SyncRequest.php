<?php

namespace App\Http\Requests\Sync;

use App\Services\SyncService;
use Illuminate\Foundation\Http\FormRequest;

class SyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Only the envelope is checked here; each action's own data is validated when it's applied,
     * so one bad action is rejected on its own instead of failing the whole batch.
     */
    public function rules(): array
    {
        return [
            // In the order they happened on the phone. Sent again after a lost reply, the same
            // ids replay the stored results.
            'actions' => ['present', 'array', 'max:200'],
            'actions.*.id' => ['required', 'uuid', 'distinct'],
            'actions.*.type' => ['required', 'string', 'in:'.implode(',', SyncService::ACTION_TYPES)],
            // true when the phone had no server at the time: the action already happened.
            'actions.*.offline' => ['required', 'boolean'],
            'actions.*.happened_at' => ['required_if:actions.*.offline,true', 'nullable', 'date'],
            'actions.*.data' => ['present', 'array'],
            // How many actions the phone still holds after this batch; the shift can't close
            // while any device reports some.
            'pending' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
