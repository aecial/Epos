<?php

use App\Models\Category;
use App\Models\Item;
use App\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->category = Category::create([
        'name' => 'Itik',
        'status' => 'active',
        'is_visible_to_pos' => true,
    ]);
});

test('service creates an item', function () {
    $service = new ItemService();

    $item = $service->CreateItem(['category_id' => $this->category->id, 'name' => 'Fried Itik', 'base_price' => 295, 'cost_price' => 150, 'quantity' => 10, 'status' => 'available']);

    expect($item)->toBeInstanceOf(Item::class)->and($this->category->name)->toBe('Itik')->and(Item::count())->toBe(1);
});

test('service returns all items', function () {
    

    $service = new ItemService();

    $service->CreateItem(['category_id' => $this->category->id, 'name' => 'Fried Itik', 'base_price' => 295, 'cost_price' => 150, 'quantity' => 10, 'status' => 'available']);

    $service->CreateItem(['category_id' => $this->category->id, 'name' => 'Sisig Itik', 'base_price' => 380, 'cost_price' => 150, 'quantity' => 10, 'status' => 'available']);

    $names = $service->ReadAllItem()->pluck('name')->all();

    expect($names)->toContain('Fried Itik', 'Sisig Itik');


});

test('service can read a single item', function () {
    $item = Item::create(['category_id' => $this->category->id, 'name' => 'Fried Itik', 'base_price' => 295, 'cost_price' => 150, 'quantity' => 10, 'status' => 'available']);

    $service = new ItemService();

    $result = $service->ReadItem($item);

    expect($result->id)->toBe($item->id)->and($result->name)->toBe('Fried Itik');
});

test('service can update an item', function () {
    $item = Item::create(['category_id' => $this->category->id, 'name' => 'Fried Itik', 'base_price' => 295, 'cost_price' => 150, 'quantity' => 10, 'status' => 'available']);

    $service = new ItemService();

    $updated = $service->UpdateItem(['base_price' => 300], $item);

    expect($updated->base_price)->toBe(300);
});

test('service can delete an item', function () {
    $item = Item::create(['category_id' => $this->category->id, 'name' => 'Fried Itik', 'base_price' => 295, 'cost_price' => 150, 'quantity' => 10, 'status' => 'available']);

    $service = new ItemService();

    expect($service->DeleteItem($item))->toBeTrue()->and(Item::count())->toBe(0);
});
