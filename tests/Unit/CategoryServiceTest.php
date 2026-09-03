<?php

use App\Http\Controllers\CategoryController;
use App\Http\Requests\Category\CreateCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('service returns all categories', function () {
    Category::create(['name' => 'Lunch', 'status' => 'active', 'is_visible_to_pos' => true]);
    Category::create(['name' => 'Drinks', 'status' => 'active', 'is_visible_to_pos' => true]);

    $service = new CategoryService();

    $names = $service->ReadAllCategory()->pluck('name')->all();

    expect($names)->toContain('Lunch', 'Drinks');
});

test('service returns one category by model instance', function () {
    $category = Category::create(['name' => 'Lunch', 'status' => 'active', 'is_visible_to_pos' => true]);

    $service = new CategoryService();

    $result = $service->ReadCategory($category);

    expect($result->id)->toBe($category->id)
        ->and($result->name)->toBe('Lunch');
});

test('service creates a category', function () {
    $service = new CategoryService();

    $category = $service->CreateCategory([
        'name' => 'Desserts',
        'status' => 'active',
        'is_visible_to_pos' => true,
    ]);

    expect($category)->toBeInstanceOf(Category::class)
        ->and($category->name)->toBe('Desserts')
        ->and(Category::count())->toBe(1);
});

test('service updates a category', function () {
    $category = Category::create([
        'name' => 'Lunch',
        'status' => 'active',
        'is_visible_to_pos' => true,
    ]);

    $service = new CategoryService();

    $updated = $service->UpdateCategory([
        'name' => 'Dinner',
        'status' => 'inactive',
        'is_visible_to_pos' => false,
    ], $category);

    expect($updated->name)->toBe('Dinner')
        ->and($updated->status)->toBe('inactive')
        ->and($updated->is_visible_to_pos)->toBeFalse();
});

test('service deletes a category', function () {
    $category = Category::create([
        'name' => 'Lunch',
        'status' => 'active',
        'is_visible_to_pos' => true,
    ]);

    $service = new CategoryService();

    expect($service->DeleteCategory($category))->toBeTrue()
        ->and(Category::count())->toBe(0);
});

test('controller delegates createCategory to the service', function () {
    $data = [
        'name' => 'Desserts',
        'status' => 'active',
        'is_visible_to_pos' => true,
    ];

    $category = new Category($data);

    $request = Mockery::mock(CreateCategoryRequest::class);
    $request->shouldReceive('validated')->once()->andReturn($data);

    $service = Mockery::mock(CategoryService::class);
    $service->shouldReceive('CreateCategory')->once()->with($data)->andReturn($category);

    $controller = new CategoryController($service);

    expect($controller->createCategory($request))->toBe($category);
});

test('controller delegates updateCategory to the service', function () {
    $category = new Category([
        'name' => 'Lunch',
        'status' => 'active',
        'is_visible_to_pos' => true,
    ]);
    $category->id = 1;

    $data = [
        'name' => 'Dinner',
        'status' => 'inactive',
        'is_visible_to_pos' => false,
    ];

    $request = Mockery::mock(UpdateCategoryRequest::class);
    $request->shouldReceive('validated')->once()->andReturn($data);

    $service = Mockery::mock(CategoryService::class);
    $service->shouldReceive('UpdateCategory')->once()->with($data, $category)->andReturn($category);

    $controller = new CategoryController($service);

    expect($controller->updateCategory($category, $request))->toBe($category);
});

test('controller delegates deleteCategory to the service', function () {
    $category = new Category([
        'name' => 'Lunch',
        'status' => 'active',
        'is_visible_to_pos' => true,
    ]);
    $category->id = 1;

    $service = Mockery::mock(CategoryService::class);
    $service->shouldReceive('DeleteCategory')->once()->with($category)->andReturnTrue();

    $controller = new CategoryController($service);

    expect($controller->deleteCategory($category))->toBeTrue();
});
