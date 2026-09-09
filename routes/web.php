<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ModifierController;
use App\Http\Controllers\ModifierGroupController;
use App\Models\Category;
use App\Models\Item;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('back-office', function () {
        return Inertia::render('backOffice');
    })->name('back-office');
    Route::get('category-management', function () {
        return Inertia::render('CategoryManagementPage', [
            'categories' => Category::withCount('items')->get(),
        ]);
    })->name('category-management');
    Route::get('item-management', function () {
        return Inertia::render('ItemManagementPage', [
            'items' => Item::with('category')->get(),
        ]);
    })->name('item-management');
        Route::get('modifier-management', function () {
        return Inertia::render('ModifierManagementPage');
    })->name('modifier-management');
    Route::get('create-category', function () {
        return Inertia::render('CreateCategoryPage');
    })->name('create-category');
    Route::get('create-item', function () {
        return Inertia::render('CreateItemPage', [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    })->name('create-item');
    Route::get('categories/{category}/edit', function (Category $category) {
        return Inertia::render('UpdateCategoryPage', [
            'category' => $category,
        ]);
    })->name('categories.edit');


    Route::get('categories', [CategoryController::class, 'getCategories']);
    Route::post('categories', [CategoryController::class, 'createCategory'])->name('categories.store');
    Route::get('categories/{category}', [CategoryController::class, 'getCategory']);
    Route::patch('categories/{category}', [CategoryController::class, 'updateCategory'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'deleteCategory'])->name('categories.destroy');
    Route::get('items/{item}/edit', function (Item $item) {
        return Inertia::render('UpdateItemPage', [
            'item' => $item,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    })->name('items.edit');
    Route::get('items', [ItemController::class, 'getItems']);
    Route::get('items/{item}', [ItemController::class, 'getItem']);
    Route::post('items', [ItemController::class, 'createItem'])->name('items.store');
    Route::patch('items/{item}', [ItemController::class, 'updateItem'])->name('items.update');
    Route::delete('items/{item}', [ItemController::class, 'deleteItem'])->name('items.destroy');

    Route::get('modifier-groups', [ModifierGroupController::class, 'getModifierGroups']);
    Route::get('modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'getModifierGroup']);
    Route::post('modifier-groups', [ModifierGroupController::class, 'createModifierGroup']);
    Route::put('modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'updateModifierGroup']);
    Route::delete('modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'deleteModifierGroup']);

    Route::get('modifiers', [ModifierController::class, 'getModifiers']);
    Route::get('modifiers/{modifier}', [ModifierController::class, 'getModifier']);
    Route::post('modifiers', [ModifierController::class, 'createModifier']);
    Route::put('modifiers/{modifier}', [ModifierController::class, 'updateModifier']);
    Route::delete('modifiers/{modifier}', [ModifierController::class, 'deleteModifier']);
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
