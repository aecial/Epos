<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\IngredientGroupController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ItemRecipeController;
use App\Http\Controllers\KitchenOrderController;
use App\Http\Controllers\ModifierController;
use App\Http\Controllers\ModifierGroupController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    // Every role may log in to the back office (CLAUDE.md role matrix), so dashboard and the
    // hub stay open to all; each management resource below is gated by its own Policy
    // (admin/manager only today — see App\Policies\Concerns\AuthorizesBackOffice).
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
    })->middleware('can:viewAny,'.Category::class)->name('category-management');
    Route::get('item-management', function () {
        $inventoryService = app(InventoryService::class);
        $items = Item::with(['category', 'ingredients'])->withCount('modifiers')->get()->map(function (Item $item) use ($inventoryService) {
            $availableStock = null;
            $recipeCost = null;

            if ($item->inventory_type === 'recipe' && $item->ingredients->isNotEmpty()) {
                $recipeCost = $item->ingredients->sum(function ($ingredient): float {
                    return (float) $ingredient->cost_per_unit * (float) $ingredient->pivot->quantity_required;
                });
            }

            if ($item->inventory_type !== 'none') {
                try {
                    $availableStock = $inventoryService->AvailableFromLoaded($item);
                } catch (InvalidArgumentException) {
                }
            }

            $item->setAttribute('available_stock', $availableStock);
            $item->setAttribute('calculated_cost_price', $recipeCost);

            return $item;
        });

        return Inertia::render('ItemManagementPage', ['items' => $items]);
    })->middleware('can:viewAny,'.Item::class)->name('item-management');
    Route::get('modifier-management', function () {
        return Inertia::render('ModifierManagementPage', [
            'modifierGroups' => ModifierGroup::withCount('modifiers')->with('modifiers')->orderBy('name')->get(),
            'modifiers' => Modifier::with('group')->orderBy('name')->get(),
        ]);
    })->middleware('can:viewAny,'.ModifierGroup::class)->name('modifier-management');
    Route::get('shifts', [ShiftController::class, 'getShifts'])->middleware('can:viewAny,'.Shift::class)->name('shifts.index');
    Route::get('shifts/{shift}', [ShiftController::class, 'getShift'])->middleware('can:view,shift')->name('shifts.show');
    Route::get('tickets', [TicketController::class, 'getTickets'])->middleware('can:viewAny,'.Ticket::class)->name('tickets.index');
    Route::get('tickets/{ticket}', [TicketController::class, 'getTicket'])->middleware('can:view,ticket')->name('tickets.show');
    Route::get('kitchen-orders', [KitchenOrderController::class, 'getOrders'])->middleware('can:viewAny,'.Ticket::class)->name('kitchen-orders.index');
    Route::patch('kitchen-orders/items/{ticketItem}/complete', [KitchenOrderController::class, 'completeItem'])->middleware('can:bumpKitchenOrders,'.Ticket::class)->name('kitchen-orders.items.complete');
    Route::patch('kitchen-orders/{ticket}/complete', [KitchenOrderController::class, 'completeTicket'])->middleware('can:bumpKitchenOrders,'.Ticket::class)->name('kitchen-orders.complete');
    Route::get('employee-management', [UserController::class, 'getUsers'])->middleware('can:viewAny,'.User::class)->name('employee-management');
    Route::get('users/create', [UserController::class, 'getCreateUser'])->middleware('can:create,'.User::class)->name('users.create');
    Route::post('users', [UserController::class, 'createUser'])->middleware('can:create,'.User::class)->name('users.store');
    Route::get('users/{user}/edit', function (User $user) {
        abort_if($user->role === 'admin', 403);

        return Inertia::render('UpdateUserPage', ['user' => $user]);
    })->middleware('can:update,user')->name('users.edit');
    Route::patch('users/{user}', [UserController::class, 'updateUser'])->middleware('can:update,user')->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'deleteUser'])->middleware('can:delete,user')->name('users.destroy');
    Route::get('users/{user}/sessions', [UserController::class, 'getUserSessions'])->middleware('can:update,user')->name('users.sessions');
    Route::delete('users/{user}/sessions/{token}', [UserController::class, 'revokeUserSession'])->middleware('can:update,user')->name('users.sessions.revoke');
    Route::delete('users/{user}/sessions', [UserController::class, 'revokeAllUserSessions'])->middleware('can:update,user')->name('users.sessions.revoke-all');
    Route::get('create-modifier-group', function () {
        return Inertia::render('CreateModifierGroupPage');
    })->middleware('can:create,'.ModifierGroup::class)->name('create-modifier-group');
    Route::get('create-modifier', function () {
        return Inertia::render('CreateModifierPage', [
            'modifierGroups' => ModifierGroup::orderBy('name')->get(['id', 'name']),
        ]);
    })->middleware('can:create,'.Modifier::class)->name('create-modifier');
    Route::get('modifier-groups/{modifierGroup}/edit', function (ModifierGroup $modifierGroup) {
        return Inertia::render('UpdateModifierGroupPage', [
            'modifierGroup' => $modifierGroup,
        ]);
    })->middleware('can:update,modifierGroup')->name('modifier-groups.edit');
    Route::get('modifiers/{modifier}/edit', function (Modifier $modifier) {
        return Inertia::render('UpdateModifierPage', [
            'modifier' => $modifier->load('group'),
            'modifierGroups' => ModifierGroup::orderBy('name')->get(['id', 'name']),
        ]);
    })->middleware('can:update,modifier')->name('modifiers.edit');
    Route::get('ingredient-management', function () {
        return Inertia::render('IngredientManagementPage', [
            'ingredientGroups' => IngredientGroup::withCount('ingredients')->orderBy('name')->get(),
            'ingredients' => Ingredient::with('ingredientGroup')->orderBy('name')->get(),
        ]);
    })->middleware('can:viewAny,'.IngredientGroup::class)->name('ingredient-management');
    Route::get('create-ingredient-group', function () {
        return Inertia::render('CreateIngredientGroupPage');
    })->middleware('can:create,'.IngredientGroup::class)->name('create-ingredient-group');
    Route::get('create-ingredient', function () {
        return Inertia::render('CreateIngredientPage', [
            'ingredientGroups' => IngredientGroup::orderBy('name')->get(['id', 'name']),
        ]);
    })->middleware('can:create,'.Ingredient::class)->name('create-ingredient');
    Route::get('ingredient-groups/{ingredientGroup}/edit', function (IngredientGroup $ingredientGroup) {
        return Inertia::render('UpdateIngredientGroupPage', [
            'ingredientGroup' => $ingredientGroup,
        ]);
    })->middleware('can:update,ingredientGroup')->name('ingredient-groups.edit');
    Route::get('ingredients/{ingredient}/edit', function (Ingredient $ingredient) {
        return Inertia::render('UpdateIngredientPage', [
            'ingredient' => $ingredient->load('ingredientGroup'),
            'ingredientGroups' => IngredientGroup::orderBy('name')->get(['id', 'name']),
        ]);
    })->middleware('can:update,ingredient')->name('ingredients.edit');

    Route::get('create-category', function () {
        return Inertia::render('CreateCategoryPage');
    })->middleware('can:create,'.Category::class)->name('create-category');
    Route::get('create-item', function () {
        return Inertia::render('CreateItemPage', [
            'categories' => Category::orderBy('name')->get(['id', 'name', 'type']),
            'ingredients' => Ingredient::where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']),
            'modifierGroups' => ModifierGroup::query()
                ->with(['modifiers' => fn ($query) => $query->where('status', 'active')->orderBy('name')])
                ->whereHas('modifiers', fn ($query) => $query->where('status', 'active'))
                ->orderBy('name')
                ->get(['id', 'name', 'is_required']),
        ]);
    })->middleware('can:create,'.Item::class)->name('create-item');
    Route::get('categories/{category}/edit', function (Category $category) {
        return Inertia::render('UpdateCategoryPage', [
            'category' => $category->loadCount('items'),
        ]);
    })->middleware('can:update,category')->name('categories.edit');

    Route::get('categories', [CategoryController::class, 'getCategories'])->middleware('can:viewAny,'.Category::class);
    Route::post('categories', [CategoryController::class, 'createCategory'])->middleware('can:create,'.Category::class)->name('categories.store');
    Route::get('categories/{category}', [CategoryController::class, 'getCategory'])->middleware('can:view,category');
    Route::patch('categories/{category}', [CategoryController::class, 'updateCategory'])->middleware('can:update,category')->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'deleteCategory'])->middleware('can:delete,category')->name('categories.destroy');
    Route::get('items/{item}/edit', function (Item $item) {
        return Inertia::render('UpdateItemPage', [
            'item' => $item->load(['ingredients', 'modifiers' => fn ($query) => $query->orderByPivot('display_order')]),
            'categories' => Category::orderBy('name')->get(['id', 'name', 'type']),
            'ingredients' => Ingredient::where('status', 'active')->orderBy('name')->get(['id', 'name', 'unit']),
            'modifierGroups' => ModifierGroup::query()
                ->with(['modifiers' => fn ($query) => $query->where('status', 'active')->orderBy('name')])
                ->whereHas('modifiers', fn ($query) => $query->where('status', 'active'))
                ->orderBy('name')
                ->get(['id', 'name', 'is_required']),
        ]);
    })->middleware('can:update,item')->name('items.edit');
    Route::get('items', [ItemController::class, 'getItems'])->middleware('can:viewAny,'.Item::class);
    Route::get('items/{item}', [ItemController::class, 'getItem'])->middleware('can:view,item');
    Route::post('items', [ItemController::class, 'createItem'])->middleware('can:create,'.Item::class)->name('items.store');
    Route::patch('items/{item}', [ItemController::class, 'updateItem'])->middleware('can:update,item')->name('items.update');
    Route::delete('items/{item}', [ItemController::class, 'deleteItem'])->middleware('can:delete,item')->name('items.destroy');

    Route::get('ingredient-groups', [IngredientGroupController::class, 'getIngredientGroups'])->middleware('can:viewAny,'.IngredientGroup::class)->name('ingredient-groups.index');
    Route::post('ingredient-groups', [IngredientGroupController::class, 'createIngredientGroup'])->middleware('can:create,'.IngredientGroup::class)->name('ingredient-groups.store');
    Route::patch('ingredient-groups/{ingredientGroup}', [IngredientGroupController::class, 'updateIngredientGroup'])->middleware('can:update,ingredientGroup')->name('ingredient-groups.update');
    Route::delete('ingredient-groups/{ingredientGroup}', [IngredientGroupController::class, 'deleteIngredientGroup'])->middleware('can:delete,ingredientGroup')->name('ingredient-groups.destroy');

    Route::get('ingredients', [IngredientController::class, 'getIngredients'])->middleware('can:viewAny,'.Ingredient::class)->name('ingredients.index');
    Route::post('ingredients', [IngredientController::class, 'createIngredient'])->middleware('can:create,'.Ingredient::class)->name('ingredients.store');
    Route::get('ingredients/{ingredient}', [IngredientController::class, 'getIngredient'])->middleware('can:view,ingredient')->name('ingredients.show');
    Route::patch('ingredients/{ingredient}', [IngredientController::class, 'updateIngredient'])->middleware('can:update,ingredient')->name('ingredients.update');
    Route::delete('ingredients/{ingredient}', [IngredientController::class, 'deleteIngredient'])->middleware('can:delete,ingredient')->name('ingredients.destroy');

    Route::get('items/{item}/recipe', [ItemRecipeController::class, 'getItemRecipe'])->middleware('can:view,item')->name('items.recipe.show');
    Route::put('items/{item}/recipe', [ItemRecipeController::class, 'updateItemRecipe'])->middleware('can:update,item')->name('items.recipe.update');

    Route::get('modifier-groups', [ModifierGroupController::class, 'getModifierGroups'])->middleware('can:viewAny,'.ModifierGroup::class)->name('modifier-groups.index');
    Route::get('modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'getModifierGroup'])->middleware('can:view,modifierGroup')->name('modifier-groups.show');
    Route::post('modifier-groups', [ModifierGroupController::class, 'createModifierGroup'])->middleware('can:create,'.ModifierGroup::class)->name('modifier-groups.store');
    Route::patch('modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'updateModifierGroup'])->middleware('can:update,modifierGroup')->name('modifier-groups.update');
    Route::delete('modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'deleteModifierGroup'])->middleware('can:delete,modifierGroup')->name('modifier-groups.destroy');

    Route::get('modifiers', [ModifierController::class, 'getModifiers'])->middleware('can:viewAny,'.Modifier::class)->name('modifiers.index');
    Route::get('modifiers/{modifier}', [ModifierController::class, 'getModifier'])->middleware('can:view,modifier')->name('modifiers.show');
    Route::post('modifiers', [ModifierController::class, 'createModifier'])->middleware('can:create,'.Modifier::class)->name('modifiers.store');
    Route::patch('modifiers/{modifier}', [ModifierController::class, 'updateModifier'])->middleware('can:update,modifier')->name('modifiers.update');
    Route::delete('modifiers/{modifier}', [ModifierController::class, 'deleteModifier'])->middleware('can:delete,modifier')->name('modifiers.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
