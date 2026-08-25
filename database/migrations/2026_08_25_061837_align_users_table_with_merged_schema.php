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
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['email', 'email_verified_at']);
            $table->string('username')->unique()->after('name');
            $table->string('passcode')->after('password')->nullable();
            $table->enum('role', ['admin', 'manager', 'cashier'])->default('cashier')->after('passcode');
            $table->enum('status', ['active', 'inactive'])->default('active')->after('role');
            $table->index('role', 'idx_role');
            $table->index('status', 'idx_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_role');
            $table->dropIndex('idx_status');
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'passcode', 'role', 'status']);
            $table->string('email')->unique()->after('name');
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }
};
