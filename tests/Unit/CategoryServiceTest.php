<?php

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
