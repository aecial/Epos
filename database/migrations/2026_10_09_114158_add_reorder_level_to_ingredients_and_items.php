<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional "running low" thresholds. A raw material or direct-stock item is low when its
     * available stock (quantity - reserved_quantity) is at or below its reorder level; no level
     * means no warning.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // In the ingredient's own unit, so decimal like its quantity.
            $table->decimal('reorder_level', 12, 3)->nullable()->after('reserved_quantity');
        });

        Schema::table('items', function (Blueprint $table) {
            // Only meaningful for inventory_type = direct (whole units, like its quantity).
            $table->unsignedInteger('reorder_level')->nullable()->after('reserved_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('reorder_level');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('reorder_level');
        });
    }
};
