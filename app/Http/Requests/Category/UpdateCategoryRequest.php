<?php

namespace App\Http\Requests\Category;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->isAdminOrManager() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($this->category)],
            'status' => ['sometimes', 'in:active,inactive'],
            'type' => ['sometimes', 'in:menu,special'],
            'is_visible_to_pos' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A category's type is locked once it has items: otherwise an existing recipe dish
     * could silently turn into a Special item (or the reverse).
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Category $category */
                $category = $this->route('category');
                $type = $this->input('type');

                if ($type !== null && $type !== $category->type && $category->items()->exists()) {
                    $validator->errors()->add('type', 'The type of a category that already has items cannot be changed.');
                }
            },
        ];
    }
}
