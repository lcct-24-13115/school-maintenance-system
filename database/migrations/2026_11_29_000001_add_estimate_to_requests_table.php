<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estimated completion date that the Head of Maintenance tells the requester once the ticket is fully approved.
 *
 * The date itself is stored in the existing requests.due_at (the target finish date, which also drives the
 * overdue badge and the auto-escalation). These columns only record that the Head has ACTUALLY given the
 * requester an estimate (due_at always has a default SLA value, so it cannot tell us that), who gave it,
 * when, and the optional message that goes with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->timestamp('eta_set_at')->nullable()->after('due_at');
            $table->unsignedBigInteger('eta_set_by')->nullable()->after('eta_set_at');
            $table->string('eta_message', 500)->nullable()->after('eta_set_by');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn(['eta_set_at', 'eta_set_by', 'eta_message']);
        });
    }
};
