<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offline-first POS: phones keep selling when the NUC and Wi-Fi are down, then replay what
     * they did through POST /api/v1/sync. Phones name what they create (client_uuid) so a resent
     * action never creates anything twice; every action received is logged once (sync_actions);
     * anything the server had to accept despite disagreeing becomes a sync issue for a manager.
     */
    public function up(): void
    {
        // One row per POS login. `code` is short and unique (P1, P2, ...) so offline receipt and
        // order numbers from different phones can never clash. Kept after the token is revoked,
        // so the code is never handed out again.
        Schema::create('pos_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->unique();
            $table->string('name');
            $table->string('code', 20)->unique();
            // What the phone last reported: actions still waiting in its outbox.
            $table->unsignedInteger('pending_actions')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique()->after('id');
            $table->boolean('opened_offline')->default(false)->after('opened_at');
        });

        // A phone that opened a shift offline while another shift was (or became) the open one
        // has its shift joined into that one; its client uuid then points here.
        Schema::create('shift_aliases', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('pos_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique()->after('id');
            $table->foreignId('pos_device_id')->nullable()->after('terminal_id')->constrained('pos_devices')->nullOnDelete();
            // Taken while the phone had no server: the number printed on its slips (e.g. P3-007)
            // and when the server finally heard about it.
            $table->boolean('created_offline')->default(false)->after('status');
            $table->string('offline_label', 50)->nullable()->after('created_offline');
            $table->timestamp('synced_at')->nullable()->after('closed_at');
            $table->index('created_offline', 'idx_tickets_created_offline');
        });

        Schema::table('ticket_items', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique()->after('id');
            // Added offline: the kitchen got a paper slip, so the line arrives already bumped.
            $table->boolean('added_offline')->default(false)->after('is_stockless');
            // Removed offline: no passcode could be checked, so a reason is kept for review.
            $table->boolean('voided_offline')->default(false)->after('voided_requested_by');
            $table->string('void_reason')->nullable()->after('voided_offline');
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique()->after('id');
        });

        Schema::table('shift_transactions', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique()->after('id');
            // Recorded offline: when the server heard about it (created_at is when it happened).
            $table->timestamp('synced_at')->nullable()->after('deleted_at');
        });

        // An offline receipt keeps the number its phone printed (REC-2026-10-10-P3-001), so it
        // has no place in the server's daily sequence.
        Schema::table('receipts', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->nullable()->change();
            $table->string('device_code', 20)->nullable()->after('sequence');
        });

        // Every action a phone sent, once. A resent action id returns the stored result.
        Schema::create('sync_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('pos_device_id')->constrained('pos_devices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 50);
            $table->boolean('offline')->default(false);
            $table->timestamp('happened_at')->nullable();
            $table->enum('status', ['applied', 'applied_with_issue', 'rejected']);
            $table->json('result')->nullable();
            $table->string('message')->nullable();
            $table->timestamps();

            $table->index(['pos_device_id', 'created_at'], 'idx_sync_actions_device_created');
        });

        // What the server accepted but a manager should look at: stock that went negative, a
        // price that changed during the outage, an offline void, and so on.
        Schema::create('sync_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_action_id')->nullable()->constrained('sync_actions')->nullOnDelete();
            $table->foreignId('pos_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50);
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->string('message');
            $table->json('details')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['reviewed_at', 'created_at'], 'idx_sync_issues_reviewed_created');
            $table->index('type', 'idx_sync_issues_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_issues');
        Schema::dropIfExists('sync_actions');

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('device_code');
            $table->unsignedInteger('sequence')->nullable(false)->change();
        });

        Schema::table('shift_transactions', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn(['client_uuid', 'synced_at']);
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });

        Schema::table('ticket_items', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn(['client_uuid', 'added_offline', 'voided_offline', 'void_reason']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_tickets_created_offline');
            $table->dropUnique(['client_uuid']);
            $table->dropConstrainedForeignId('pos_device_id');
            $table->dropColumn(['client_uuid', 'created_offline', 'offline_label', 'synced_at']);
        });

        Schema::dropIfExists('shift_aliases');

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn(['client_uuid', 'opened_offline']);
        });

        Schema::dropIfExists('pos_devices');
    }
};
