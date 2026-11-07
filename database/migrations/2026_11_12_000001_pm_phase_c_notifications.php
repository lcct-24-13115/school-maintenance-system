<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** PM integration, Phase C: remember which alerts a PM ticket already had, so each one is sent only once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->timestamp('pm_notified_at')->nullable();    // "new PM ticket" alert sent
            $table->timestamp('pm_reminded_at')->nullable();    // "due in X days" reminder sent
            $table->timestamp('pm_overdue_at')->nullable();     // marked overdue (and the overdue alert sent)
            $table->timestamp('pm_escalated_at')->nullable();   // the supervisor was told
        });

        // PM tickets that already exist were announced the old way: do not send a "new ticket" alert for them now.
        DB::table('requests')->whereNotNull('pm_due_date')->update(['pm_notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn(['pm_notified_at', 'pm_reminded_at', 'pm_overdue_at', 'pm_escalated_at']);
        });
    }
};