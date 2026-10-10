<?php

use App\Models\Category;
use App\Models\Item;
use App\Models\PosDevice;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\PosDeviceService;
use App\Services\ShiftService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

function posUser(string $role = 'cashier'): User
{
    return User::factory()->create(['role' => $role]);
}

function posOpenShift(User $by): Shift
{
    return app(ShiftService::class)->OpenShift($by, 1000);
}

function posItem(string $name, float $price, int $quantity = 50): Item
{
    $category = Category::firstOrCreate(['name' => 'Test Category'], ['status' => 'active', 'is_visible_to_pos' => true]);

    return Item::create([
        'category_id' => $category->id,
        'name' => $name,
        'base_price' => $price,
        'cost_price' => 0,
        'quantity' => $quantity,
        'inventory_type' => 'direct',
        'status' => 'available',
    ]);
}

function posTicket(Shift $shift, User $user, string $customer, string $terminal = 'POS-01'): Ticket
{
    return app(TicketService::class)->CreateTicket($shift, $user, $terminal, $customer, 'dine_in');
}

function posAddItem(Ticket $ticket, Item $item, int $quantity = 1, ?string $notes = null): TicketItem
{
    return app(TicketService::class)->AddItem($ticket, $item->fresh(), $quantity, [], $notes);
}

/** @param array<int, array<string, mixed>> $charges */
function posPay(Ticket $ticket, User $cashier, array $charges): Ticket
{
    return app(PaymentService::class)->ChargeTicket($ticket, $cashier, $charges);
}

/*
| Offline sync helpers - a phone signed in as $role with a real token and device code, and the
| actions it sends to POST /api/v1/sync. An action with a time happened offline at that time.
*/

/** @return array{user: User, token: string, device: PosDevice} */
function syncPhone(string $role = 'cashier', string $name = 'POS-01'): array
{
    $user = posUser($role);
    $token = $user->createToken($name);
    $device = app(PosDeviceService::class)->RegisterDevice($user, $token->accessToken);

    return ['user' => $user, 'token' => $token->plainTextToken, 'device' => $device];
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function syncAction(string $type, array $data, ?string $offlineAt = null): array
{
    return [
        'id' => (string) Str::uuid(),
        'type' => $type,
        'offline' => $offlineAt !== null,
        'happened_at' => $offlineAt === null ? null : Carbon\Carbon::parse($offlineAt, 'Asia/Manila')->toIso8601String(),
        'data' => $data,
    ];
}

/** @param  array<int, array<string, mixed>>  $actions */
function syncSend(string $token, array $actions, int $pending = 0): TestResponse
{
    // Several phones in one test: forget the last token so Sanctum reads this one.
    app('auth')->forgetGuards();

    $response = test()->withToken($token)->postJson('/api/v1/sync', ['actions' => $actions, 'pending' => $pending]);

    // auth:sanctum made Sanctum the default guard; put back the web one (and drop the token) so
    // a later actingAs() in the same test signs into the back office as usual.
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    test()->flushHeaders();

    return $response;
}

function syncUuid(): string
{
    return (string) Str::uuid();
}
