<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->decimal('starting_cash', 10, 2);
            $table->decimal('closing_cash', 12, 2)->nullable();
            // Snapshots written only at close; while open, totals are computed live from charges/refunds.
            $table->decimal('total_revenue', 12, 2)->nullable();
            $table->decimal('total_cash', 12, 2)->nullable();
            $table->decimal('total_gcash', 12, 2)->nullable();
            $table->decimal('total_additions', 12, 2)->nullable();
            $table->decimal('total_expenses', 12, 2)->nullable();
            $table->decimal('total_refunds', 12, 2)->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('discrepancy', 12, 2)->nullable();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // Generated column: 1 while open, NULL once closed. A UNIQUE index ignores
            // NULLs, so this enforces "at most one open shift" without blocking closed history.
            // CASE (not MySQL's IF()) so the same migration also runs on the SQLite test DB.
            $table->unsignedTinyInteger('is_open')
                ->storedAs("CASE WHEN status = 'open' THEN 1 ELSE NULL END")
                ->nullable();
            $table->unique('is_open', 'idx_shifts_one_active_shift');

            $table->index('status', 'idx_shifts_status');
            $table->index('opened_at', 'idx_shifts_opened_at');
        });

        Schema::create('shift_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->enum('type', ['expense', 'addition']);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index('shift_id', 'idx_shift_transactions_shift_id');
            $table->index('type', 'idx_shift_transactions_type');
            $table->index('created_at', 'idx_shift_transactions_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_transactions');
        Schema::dropIfExists('shifts');
    }
};
