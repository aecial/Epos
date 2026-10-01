<?php

/*
| kds:clear-completed - the nightly sweep. Unconditional: clears completed_at regardless of
| ticket status (open or otherwise), since this is operational state, not audit history.
*/

test('it clears completed_at on every ticket item, regardless of ticket status', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);

    $openTicket = posTicket($shift, $cashier, 'john');
    $openLine = posAddItem($openTicket, $item, 1);
    $openLine->update(['completed_at' => now()]);

    $paidTicket = posTicket($shift, $cashier, 'jane');
    $paidLine = posAddItem($paidTicket, $item, 1);
    $paidLine->update(['completed_at' => now()]);
    posPay($paidTicket, $cashier, [['payment_method' => 'cash', 'amount' => 100, 'tendered_amount' => 100]]);

    $untouchedLine = posAddItem($openTicket, $item, 1);

    $this->artisan('kds:clear-completed')->assertExitCode(0);

    expect($openLine->fresh()->completed_at)->toBeNull();
    expect($paidLine->fresh()->completed_at)->toBeNull();
    expect($untouchedLine->fresh()->completed_at)->toBeNull();
});

test('it is a no-op when nothing is completed', function () {
    $cashier = posUser();
    $shift = posOpenShift($cashier);
    $item = posItem('Burger', 100);
    $ticket = posTicket($shift, $cashier, 'john');
    posAddItem($ticket, $item, 1);

    $this->artisan('kds:clear-completed')->assertExitCode(0);
});
