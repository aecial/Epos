<?php

namespace App\Http\Controllers;

use App\Http\Requests\Category\CreateCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;

class CategoryController extends Controller
{
    protected CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    public function getCategories()
    {
        return $this->categoryService->ReadAllCategory();
    }

    public function getCategory(Category $category)
    {
        return $this->categoryService->ReadCategory($category);
    }
    public function createCategory(CreateCategoryRequest $request)
    {
        return $this->categoryService->CreateCategory($request->validated());
    }

    public function updateCategory(Category $category, UpdateCategoryRequest $request)
    {
        return $this->categoryService->UpdateCategory($request->validated(), $category);
    }

    public function deleteCategory(Category $category)
    {
        return $this->categoryService->DeleteCategory($category);
    }
}
