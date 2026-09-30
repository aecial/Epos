<?php

namespace App\Services;

use App\Models\Category;
use App\Services\Concerns\DeletesSafely;

class CategoryService
{
    use DeletesSafely;

    public function CreateCategory(array $data)
    {
        return Category::create($data);
    }

    public function ReadAllCategory()
    {
        return Category::all();
    }

    public function ReadCategory(Category $category)
    {
        return $category;
    }

    public function UpdateCategory(array $data, Category $category)
    {
        $category->update($data);

        return $category;
    }

    public function DeleteCategory(Category $category)
    {
        return $this->deleteOrFail($category, 'This category cannot be deleted because it (or an item in it) is referenced by past orders.');
    }
}
