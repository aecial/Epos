<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per paid charge. Immutable once written: `payload` is the exact receipt
        // as it was issued, so a reprint next month is identical to the original.
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_id')->unique()->constrained('charges')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->string('terminal_id', 50);
            // receipt_number = REC-{receipt_date}-{sequence}; the pair is unique so the
            // number can never be issued twice even if two payments race.
            $table->date('receipt_date');
            $table->unsignedInteger('sequence');
            $table->string('receipt_number', 100)->unique();
            // Denormalized snapshot columns so history can be listed/filtered/searched
            // without parsing the JSON payload.
            $table->string('order_number', 50);
            $table->string('customer_name');
            $table->enum('payment_method', ['cash', 'gcash']);
            $table->decimal('amount', 12, 2);
            $table->json('payload');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamps();

            $table->unique(['receipt_date', 'sequence'], 'idx_receipts_date_sequence');
            $table->index('shift_id', 'idx_receipts_shift_id');
            $table->index('terminal_id', 'idx_receipts_terminal_id');
            $table->index('payment_method', 'idx_receipts_payment_method');
            $table->index('issued_at', 'idx_receipts_issued_at');
            $table->index('order_number', 'idx_receipts_order_number');
        });

        // Append-only log of every time a receipt was sent to a printer. The first row is
        // the original print (is_reprint = false); later rows are duplicates.
        Schema::create('receipt_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('receipts')->cascadeOnDelete();
            $table->foreignId('printed_by')->constrained('users')->restrictOnDelete();
            $table->boolean('is_reprint')->default(false);
            $table->timestamp('printed_at')->useCurrent();
            $table->timestamps();

            $table->index(['receipt_id', 'is_reprint'], 'idx_receipt_prints_receipt_reprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_prints');
        Schema::dropIfExists('receipts');
    }
};
