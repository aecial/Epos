<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // 'special' categories hold Special items (fees and custom items) whose price,
            // and sometimes name, the cashier types when adding them to a ticket.
            $table->enum('type', ['menu', 'special'])->default('menu')->after('name');
        });

        Schema::table('items', function (Blueprint $table) {
            // What the cashier supplies when adding this item to a ticket:
            //   fixed      - nothing, the base_price is used (every normal menu item)
            //   price      - the amount (a Fee item; base_price is only the suggested default)
            //   name_price - the name and the amount (a Custom item)
            $table->enum('entry_mode', ['fixed', 'price', 'name_price'])->default('fixed')->after('inventory_type');
        });

        Schema::table('ticket_items', function (Blueprint $table) {
            // Snapshot taken when the line is added, like item_name/unit_price, so later menu
            // edits never rewrite history and the KDS/reports can filter without joins.
            $table->enum('line_type', ['item', 'fee', 'custom'])->default('item')->after('item_cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_items', function (Blueprint $table) {
            $table->dropColumn('line_type');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('entry_mode');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
