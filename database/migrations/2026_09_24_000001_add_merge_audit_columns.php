<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Set on the SOURCE ticket when it is merged away, mirroring cancelled_by/cancelled_at.
            $table->foreignId('merged_by')->nullable()->after('merged_into_ticket_id')->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_by');
        });

        Schema::table('ticket_items', function (Blueprint $table) {
            // Merging physically moves lines to the target ticket. This remembers the ticket a
            // line was originally created on ("john2's items"); NULL means it never moved.
            $table->foreignId('merged_from_ticket_id')->nullable()->after('ticket_id')->constrained('tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_from_ticket_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_by');
            $table->dropColumn('merged_at');
        });
    }
};
