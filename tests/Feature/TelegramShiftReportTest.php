<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ShiftService;
use App\Services\TicketService;
use Database\Seeders\TelegramDemoSeeder;
use Database\Seeders\TelegramLateSaleDemoSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
| Telegram shift report: once a shift closes, the every-minute `telegram:shift-reports` run posts
| its items sold (by category) and raw materials used (by ingredient group) to the group chat -
| names and quantities only. Late offline sales follow as their own message.
*/

beforeEach(function () {
    config(['services.telegram.bot_token' => '123:SECRET', 'services.telegram.chat_id' => '-100200300']);
    Http::preventStrayRequests();
});

/** Telegram accepts every call (tests that need another answer fake their own instead). */
function telegramAccepts(): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
}

/** Fried Itik and Adobong Itik both take 1 Itik; Sisig Itik offers the stockless "Lagi". */
function telegramMenu(): array
{
    $meats = IngredientGroup::create(['name' => 'Meats']);
    $pantry = IngredientGroup::create(['name' => 'Pantry']);
    $itik = Ingredient::create(['ingredient_group_id' => $meats->id, 'name' => 'Itik', 'unit' => 'piece', 'quantity' => 50, 'cost_per_unit' => 180]);
    $oil = Ingredient::create(['ingredient_group_id' => $pantry->id, 'name' => 'Cooking Oil', 'unit' => 'liter', 'quantity' => 20, 'cost_per_unit' => 90]);

    $itikDishes = Category::create(['name' => 'Itik Dishes', 'status' => 'active', 'is_visible_to_pos' => true]);
    $drinks = Category::create(['name' => 'Drinks', 'status' => 'active', 'is_visible_to_pos' => true]);
    $special = Category::create(['name' => 'Special', 'type' => 'special', 'status' => 'active', 'is_visible_to_pos' => true]);

    $recipe = function (string $name, float $price, array $uses) use ($itikDishes): Item {
        $item = Item::create(['category_id' => $itikDishes->id, 'name' => $name, 'base_price' => $price, 'cost_price' => 0, 'inventory_type' => 'recipe', 'status' => 'available']);

        foreach ($uses as [$ingredient, $quantity]) {
            $item->ingredients()->attach($ingredient->id, ['quantity_required' => $quantity, 'unit' => $ingredient->unit]);
        }

        return $item;
    };

    $sisig = $recipe('Sisig Itik', 249, [[$itik, 1]]);
    $lagi = Modifier::create(['modifier_group_id' => ModifierGroup::create(['name' => 'Variant'])->id, 'name' => 'Lagi', 'status' => 'active', 'is_stockless_variant' => true]);
    $sisig->modifiers()->attach($lagi->id, ['price_modifier' => 0, 'status' => 'active', 'display_order' => 1]);

    return [
        'fried' => $recipe('Fried Itik', 350, [[$itik, 1], [$oil, 0.25]]),
        'adobo' => $recipe('Adobong Itik', 320, [[$itik, 1]]),
        'sisig' => $sisig,
        'lagi' => $lagi,
        'coke' => Item::create(['category_id' => $drinks->id, 'name' => 'Coke', 'base_price' => 40, 'cost_price' => 25, 'quantity' => 100, 'inventory_type' => 'direct', 'status' => 'available']),
        'corkage' => Item::create(['category_id' => $special->id, 'name' => 'Corkage', 'base_price' => 0, 'inventory_type' => 'none', 'entry_mode' => 'price', 'status' => 'available']),
        'custom' => Item::create(['category_id' => $special->id, 'name' => 'Custom Item', 'base_price' => 0, 'inventory_type' => 'none', 'entry_mode' => 'name_price', 'status' => 'available']),
    ];
}

/**
 * One paid ticket with the given lines: [item, quantity, modifierIds?, unitPrice?, customName?].
 *
 * @param  array<int, array<int, mixed>>  $lines
 */
function telegramSale(Shift $shift, User $cashier, string $customer, array $lines): Ticket
{
    $ticket = posTicket($shift, $cashier, $customer);

    foreach ($lines as $line) {
        app(TicketService::class)->AddItem($ticket->fresh(), $line[0]->fresh(), $line[1], $line[2] ?? [], null, $line[3] ?? null, $line[4] ?? null);
    }

    $total = (float) $ticket->fresh()->total;

    return posPay($ticket->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => $total, 'tendered_amount' => $total]]);
}

/** @return array<int, string> the texts sent to Telegram, in order */
function telegramTexts(): array
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/sendMessage'))
        ->map(fn (array $pair): string => $pair[0]['text'])
        ->values()
        ->all();
}

test('a closed shift is posted once: items by category, variants and special items separate, raw materials by group', function () {
    telegramAccepts();
    $menu = telegramMenu();
    $cashier = posUser();
    $manager = User::factory()->create(['role' => 'manager', 'name' => 'Kring']);
    $shift = posOpenShift($cashier);

    telegramSale($shift, $cashier, 'john', [[$menu['fried'], 2], [$menu['adobo'], 1], [$menu['coke'], 3]]);
    telegramSale($shift, $cashier, 'mary', [[$menu['sisig'], 2, [$menu['lagi']->id]], [$menu['sisig'], 1], [$menu['corkage'], 1, [], 150]]);
    telegramSale($shift, $cashier, 'ana', [[$menu['fried'], 1], [$menu['custom'], 1, [], 99, 'Birthday Cake Slicing']]);

    // Not sold: a voided line and a cancelled ticket.
    $voided = posTicket($shift, $cashier, 'leo');
    $line = app(TicketService::class)->AddItem($voided, $menu['adobo']->fresh(), 4);
    $line->update(['voided_at' => now()]);
    app(TicketService::class)->AddItem($voided->fresh(), $menu['coke']->fresh(), 1);
    posPay($voided->fresh(), $cashier, [['payment_method' => 'cash', 'amount' => 40, 'tendered_amount' => 40]]);
    $cancelled = posTicket($shift, $cashier, 'max');
    app(TicketService::class)->AddItem($cancelled, $menu['fried']->fresh(), 5);
    app(TicketService::class)->CancelTicket($cancelled->fresh(), $cashier);

    app(ShiftService::class)->CloseShift($shift, $manager, 1000);

    $this->artisan('telegram:shift-reports')->assertSuccessful();

    expect(telegramTexts())->toHaveCount(1);
    $text = telegramTexts()[0];

    expect($text)->toContain("<b>Shift #{$shift->id} closed</b>")
        ->toContain('Closed by Kring')
        ->toContain('<b>ITEMS SOLD</b> (4 tickets)')
        ->toContain(implode("\n", ['<b>Drinks</b>', 'Coke — 4']))
        ->toContain(implode("\n", ['<b>Itik Dishes</b>', 'Fried Itik — 3', 'Sisig Itik · Lagi — 2', 'Adobong Itik — 1', 'Sisig Itik — 1']))
        ->toContain(implode("\n", ['<b>Special</b>', 'Birthday Cake Slicing — 1', 'Corkage — 1']))
        // 3 Fried + 1 Adobo + 1 normal Sisig = 5 Itik; Lagi took none. 3 Fried x 0.25 L oil.
        ->toContain(implode("\n", ['<b>RAW MATERIALS USED</b>', '', '<b>Meats</b>', 'Itik — 5 pcs', '', '<b>Pantry</b>', 'Cooking Oil — 0.75 L']))
        ->not->toContain('Adobong Itik — 5');

    // Categories in name order, special ones last.
    expect(strpos($text, '<b>Drinks</b>'))->toBeLessThan(strpos($text, '<b>Itik Dishes</b>'))
        ->and(strpos($text, '<b>Itik Dishes</b>'))->toBeLessThan(strpos($text, '<b>Special</b>'));

    expect($shift->fresh()->sales_report_sent_at)->not->toBeNull()
        ->and(Ticket::query()->where('status', 'paid')->whereNull('sales_report_sent_at')->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot123:SECRET/sendMessage'
        && $request['chat_id'] === '-100200300'
        && $request['parse_mode'] === 'HTML');

    // The next minute's run has nothing new to send.
    $this->artisan('telegram:shift-reports')->assertSuccessful();
    expect(telegramTexts())->toHaveCount(1);
});

test('an open shift is not posted, and a shift with no sales still is', function () {
    telegramAccepts();
    $cashier = posUser();
    $shift = posOpenShift($cashier);

    $this->artisan('telegram:shift-reports')->assertSuccessful();
    expect(telegramTexts())->toBe([]);

    app(ShiftService::class)->CloseShift($shift, $cashier, 1000);
    $this->artisan('telegram:shift-reports')->assertSuccessful();

    expect(telegramTexts())->toHaveCount(1)
        ->and(telegramTexts()[0])->toContain("<b>ITEMS SOLD</b> (0 tickets)\nNo sales.")
        ->not->toContain('RAW MATERIALS');
});

test('offline sales synced after the shift closed go out as a follow-up with only those sales', function () {
    telegramAccepts();
    $menu = telegramMenu();
    $manager = posUser('manager');
    $phone = syncPhone();
    $shift = posOpenShift($manager);
    $shift->update(['opened_at' => Carbon::parse('2026-10-14 10:00', 'Asia/Manila')]);
    telegramSale($shift, $manager, 'john', [[$menu['coke'], 2]]);
    app(ShiftService::class)->CloseShift($shift, $manager, 1000);

    $this->artisan('telegram:shift-reports')->assertSuccessful();
    expect(telegramTexts())->toHaveCount(1);

    $ticket = syncUuid();
    syncSend($phone['token'], [
        syncAction('shift.open', ['shift_uuid' => $phoneShift = syncUuid(), 'starting_cash' => 1000], '2026-10-14 12:00'),
        syncAction('ticket.create', ['ticket_uuid' => $ticket, 'shift_uuid' => $phoneShift, 'terminal_id' => 'POS', 'customer_name' => 'Late', 'order_type' => 'dine_in'], '2026-10-14 12:10'),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticket, 'line_uuid' => syncUuid(), 'item_id' => $menu['fried']->id, 'quantity' => 2, 'unit_price' => 350], '2026-10-14 12:10'),
        syncAction('ticket.charge', ['ticket_uuid' => $ticket, 'charges' => [
            ['charge_uuid' => syncUuid(), 'payment_method' => 'cash', 'amount' => 700, 'tendered_amount' => 700],
        ]], '2026-10-14 12:15'),
    ])->assertOk();

    expect(Ticket::query()->where('client_uuid', $ticket)->value('shift_id'))->toBe($shift->id);

    $this->artisan('telegram:shift-reports')->assertSuccessful();

    expect(telegramTexts())->toHaveCount(2);
    expect(telegramTexts()[1])->toContain("<b>Shift #{$shift->id} — late sales</b>")
        ->toContain('<b>ITEMS SOLD</b> (1 ticket)')
        ->toContain('Fried Itik — 2')
        ->toContain('Itik — 2 pcs')
        ->not->toContain('Coke');

    $this->artisan('telegram:shift-reports')->assertSuccessful();
    expect(telegramTexts())->toHaveCount(2);
});

test('nothing is sent or marked until the bot is set up', function () {
    telegramAccepts();
    config(['services.telegram.bot_token' => null]);
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    app(ShiftService::class)->CloseShift($shift, $cashier, 1000);

    $this->artisan('telegram:shift-reports')->assertSuccessful();

    Http::assertNothingSent();
    expect($shift->fresh()->sales_report_sent_at)->toBeNull();
});

test('a report Telegram refuses is retried on the next run, and the token stays out of the error', function () {
    $menu = telegramMenu();
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    telegramSale($shift, $cashier, 'john', [[$menu['coke'], 1]]);
    app(ShiftService::class)->CloseShift($shift, $cashier, 1000);

    Http::fake(['api.telegram.org/*' => Http::sequence()
        ->push(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)
        ->push(['ok' => true, 'result' => []])]);

    $this->artisan('telegram:shift-reports')
        ->expectsOutputToContain('chat not found')
        ->assertFailed();

    expect($shift->fresh()->sales_report_sent_at)->toBeNull()
        ->and(Ticket::query()->whereNotNull('sales_report_sent_at')->count())->toBe(0);

    $this->artisan('telegram:shift-reports')->assertSuccessful();

    expect($shift->fresh()->sales_report_sent_at)->not->toBeNull()
        ->and(Ticket::query()->whereNull('sales_report_sent_at')->count())->toBe(0);
});

test('a connection failure never prints the bot token', function () {
    $cashier = posUser();
    app(ShiftService::class)->CloseShift(posOpenShift($cashier), $cashier, 1000);
    Http::fake(fn (Request $request) => throw new ConnectionException("cURL error 7: Failed to connect for {$request->url()}"));

    $this->artisan('telegram:shift-reports')
        ->expectsOutputToContain('bot***/sendMessage')
        ->doesntExpectOutputToContain('SECRET')
        ->assertFailed();
});

test('shifts that closed before the lookback window are never sent automatically', function () {
    telegramAccepts();
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    app(ShiftService::class)->CloseShift($shift, $cashier, 1000);
    $shift->update(['closed_at' => now()->subDays(3)]);

    $this->artisan('telegram:shift-reports')->assertSuccessful();

    Http::assertNothingSent();
});

test('--shift resends a closed shift in full without changing what counts as sent', function () {
    telegramAccepts();
    $menu = telegramMenu();
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    telegramSale($shift, $cashier, 'john', [[$menu['adobo'], 2]]);
    app(ShiftService::class)->CloseShift($shift, $cashier, 1000);
    $this->artisan('telegram:shift-reports')->assertSuccessful();

    $this->artisan('telegram:shift-reports', ['--shift' => $shift->id])->assertSuccessful();

    expect(telegramTexts())->toHaveCount(2)
        ->and(telegramTexts()[1])->toContain("Shift #{$shift->id} closed (resent)")->toContain('Adobong Itik — 2');

    $open = posOpenShift($cashier);
    $this->artisan('telegram:shift-reports', ['--shift' => $open->id])
        ->expectsOutputToContain('still open')
        ->assertFailed();
});

test('a long report is split between lines under the Telegram limit', function () {
    telegramAccepts();
    $category = Category::create(['name' => 'Big Menu', 'status' => 'active', 'is_visible_to_pos' => true]);
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $lines = collect(range(1, 120))->map(fn (int $n): array => [
        Item::create(['category_id' => $category->id, 'name' => sprintf('A very long menu item name number %03d', $n), 'base_price' => 10, 'cost_price' => 1, 'inventory_type' => 'none', 'status' => 'available']),
        1,
    ])->all();
    telegramSale($shift, $cashier, 'john', $lines);
    app(ShiftService::class)->CloseShift($shift, $cashier, 1000);

    $this->artisan('telegram:shift-reports')->assertSuccessful();

    $texts = telegramTexts();
    expect(count($texts))->toBeGreaterThan(1);

    foreach ($texts as $text) {
        expect(mb_strlen($text))->toBeLessThanOrEqual(4000);
    }

    expect(substr_count(implode("\n", $texts), 'A very long menu item name number'))->toBe(120);
});

test('--test sends a hello and --find-chat lists the groups the bot has seen', function () {
    Http::fake([
        'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 1, 'message' => ['chat' => ['id' => -100200300, 'title' => 'Itikan Owners', 'type' => 'supergroup'], 'text' => 'hi']],
        ]]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
    ]);

    $this->artisan('telegram:shift-reports', ['--test' => true])->assertSuccessful();
    expect(telegramTexts()[0])->toContain('EPOS is connected');

    config(['services.telegram.chat_id' => null]);
    $this->artisan('telegram:shift-reports', ['--find-chat' => true])
        ->expectsTable(['TELEGRAM_CHAT_ID', 'Chat', 'Type'], [[-100200300, 'Itikan Owners', 'supergroup']])
        ->assertSuccessful();
});

test('the demo seeders build a closed shift and then a late offline sale that goes out as a follow-up', function () {
    telegramAccepts();

    $this->seed(TelegramDemoSeeder::class);
    $shift = Shift::query()->sole();

    expect($shift->status)->toBe('closed')
        ->and($shift->tickets()->where('status', 'paid')->count())->toBe(22);

    $this->artisan('telegram:shift-reports')->assertSuccessful();
    expect(telegramTexts()[0])->toContain('Sisig Itik · Lagi')->toContain('Birthday Cake Slicing')->toContain('<b>Meats</b>');

    $this->seed(TelegramLateSaleDemoSeeder::class);
    $this->artisan('telegram:shift-reports')->assertSuccessful();

    expect(telegramTexts())->toHaveCount(2)
        ->and(telegramTexts()[1])->toContain('late sales')->toContain('(2 tickets)')->toContain('Adobong Itik · Lagi — 1');
});
