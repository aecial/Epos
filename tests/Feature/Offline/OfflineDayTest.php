<?php

use App\Models\Charge;
use App\Models\Ingredient;
use App\Models\IngredientGroup;
use App\Models\Item;
use App\Models\Receipt;
use App\Models\Shift;
use App\Models\ShiftTransaction;
use App\Models\SyncAction;
use App\Models\SyncIssue;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Services\KdsService;
use App\Services\SalesReportService;
use App\Services\ShiftService;
use Illuminate\Support\Carbon;

/*
| A whole brownout, replayed. The NUC was down from 10:00 to 14:00; the manager's phone kept
| selling, then sent its notebook when the power came back at 18:00. Everything lands at the
| time it happened, nothing is counted twice when the phone resends, and the kitchen display
| doesn't show what the kitchen already cooked from paper slips.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 18:00:00', 'Asia/Manila'));
});

/**
 * The outage's notebook: a shift opened offline with ₱2,000, John's order (2 Burgers + 1 Sisig
 * Itik, ₱20 off, paid ₱560 cash), Mary's order (1 Burger removed with a reason, 1 Fries paid by
 * GCash), and a ₱150 ice expense.
 *
 * @return array{actions: array<int, array<string, mixed>>, burger: Item, fries: Item, sisig: Item, itik: Ingredient}
 */
function offlineDay(): array
{
    $burger = posItem('Burger', 100, 10);
    $fries = posItem('Fries', 50, 10);
    $sisig = posItem('Sisig Itik', 380, 0);
    $sisig->update(['inventory_type' => 'recipe']);
    $itik = Ingredient::create(['ingredient_group_id' => IngredientGroup::create(['name' => 'Meat'])->id, 'name' => 'Itik', 'unit' => 'piece', 'quantity' => 5, 'cost_per_unit' => 180]);
    $sisig->ingredients()->attach($itik->id, ['quantity_required' => 1, 'unit' => 'piece']);

    $shift = syncUuid();
    $john = syncUuid();
    $mary = syncUuid();
    $maryBurger = syncUuid();

    return [
        'burger' => $burger,
        'fries' => $fries,
        'sisig' => $sisig,
        'itik' => $itik,
        'actions' => [
            syncAction('shift.open', ['shift_uuid' => $shift, 'starting_cash' => 2000], '2026-10-10 10:00'),
            syncAction('ticket.create', ['ticket_uuid' => $john, 'shift_uuid' => $shift, 'terminal_id' => 'POS-01', 'customer_name' => 'John', 'order_type' => 'dine_in', 'offline_label' => 'P1-001'], '2026-10-10 10:05'),
            syncAction('ticket.add_item', ['ticket_uuid' => $john, 'line_uuid' => syncUuid(), 'item_id' => $burger->id, 'quantity' => 2, 'unit_price' => 100], '2026-10-10 10:06'),
            syncAction('ticket.add_item', ['ticket_uuid' => $john, 'line_uuid' => syncUuid(), 'item_id' => $sisig->id, 'quantity' => 1, 'unit_price' => 380], '2026-10-10 10:07'),
            syncAction('ticket.discount', ['ticket_uuid' => $john, 'discount_amount' => 20], '2026-10-10 10:20'),
            syncAction('ticket.charge', ['ticket_uuid' => $john, 'charges' => [
                ['charge_uuid' => syncUuid(), 'payment_method' => 'cash', 'amount' => 560, 'tendered_amount' => 600, 'receipt_number' => 'REC-2026-10-10-P1-001'],
            ]], '2026-10-10 10:30'),
            syncAction('ticket.create', ['ticket_uuid' => $mary, 'shift_uuid' => $shift, 'terminal_id' => 'POS-01', 'customer_name' => 'Mary', 'order_type' => 'takeout', 'offline_label' => 'P1-002'], '2026-10-10 11:00'),
            syncAction('ticket.add_item', ['ticket_uuid' => $mary, 'line_uuid' => $maryBurger, 'item_id' => $burger->id, 'quantity' => 1, 'unit_price' => 100], '2026-10-10 11:01'),
            syncAction('ticket.void_item', ['line_uuid' => $maryBurger, 'reason' => 'Changed her mind'], '2026-10-10 11:03'),
            syncAction('ticket.add_item', ['ticket_uuid' => $mary, 'line_uuid' => syncUuid(), 'item_id' => $fries->id, 'quantity' => 1, 'unit_price' => 50], '2026-10-10 11:04'),
            syncAction('ticket.charge', ['ticket_uuid' => $mary, 'charges' => [
                ['charge_uuid' => syncUuid(), 'payment_method' => 'gcash', 'amount' => 50, 'payment_reference' => 'GC-778', 'receipt_number' => 'REC-2026-10-10-P1-002'],
            ]], '2026-10-10 11:10'),
            syncAction('shift_transaction.add', ['transaction_uuid' => syncUuid(), 'shift_uuid' => $shift, 'type' => 'expense', 'amount' => 150, 'reason' => 'Ice'], '2026-10-10 12:00'),
        ],
    ];
}

test('an outage day lands on the server at the times it happened', function () {
    ['user' => $manager, 'token' => $token, 'device' => $device] = syncPhone('manager');
    ['actions' => $actions, 'burger' => $burger, 'fries' => $fries, 'itik' => $itik] = offlineDay();

    $results = syncSend($token, $actions)->assertOk()->json('data.results');

    expect(collect($results)->pluck('status')->all())->toBe([
        'applied', 'applied', 'applied', 'applied', 'applied', 'applied',
        'applied', 'applied', 'applied_with_issue', 'applied', 'applied', 'applied',
    ]);

    // The shift the phone opened offline, opened at 10:00.
    $shift = Shift::sole();
    expect($shift->opened_offline)->toBeTrue()
        ->and($shift->opened_at->setTimezone('Asia/Manila')->format('H:i'))->toBe('10:00')
        ->and((float) $shift->starting_cash)->toBe(2000.0);

    // Real order numbers beside the labels the phone printed, at their real times.
    $john = Ticket::firstWhere('customer_name', 'John');
    $mary = Ticket::firstWhere('customer_name', 'Mary');
    expect($results[1]['result'])->toMatchArray(['ticket_id' => $john->id, 'order_number' => '#001', 'customer_name' => 'John'])
        ->and($john->created_offline)->toBeTrue()
        ->and($john->offline_label)->toBe('P1-001')
        ->and($john->pos_device_id)->toBe($device->id)
        ->and($john->created_at->setTimezone('Asia/Manila')->format('H:i'))->toBe('10:05')
        ->and($john->closed_at->setTimezone('Asia/Manila')->format('H:i'))->toBe('10:30')
        ->and($john->status)->toBe('paid')
        ->and((float) $john->total)->toBe(560.0)
        ->and($mary->order_number)->toBe('#002')
        ->and((float) $mary->total)->toBe(50.0);

    // The receipts keep the numbers printed on paper, outside the daily sequence.
    $receipt = Receipt::firstWhere('ticket_id', $john->id);
    expect($receipt->receipt_number)->toBe('REC-2026-10-10-P1-001')
        ->and($receipt->sequence)->toBeNull()
        ->and($receipt->device_code)->toBe($device->code)
        ->and($receipt->issued_at->setTimezone('Asia/Manila')->format('H:i'))->toBe('10:30')
        ->and($receipt->payload['order']['offline_label'])->toBe('P1-001')
        ->and(Charge::firstWhere('payment_method', 'gcash')->paid_at->setTimezone('Asia/Manila')->format('H:i'))->toBe('11:10');

    // Stock: 2 + 0 Burgers (Mary's was removed), 1 Fries, 1 Itik.
    expect($burger->fresh()->quantity)->toBe(8)
        ->and($burger->fresh()->reserved_quantity)->toBe(0)
        ->and($fries->fresh()->quantity)->toBe(9)
        ->and((float) $itik->fresh()->quantity)->toBe(4.0);

    // Mary's Burger: removed offline without a passcode, kept with its reason for a manager.
    $voided = TicketItem::query()->whereNotNull('voided_at')->sole();
    expect($voided->voided_offline)->toBeTrue()
        ->and($voided->voided_by)->toBeNull()
        ->and($voided->voided_requested_by)->toBe($manager->id)
        ->and($voided->void_reason)->toBe('Changed her mind');
    expect(SyncIssue::sole())
        ->type->toBe('offline_void')
        ->ticket_id->toBe($mary->id);

    // The drawer: 2000 + 560 cash − 150 ice.
    expect(app(ShiftService::class)->ComputeTotals($shift)['expected_cash'])->toBe(2410.0)
        ->and(ShiftTransaction::sole()->created_at->setTimezone('Asia/Manila')->format('H:i'))->toBe('12:00');

    // The device is up to date.
    expect($device->fresh()->last_synced_at)->not->toBeNull()
        ->and($device->fresh()->pending_actions)->toBe(0);
});

test('reports put the outage sales at the hours they happened', function () {
    ['token' => $token] = syncPhone('manager');
    syncSend($token, offlineDay()['actions'])->assertOk();

    $report = app(SalesReportService::class)->ItemsSold(
        Carbon::parse('2026-10-10 00:00', 'Asia/Manila'),
        Carbon::parse('2026-10-10 23:59:59', 'Asia/Manila'),
    );
    $rows = collect($report['rows'])->keyBy('name');

    expect($rows->keys()->sort()->values()->all())->toBe(['Burger', 'Fries', 'Sisig Itik'])
        ->and($rows['Burger']['quantity'])->toBe(2)
        ->and($rows['Sisig Itik']['cost'])->toEqual(180);

    // Nothing landed on the next day or at the sync time.
    $paidHours = Ticket::query()->where('status', 'paid')->get()->map(fn (Ticket $ticket): string => $ticket->closed_at->setTimezone('Asia/Manila')->format('H'))->all();
    expect($paidHours)->toBe(['10', '11']);
});

test('the kitchen display never shows what was cooked from paper slips', function () {
    ['token' => $token] = syncPhone('manager');
    $burger = posItem('Burger', 100);
    $ticket = syncUuid();

    syncSend($token, [
        syncAction('shift.open', ['shift_uuid' => syncUuid(), 'starting_cash' => 1000], '2026-10-10 10:00'),
        syncAction('ticket.create', ['ticket_uuid' => $ticket, 'terminal_id' => 'POS-01', 'customer_name' => 'John', 'order_type' => 'dine_in'], '2026-10-10 10:05'),
        syncAction('ticket.add_item', ['ticket_uuid' => $ticket, 'line_uuid' => syncUuid(), 'item_id' => $burger->id, 'quantity' => 1, 'unit_price' => 100], '2026-10-10 10:06'),
    ])->assertOk();

    $line = TicketItem::sole();
    expect($line->added_offline)->toBeTrue()
        ->and($line->completed_at)->not->toBeNull()
        ->and(app(KdsService::class)->GetOpenOrders())->toBe([]);
});

test('sending the same notebook again changes nothing and returns the same answers', function () {
    ['token' => $token] = syncPhone('manager');
    ['actions' => $actions, 'burger' => $burger] = offlineDay();

    $first = syncSend($token, $actions)->assertOk()->json('data.results');
    $second = syncSend($token, $actions)->assertOk()->json('data.results');

    expect($second)->toBe($first)
        ->and(Ticket::count())->toBe(2)
        ->and(Charge::count())->toBe(2)
        ->and(Receipt::count())->toBe(2)
        ->and(ShiftTransaction::count())->toBe(1)
        ->and(SyncAction::count())->toBe(12)
        ->and(SyncIssue::count())->toBe(1)
        ->and($burger->fresh()->quantity)->toBe(8);
});

test('a batch cut off halfway picks up where it stopped', function () {
    ['token' => $token] = syncPhone('manager');
    ['actions' => $actions] = offlineDay();

    syncSend($token, array_slice($actions, 0, 4), pending: 8)->assertOk();
    syncSend($token, $actions)->assertOk();

    expect(Ticket::count())->toBe(2)
        ->and(TicketItem::count())->toBe(4)
        ->and(SyncAction::count())->toBe(12);
});
