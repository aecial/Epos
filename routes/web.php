<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ModifierController;
use App\Http\Controllers\ModifierGroupController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\IngredientGroupController;
use App\Http\Controllers\ItemRecipeController;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Services\InventoryService;
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
        $inventoryService = app(InventoryService::class);
        $items = Item::with('category')->get()->map(function (Item $item) use ($inventoryService) {
            $availableStock = null;

            if ($item->inventory_type !== 'none') {
                try {
                    $availableStock = $inventoryService->AvailableForItem($item);
                } catch (\InvalidArgumentException) {
                }
            }

            $item->setAttribute('available_stock', $availableStock);

            return $item;
        });

        return Inertia::render('ItemManagementPage', ['items' => $items]);
    })->name('item-management');
    Route::get('modifier-management', function () {
        return Inertia::render('ModifierManagementPage');
    })->name('modifier-management');
    Route::get('ingredient-management', function () {
        return Inertia::render('IngredientManagementPage', [
            'ingredientGroups' => IngredientGroup::withCount('ingredients')->orderBy('name')->get(),
            'ingredients' => Ingredient::with('ingredientGroup')->orderBy('name')->get(),
        ]);
    })->name('ingredient-management');
    Route::get('create-ingredient-group', function () {
        return Inertia::render('CreateIngredientGroupPage');
    })->name('create-ingredient-group');
    Route::get('create-ingredient', function () {
        return Inertia::render('CreateIngredientPage', [
            'ingredientGroups' => IngredientGroup::orderBy('name')->get(['id', 'name']),
        ]);
    })->name('create-ingredient');
    Route::get('ingredient-groups/{ingredientGroup}/edit', function (IngredientGroup $ingredientGroup) {
        return Inertia::render('UpdateIngredientGroupPage', [
            'ingredientGroup' => $ingredientGroup,
        ]);
    })->name('ingredient-groups.edit');
    Route::get('ingredients/{ingredient}/edit', function (Ingredient $ingredient) {
        return Inertia::render('UpdateIngredientPage', [
            'ingredient' => $ingredient->load('ingredientGroup'),
            'ingredientGroups' => IngredientGroup::orderBy('name')->get(['id', 'name']),
        ]);
    })->name('ingredients.edit');



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
            'item' => $item->load('ingredients'),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'ingredients' => Ingredient::where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']),
        ]);
    })->name('items.edit');
    Route::get('items', [ItemController::class, 'getItems']);
    Route::get('items/{item}', [ItemController::class, 'getItem']);
    Route::post('items', [ItemController::class, 'createItem'])->name('items.store');
    Route::patch('items/{item}', [ItemController::class, 'updateItem'])->name('items.update');
    Route::delete('items/{item}', [ItemController::class, 'deleteItem'])->name('items.destroy');

    Route::get('ingredient-groups', [IngredientGroupController::class, 'getIngredientGroups'])->name('ingredient-groups.index');
    Route::post('ingredient-groups', [IngredientGroupController::class, 'createIngredientGroup'])->name('ingredient-groups.store');
    Route::patch('ingredient-groups/{ingredientGroup}', [IngredientGroupController::class, 'updateIngredientGroup'])->name('ingredient-groups.update');
    Route::delete('ingredient-groups/{ingredientGroup}', [IngredientGroupController::class, 'deleteIngredientGroup'])->name('ingredient-groups.destroy');

    Route::get('ingredients', [IngredientController::class, 'getIngredients'])->name('ingredients.index');
    Route::post('ingredients', [IngredientController::class, 'createIngredient'])->name('ingredients.store');
    Route::get('ingredients/{ingredient}', [IngredientController::class, 'getIngredient'])->name('ingredients.show');
    Route::patch('ingredients/{ingredient}', [IngredientController::class, 'updateIngredient'])->name('ingredients.update');
    Route::delete('ingredients/{ingredient}', [IngredientController::class, 'deleteIngredient'])->name('ingredients.destroy');

    Route::get('items/{item}/recipe', [ItemRecipeController::class, 'getItemRecipe'])->name('items.recipe.show');
    Route::put('items/{item}/recipe', [ItemRecipeController::class, 'updateItemRecipe'])->name('items.recipe.update');

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
