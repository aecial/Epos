<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ModifierController;
use App\Http\Controllers\ModifierGroupController;
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


    Route::get('category', [CategoryController::class, 'getCategories']);
    Route::get('category/{category}', [CategoryController::class, 'getCategory']);
    Route::get('items', [ItemController::class, 'getItems']);
    Route::get('items/{item}', [ItemController::class, 'getItem']);

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
