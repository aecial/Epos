<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('terminal_id', 50);
            $table->string('customer_name');
            // Resets per shift, e.g. "#001". Uniqueness is scoped to shift_id below.
            $table->string('order_number', 50);
            $table->enum('order_type', ['dine_in', 'takeout']);
            $table->enum('status', ['open', 'paid', 'merged', 'cancelled'])->default('open');
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('merged_into_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // Generated column: holds customer_name only while the ticket is open, else NULL.
            // Backs the "no duplicate open name per shift" rule (john -> john2 -> john3)
            // across all terminals, without blocking the same name being reused once paid.
            // CASE (not MySQL's IF()) so the same migration also runs on the SQLite test DB.
            $table->string('open_name')
                ->storedAs("CASE WHEN status = 'open' THEN customer_name ELSE NULL END")
                ->nullable();
            $table->unique(['shift_id', 'open_name'], 'idx_tickets_shift_open_name');

            $table->unique(['shift_id', 'order_number'], 'idx_tickets_shift_order_number');
            $table->index('terminal_id', 'idx_tickets_terminal_id');
            $table->index('status', 'idx_tickets_status');
            $table->index('created_at', 'idx_tickets_created_at');
            $table->index(['terminal_id', 'status'], 'idx_tickets_terminal_status');
            $table->index(['shift_id', 'status', 'created_at'], 'idx_tickets_shift_status_created');
        });

        Schema::create('ticket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            // Snapshots taken when the line is added, so renaming/repricing an item later
            // does not silently change historical receipts or margin reports.
            $table->string('item_name');
            $table->decimal('item_cost_price', 10, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            // KDS-only notes: visible in kitchen and back office, never printed on receipts.
            $table->text('notes')->nullable();
            $table->decimal('line_total', 12, 2);
            $table->timestamp('voided_at')->nullable();
            // Passcode owner who authorized the void.
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            // Cashier operating the terminal who initiated the void request.
            $table->foreignId('voided_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('ticket_id', 'idx_ticket_items_ticket_id');
            $table->index('item_id', 'idx_ticket_items_item_id');
        });

        Schema::create('ticket_item_modifier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_item_id')->constrained('ticket_items')->cascadeOnDelete();
            $table->foreignId('modifier_id')->constrained('modifiers')->restrictOnDelete();
            // Snapshots, same rationale as ticket_items above.
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['ticket_item_id', 'modifier_id'], 'idx_ticket_item_modifier_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_item_modifier');
        Schema::dropIfExists('ticket_items');
        Schema::dropIfExists('tickets');
    }
};
