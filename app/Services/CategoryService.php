<?php

namespace App\Services;

use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;

class CategoryService
{
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

    public function UpdateCategory(UpdateCategoryRequest $request, Category $category)
    {
        $category->update($request->validated());

        return $category;
    }

    public function DeleteCategory(Category $category)
    {
        return $category->delete();
    }
}
