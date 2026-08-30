<?php

use App\Http\Requests\Category\CreateCategoryRequest;
use App\Models\Category;
use App\Models\User;

function authorizeCategoryRequest(string $role): bool
{
    $user = User::factory()->create(['role' => $role]);
    auth()->setUser($user);

    $request = new CreateCategoryRequest();
    $request->setUserResolver(fn () => auth()->user());

    return $request->authorize();
}

test('admin can read all categories', function () {
    Category::create(['name' => 'Lunch', 'status' => 'active', 'is_visible_to_pos' => true]);
    Category::create(['name' => 'Drinks', 'status' => 'active', 'is_visible_to_pos' => true]);

    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $this->get('/category')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Lunch'])
        ->assertJsonFragment(['name' => 'Drinks']);
});

test('admin can read a single category', function () {
    $category = Category::create(['name' => 'Lunch', 'status' => 'active', 'is_visible_to_pos' => true]);

    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $this->get('/category/' . $category->id)
        ->assertOk()
        ->assertJsonPath('name', 'Lunch');
});

test('admin and manager can authorize category create request', function () {
    expect(authorizeCategoryRequest('admin'))->toBeTrue()
        ->and(authorizeCategoryRequest('manager'))->toBeTrue();
});

test('cashier cannot authorize category create request', function () {
    expect(authorizeCategoryRequest('cashier'))->toBeFalse();
});
