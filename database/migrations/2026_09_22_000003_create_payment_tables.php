<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->enum('payment_method', ['cash', 'gcash']);
            $table->decimal('amount', 12, 2);
            $table->decimal('tendered_amount', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->enum('status', ['pending', 'paid', 'voided'])->default('pending');
            $table->string('payment_reference')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('ticket_id', 'idx_charges_ticket_id');
            $table->index('payment_method', 'idx_charges_payment_method');
            $table->index('status', 'idx_charges_status');
            $table->index(['ticket_id', 'status'], 'idx_charges_ticket_status');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            // Denormalized from ticket->shift_id so cash refunds can be netted directly
            // against that shift's cash reconciliation without joining through tickets.
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('charge_id')->nullable()->constrained('charges')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            // Passcode owner (admin/manager) who verified the approval or rejection.
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('ticket_id', 'idx_refunds_ticket_id');
            $table->index('shift_id', 'idx_refunds_shift_id');
            $table->index('status', 'idx_refunds_status');
            $table->index(['status', 'requested_at'], 'idx_refunds_status_requested');
        });

        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained('refunds')->cascadeOnDelete();
            $table->foreignId('ticket_item_id')->constrained('ticket_items')->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['refund_id', 'ticket_item_id'], 'idx_refund_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('charges');
    }
};
