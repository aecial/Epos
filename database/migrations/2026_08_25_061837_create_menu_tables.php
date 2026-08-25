<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('is_visible_to_pos')->default(true);
            $table->timestamps();
            $table->index('status', 'idx_categories_status');
            $table->index('is_visible_to_pos', 'idx_categories_visible');
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('base_price', 10, 2);
            $table->decimal('cost_price', 10, 2)->default(0);
            $table->integer('quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->string('image_url')->nullable();
            $table->enum('status', ['available', 'unavailable', 'hidden'])->default('available');
            $table->timestamps();
            $table->index('status', 'idx_items_status');
            $table->index('name', 'idx_items_name');
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('name');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->unique(['item_id', 'name'], 'idx_item_modifier_name');
            $table->index('status', 'idx_modifiers_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('items');
        Schema::dropIfExists('categories');
    }
};
