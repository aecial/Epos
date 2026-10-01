<?php

namespace App\Console\Commands;

use App\Models\TicketItem;
use Illuminate\Console\Command;

class ClearKdsCompletionState extends Command
{
    protected $signature = 'kds:clear-completed';

    protected $description = 'Clear ticket_items.completed_at - KDS completion is operational state, not audit history';

    public function handle(): int
    {
        $cleared = TicketItem::query()->whereNotNull('completed_at')->update(['completed_at' => null]);

        $this->info("Cleared completed_at on {$cleared} ticket item(s).");

        return self::SUCCESS;
    }
}
