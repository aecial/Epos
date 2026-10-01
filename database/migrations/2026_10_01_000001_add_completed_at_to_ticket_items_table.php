<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_items', function (Blueprint $table) {
            // KDS completion toggle only - not audit/historical data. Nothing in receipts,
            // payments or refunds reads it; kds:clear-completed sweeps it back to null nightly.
            $table->timestamp('completed_at')->nullable()->after('voided_requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_items', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
