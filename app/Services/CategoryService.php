<?php

namespace App\Services;

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

    public function UpdateCategory(array $data, Category $category)
    {
        $category->update($data);

        return $category;
    }

    public function DeleteCategory(Category $category)
    {
        return $category->delete();
    }
}
