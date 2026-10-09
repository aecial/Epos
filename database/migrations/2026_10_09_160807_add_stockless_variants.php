<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stockless variants: a modifier (e.g. "Lagi") that, when picked, makes its ticket line take
     * no stock at all, cost and price nothing extra, and stay off the customer receipt while the
     * kitchen still sees it.
     */
    public function up(): void
    {
        Schema::table('modifiers', function (Blueprint $table) {
            $table->boolean('is_stockless_variant')->default(false)->after('status');
        });

        Schema::table('ticket_item_modifier', function (Blueprint $table) {
            // Snapshot, like name/price: changing the modifier later never rewrites past sales.
            $table->boolean('is_stockless_variant')->default(false)->after('price');
        });

        Schema::table('ticket_items', function (Blueprint $table) {
            // Set once when the line is added; every reserve/release/deduct/restore skips it.
            $table->boolean('is_stockless')->default(false)->after('line_type');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_items', function (Blueprint $table) {
            $table->dropColumn('is_stockless');
        });

        Schema::table('ticket_item_modifier', function (Blueprint $table) {
            $table->dropColumn('is_stockless_variant');
        });

        Schema::table('modifiers', function (Blueprint $table) {
            $table->dropColumn('is_stockless_variant');
        });
    }
};
