<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->enum('inventory_type', ['direct', 'recipe', 'none'])
                ->default('direct')
                ->after('reserved_quantity');
            $table->index('inventory_type', 'idx_items_inventory_type');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('idx_items_inventory_type');
            $table->dropColumn('inventory_type');
        });
    }
};
