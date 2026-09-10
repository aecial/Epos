<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_group_id')
                ->constrained('ingredient_groups')
                ->restrictOnDelete();
            $table->string('name');
            $table->enum('unit', ['piece', 'kg', 'gram', 'liter', 'ml']);
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->decimal('cost_per_unit', 12, 2)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->unique(['ingredient_group_id', 'name']);
            $table->index('status', 'idx_ingredients_status');
        });

        Schema::create('item_ingredient', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('quantity_required', 12, 3);
            $table->enum('unit', ['piece', 'kg', 'gram', 'liter', 'ml']);
            $table->timestamps();
            $table->primary(['item_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_ingredient');
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('ingredient_groups');
    }
};
