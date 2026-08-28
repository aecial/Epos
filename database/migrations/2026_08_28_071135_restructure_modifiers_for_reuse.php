<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_required')->default(false);
            $table->timestamps();
        });

        Schema::table('modifiers', function (Blueprint $table) {
            $table->foreignId('modifier_group_id')
                ->nullable()
                ->after('id')
                ->constrained('modifier_groups')
                ->nullOnDelete();
        });

        Schema::create('item_modifier', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('modifier_id')->constrained('modifiers')->cascadeOnDelete();
            $table->decimal('price_modifier', 10, 2)->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->primary(['item_id', 'modifier_id']);
            $table->index(['item_id', 'status']);
        });

        DB::table('item_modifier')->insertUsing(
            ['item_id', 'modifier_id'],
            DB::table('modifiers')->select(['item_id', 'id'])
        );

        Schema::table('modifiers', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->dropUnique('idx_item_modifier_name');
            $table->dropColumn('item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('modifiers', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->after('id')->constrained('items')->nullOnDelete();
        });

        Schema::dropIfExists('item_modifier');

        Schema::table('modifiers', function (Blueprint $table) {
            $table->dropForeign(['modifier_group_id']);
            $table->dropColumn('modifier_group_id');
            $table->unique(['item_id', 'name'], 'idx_item_modifier_name');
        });

        Schema::dropIfExists('modifier_groups');
    }
};
