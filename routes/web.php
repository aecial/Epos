<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ItemController;
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
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
