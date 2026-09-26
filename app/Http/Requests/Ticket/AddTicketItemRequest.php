<?php

namespace App\Http\Requests\Ticket;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AddTicketItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'modifier_ids' => ['sometimes', 'array'],
            'modifier_ids.*' => ['integer', 'distinct', 'exists:modifiers,id'],
            // KDS-only notes: visible in kitchen and back office, never printed on receipts.
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Special items only (see after()): the amount and, for Custom items, the name
            // the cashier types. Whether each is required or forbidden depends on the item's entry_mode.
            'unit_price' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'],
            'custom_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:100', 'regex:/^[^\r\n]+$/'],
        ];
    }

    /**
     * The server, not the terminal, decides who may set a price or a name: a normal menu
     * item must never accept a price override, and a Special item must always receive what
     * its entry_mode asks for.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $entryMode = Item::query()->whereKey($this->input('item_id'))->value('entry_mode') ?? 'fixed';
                $needsPrice = in_array($entryMode, ['price', 'name_price'], true);
                $needsName = $entryMode === 'name_price';

                foreach (['unit_price' => $needsPrice, 'custom_name' => $needsName] as $field => $required) {
                    $given = $this->input($field) !== null;

                    if ($required && ! $given) {
                        $validator->errors()->add($field, "The {$field} field is required for this item.");
                    }

                    if (! $required && $given) {
                        $validator->errors()->add($field, "The {$field} field is not allowed for this item.");
                    }
                }
            },
        ];
    }
}
