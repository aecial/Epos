<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    use ApiResponses;

    public function getCategories(): JsonResponse
    {
        $categories = $this->posQuery()->orderBy('name')->get();

        return $this->success($categories->map(fn (Category $category): array => $this->presentCategory($category))->values());
    }

    public function getCategory(Category $category): JsonResponse
    {
        $category = $this->posQuery()->findOrFail($category->id);

        return $this->success($this->presentCategory($category));
    }

    /**
     * Same visibility rule the POS menu uses (ItemService::menuQuery): a category the
     * POS may never see (inactive, or is_visible_to_pos = false) is a clean 404 here too.
     */
    private function posQuery(): Builder
    {
        return Category::query()
            ->where('status', 'active')
            ->where('is_visible_to_pos', true);
    }

    private function presentCategory(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'type' => $category->type,
        ];
    }
}
