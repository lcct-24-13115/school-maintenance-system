<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PM integration, Phase B: what is recorded when a PM ticket is completed, and the floating "anchor" of a schedule. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->date('pm_completed_date')->nullable();                                           // the day the work was really done
            $table->foreignId('pm_technician_id')->nullable()->constrained('users')->nullOnDelete();  // who did it
            $table->text('pm_notes')->nullable();
        });

        Schema::table('pm_schedules', function (Blueprint $table) {
            $table->date('anchor_date')->nullable();          // floating: the occurrences are counted from here instead of start_date
            $table->date('last_completed_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pm_schedules', function (Blueprint $table) {
            $table->dropColumn(['anchor_date', 'last_completed_date']);
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pm_technician_id');
            $table->dropColumn(['pm_completed_date', 'pm_notes']);
        });
    }
};