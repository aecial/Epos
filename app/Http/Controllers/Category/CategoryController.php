<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
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
}
