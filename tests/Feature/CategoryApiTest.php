<?php

use App\Models\Category;
use Laravel\Sanctum\Sanctum;

function apiCategoryOfType(string $name, string $type = 'menu', bool $visibleToPos = true, string $status = 'active'): Category
{
    return Category::create(['name' => $name, 'type' => $type, 'status' => $status, 'is_visible_to_pos' => $visibleToPos]);
}

test('the categories endpoints require authentication', function () {
    $this->getJson('/api/v1/categories')->assertUnauthorized();
    $this->getJson('/api/v1/categories/1')->assertUnauthorized();
});

test('a cashier can list POS-visible categories, sorted by name', function () {
    apiCategoryOfType('Mains');
    apiCategoryOfType('Drinks');
    apiCategoryOfType('Hidden From POS', 'menu', false);
    apiCategoryOfType('Inactive', 'menu', true, 'inactive');

    Sanctum::actingAs(posUser('cashier'));

    $response = $this->getJson('/api/v1/categories')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.name'))->toBe('Drinks');
    expect($response->json('data.1.name'))->toBe('Mains');
    expect($response->json('data.0'))->toHaveKeys(['id', 'name', 'type']);
});

test('a special category is included with its type', function () {
    apiCategoryOfType('Fees', 'special');

    Sanctum::actingAs(posUser('cashier'));

    $this->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'special');
});

test('a single POS-visible category can be fetched', function () {
    $category = apiCategoryOfType('Mains');

    Sanctum::actingAs(posUser('cashier'));

    $this->getJson("/api/v1/categories/{$category->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $category->id)
        ->assertJsonPath('data.name', 'Mains');
});

test('a category hidden from the POS is a 404', function () {
    $category = apiCategoryOfType('Back Office Only', 'menu', false);

    Sanctum::actingAs(posUser('cashier'));

    $this->getJson("/api/v1/categories/{$category->id}")->assertNotFound();
});

test('an inactive category is a 404', function () {
    $category = apiCategoryOfType('Retired', 'menu', true, 'inactive');

    Sanctum::actingAs(posUser('cashier'));

    $this->getJson("/api/v1/categories/{$category->id}")->assertNotFound();
});
