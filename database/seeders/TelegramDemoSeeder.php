<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Shift;
use App\Models\User;
use App\Services\ItemModifierService;
use App\Services\ItemRecipeService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use App\Services\TicketService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Demo data for trying the Telegram shift report: an itik restaurant menu and one realistic,
 * closed shift (yesterday 10:00-21:30, Asia/Manila) rung up through the real services, so stock,
 * raw-material usage, receipts and the shift's closing totals are all genuine.
 *
 *   php artisan db:seed --class=TelegramDemoSeeder
 *   php artisan telegram:shift-reports            (or let the scheduler send it)
 *
 * Not part of DatabaseSeeder - never run it on the restaurant's real database.
 */
class TelegramDemoSeeder extends Seeder
{
    public function run(TicketService $tickets, PaymentService $payments, ShiftService $shifts): void
    {
        $this->call(DatabaseSeeder::class);

        if ($shifts->ActiveShift() !== null) {
            throw new RuntimeException('A shift is open. Close it first - the demo shift needs to be the only open one while it runs.');
        }

        $menu = $this->menu();
        $cashier = User::query()->where('username', 'dangbi')->firstOrFail();
        $manager = User::query()->where('username', 'kring')->firstOrFail();
        mt_srand(20261014);

        $day = Carbon::yesterday('Asia/Manila');
        Carbon::setTestNow($day->copy()->setTime(10, 0));

        try {
            $shift = $shifts->OpenShift($cashier, 2000);
            $customers = ['john', 'mary', 'Table 3', 'jun', 'Kuya Ben', 'ana', 'Table 7', 'rey', 'liza', 'Table 1', 'mark', 'joy', 'Tita Nena', 'carlo', 'Table 5', 'bea', 'paolo', 'Table 2', 'gina', 'Sir Dan', 'mika', 'Table 4'];

            foreach ($customers as $index => $customer) {
                // Spread across the day, busier at lunch and dinner.
                $minutes = [30, 75, 110, 125, 140, 160, 185, 240, 300, 350, 420, 445, 470, 495, 510, 530, 555, 575, 600, 615, 630, 645][$index];
                Carbon::setTestNow($day->copy()->setTime(10, 0)->addMinutes($minutes));

                $this->sale($tickets, $payments, $shift, $cashier, $customer, $index, $menu);
            }

            Carbon::setTestNow($day->copy()->setTime(21, 30));
            $shifts->CloseShift($shift->fresh(), $manager, (float) $shifts->ComputeTotals($shift->fresh())['expected_cash']);
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info("Demo shift #{$shift->id} closed with ".$shift->tickets()->where('status', 'paid')->count().' paid tickets.');
    }

    /**
     * One ticket: a few random dishes and drinks, sometimes rice, a Lagi Sisig, a special item,
     * a discount or a GCash payment.
     *
     * @param  array<string, Item|Modifier>  $menu
     */
    private function sale(TicketService $tickets, PaymentService $payments, Shift $shift, User $cashier, string $customer, int $index, array $menu): void
    {
        $ticket = $tickets->CreateTicket($shift, $cashier, 'POS-01', $customer, $index % 4 === 3 ? 'takeout' : 'dine_in');
        $add = fn (Item $item, int $quantity, array $modifierIds = [], ?float $price = null, ?string $name = null) => $tickets->AddItem($ticket->fresh(), $item->fresh(), $quantity, $modifierIds, null, $price, $name);

        $dishes = ['fried', 'fried', 'adobo', 'adobo', 'sisig', 'kawali', 'bulaklak', 'tinola'];
        $drinks = ['coke', 'sanmig', 'redhorse', 'redhorse', 'water', 'iced_tea'];

        $picked = array_unique(array_map(fn (): string => $dishes[mt_rand(0, count($dishes) - 1)], range(1, mt_rand(1, 3))));

        foreach ($picked as $dish) {
            $add($menu[$dish], mt_rand(1, 2));
        }

        if (mt_rand(1, 3) > 1) {
            $add($menu[mt_rand(0, 1) ? 'rice' : 'garlic_rice'], mt_rand(2, 5));
        }

        $add($menu[$drinks[mt_rand(0, count($drinks) - 1)]], mt_rand(1, 4));

        // Lagi: the regular's stockless way of ordering Sisig/Adobo.
        if (in_array($index, [2, 9, 15], true)) {
            $add($menu[$index === 9 ? 'adobo' : 'sisig'], 1, [$menu['lagi']->id]);
        }

        if ($index === 6) {
            $add($menu['corkage'], 1, [], 150);
        }

        if ($index === 13) {
            $add($menu['delivery'], 1, [], 60);
        }

        if ($index === 18) {
            $add($menu['custom'], 1, [], 120, 'Birthday Cake Slicing');
        }

        // One line taken back before paying with Kring's passcode (voided lines don't count as sold).
        if ($index === 4) {
            $tickets->VoidItem($add($menu['bulaklak'], 1), $cashier, '0428');
        }

        if ($index === 11) {
            $tickets->SetDiscount($ticket->fresh(), discountPercent: 20);
        }

        $total = (float) $ticket->fresh()->total;
        $charges = $index % 3 === 0
            ? [['payment_method' => 'gcash', 'amount' => $total, 'payment_reference' => 'GC-DEMO-'.($index + 1)]]
            : [['payment_method' => 'cash', 'amount' => $total, 'tendered_amount' => ceil($total / 100) * 100]];

        $payments->ChargeTicket($ticket->fresh(), $cashier, $charges);
    }

    /**
     * @return array<string, Item|Modifier>
     */
    private function menu(): array
    {
        $meats = IngredientGroup::query()->firstOrCreate(['name' => 'Meats'], ['status' => 'active']);
        $pantry = IngredientGroup::query()->firstOrCreate(['name' => 'Pantry'], ['status' => 'active']);

        $ingredient = fn (IngredientGroup $group, string $name, string $unit, float $stock, float $cost): Ingredient => Ingredient::query()->firstOrCreate(
            ['name' => $name],
            ['ingredient_group_id' => $group->id, 'unit' => $unit, 'quantity' => $stock, 'cost_per_unit' => $cost, 'status' => 'active'],
        );

        $itik = $ingredient($meats, 'Itik', 'piece', 80, 180);
        $pork = $ingredient($meats, 'Pork Belly', 'kg', 25, 340);
        $chicken = $ingredient($meats, 'Chicken', 'kg', 15, 210);
        $oil = $ingredient($pantry, 'Cooking Oil', 'liter', 30, 95);
        $garlic = $ingredient($pantry, 'Garlic', 'gram', 5000, 0.2);
        $vinegar = $ingredient($pantry, 'Vinegar', 'ml', 10000, 0.05);
        $egg = $ingredient($pantry, 'Egg', 'piece', 120, 9);
        $rice = $ingredient($pantry, 'Rice', 'kg', 50, 52);

        $category = fn (string $name, string $type = 'menu'): Category => Category::query()->firstOrCreate(
            ['name' => $name],
            ['type' => $type, 'status' => 'active', 'is_visible_to_pos' => true],
        );

        $itikDishes = $category('Itik Specialties');
        $pulutan = $category('Pulutan');
        $riceCategory = $category('Rice');
        $drinks = $category('Drinks');
        $special = $category('Special', 'special');

        $recipe = function (Category $category, string $name, float $price, array $uses): Item {
            $item = Item::query()->firstOrCreate(
                ['name' => $name],
                ['category_id' => $category->id, 'base_price' => $price, 'cost_price' => 0, 'inventory_type' => 'recipe', 'status' => 'available'],
            );

            app(ItemRecipeService::class)->ReplaceItemRecipe($item, array_map(fn (array $use): array => [
                'ingredient_id' => $use[0]->id,
                'quantity_required' => $use[1],
                'unit' => $use[0]->unit,
            ], $uses));

            return $item->fresh();
        };

        $direct = fn (Category $category, string $name, float $price, float $cost, int $stock): Item => Item::query()->firstOrCreate(
            ['name' => $name],
            ['category_id' => $category->id, 'base_price' => $price, 'cost_price' => $cost, 'quantity' => $stock, 'inventory_type' => 'direct', 'status' => 'available'],
        );

        $specialItem = fn (string $name, string $entryMode): Item => Item::query()->firstOrCreate(
            ['name' => $name],
            ['category_id' => $special->id, 'base_price' => 0, 'inventory_type' => 'none', 'entry_mode' => $entryMode, 'status' => 'available'],
        );

        $menu = [
            'fried' => $recipe($itikDishes, 'Fried Itik', 350, [[$itik, 1], [$oil, 0.25], [$garlic, 20]]),
            'adobo' => $recipe($itikDishes, 'Adobong Itik', 320, [[$itik, 1], [$vinegar, 120], [$garlic, 30]]),
            'sisig' => $recipe($itikDishes, 'Sisig Itik', 249, [[$itik, 0.5], [$egg, 1], [$garlic, 15]]),
            'kawali' => $recipe($pulutan, 'Lechon Kawali', 280, [[$pork, 0.3], [$oil, 0.2]]),
            'bulaklak' => $recipe($pulutan, 'Chicharon Bulaklak', 220, [[$pork, 0.25], [$oil, 0.15]]),
            'tinola' => $recipe($pulutan, 'Chicken Tinola', 240, [[$chicken, 0.4], [$garlic, 10]]),
            'rice' => $recipe($riceCategory, 'Plain Rice', 25, [[$rice, 0.12]]),
            'garlic_rice' => $recipe($riceCategory, 'Garlic Rice', 35, [[$rice, 0.12], [$garlic, 10], [$oil, 0.01]]),
            'coke' => $direct($drinks, 'Coke 1.5L', 95, 70, 60),
            'sanmig' => $direct($drinks, 'San Miguel Pale Pilsen', 70, 48, 120),
            'redhorse' => $direct($drinks, 'Red Horse', 85, 58, 120),
            'water' => $direct($drinks, 'Bottled Water', 25, 12, 100),
            'iced_tea' => $direct($drinks, 'House Iced Tea', 45, 10, 100),
            'corkage' => $specialItem('Corkage', 'price'),
            'delivery' => $specialItem('Delivery Fee', 'price'),
            'custom' => $specialItem('Custom Item', 'name_price'),
        ];

        $variants = ModifierGroup::query()->firstOrCreate(['name' => 'Variant']);
        $menu['lagi'] = Modifier::query()->firstOrCreate(
            ['name' => 'Lagi'],
            ['modifier_group_id' => $variants->id, 'status' => 'active', 'is_stockless_variant' => true],
        );

        foreach (['sisig', 'adobo'] as $dish) {
            app(ItemModifierService::class)->ReplaceItemModifiers($menu[$dish], [['modifier_id' => $menu['lagi']->id]]);
        }

        return $menu;
    }
}
