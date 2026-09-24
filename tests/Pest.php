<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
| POS helpers - build shifts, items and tickets through the real services so tests exercise
| the same code paths as the API.
*/

function posUser(string $role = 'cashier'): App\Models\User
{
    return App\Models\User::factory()->create(['role' => $role]);
}

function posOpenShift(App\Models\User $by): App\Models\Shift
{
    return app(App\Services\ShiftService::class)->OpenShift($by, 1000);
}

function posItem(string $name, float $price, int $quantity = 50): App\Models\Item
{
    $category = App\Models\Category::firstOrCreate(['name' => 'Test Category'], ['status' => 'active', 'is_visible_to_pos' => true]);

    return App\Models\Item::create([
        'category_id' => $category->id,
        'name' => $name,
        'base_price' => $price,
        'cost_price' => 0,
        'quantity' => $quantity,
        'inventory_type' => 'direct',
        'status' => 'available',
    ]);
}

function posTicket(App\Models\Shift $shift, App\Models\User $user, string $customer, string $terminal = 'POS-01'): App\Models\Ticket
{
    return app(App\Services\TicketService::class)->CreateTicket($shift, $user, $terminal, $customer, 'dine_in');
}

function posAddItem(App\Models\Ticket $ticket, App\Models\Item $item, int $quantity = 1, ?string $notes = null): App\Models\TicketItem
{
    return app(App\Services\TicketService::class)->AddItem($ticket, $item->fresh(), $quantity, [], $notes);
}

/** @param array<int, array<string, mixed>> $charges */
function posPay(App\Models\Ticket $ticket, App\Models\User $cashier, array $charges): App\Models\Ticket
{
    return app(App\Services\PaymentService::class)->ChargeTicket($ticket, $cashier, $charges);
}
