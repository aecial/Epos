<?php

namespace App\Http\Requests\Sync;

use App\Models\SyncIssue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the back-office Sync review page (GET /sync-issues). Shows what still needs a look
 * unless asked otherwise.
 */
class GetSyncIssuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->isAdminOrManager();
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:unreviewed,reviewed,all'],
            'type' => ['nullable', 'in:'.implode(',', SyncIssue::TYPES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
