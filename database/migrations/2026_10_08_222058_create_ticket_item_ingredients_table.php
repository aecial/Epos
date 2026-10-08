<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a paid recipe line actually took from stock, recorded when it was deducted. The
        // recipe and ingredient cost can change later; usage reports and refunds read this.
        Schema::create('ticket_item_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_item_id')->constrained('ticket_items')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            // Total for the whole line (per serving x line quantity), in the ingredient's unit.
            $table->decimal('quantity_used', 12, 3);
            $table->string('unit', 20);
            $table->decimal('cost_per_unit', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['ticket_item_id', 'ingredient_id'], 'idx_ticket_item_ingredients_unique');
            $table->index('ingredient_id', 'idx_ticket_item_ingredients_ingredient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_item_ingredients');
    }
};
