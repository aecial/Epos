<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the Telegram shift report has already sent: a shift's closing summary once, then each
     * paid ticket once (a ticket paid after its shift closed - an offline sale synced late - goes
     * out as a follow-up). Shifts and tickets already closed when this runs count as sent, so
     * switching the bot on never replays old shifts.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->timestamp('sales_report_sent_at')->nullable()->after('closed_at');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('sales_report_sent_at')->nullable()->after('closed_at');
        });

        $now = now();

        DB::table('shifts')->where('status', 'closed')->update(['sales_report_sent_at' => $now]);
        DB::table('tickets')
            ->where('status', 'paid')
            ->whereIn('shift_id', DB::table('shifts')->where('status', 'closed')->select('id'))
            ->update(['sales_report_sent_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('sales_report_sent_at');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('sales_report_sent_at');
        });
    }
};
