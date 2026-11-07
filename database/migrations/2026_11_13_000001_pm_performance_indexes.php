<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PM Scheduler: indexes for the lists, the calendar feed, the daily jobs and the equipment history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_schedules', function (Blueprint $table) {
            // "active schedules of a facility, soonest first" (list filters, calendar, reports)
            $table->index(['facility_id', 'is_active', 'next_due_date'], 'pm_schedules_facility_active_due_index');
            // "schedules of one equipment" (equipment history)
            $table->index(['equipment_id', 'is_active'], 'pm_schedules_equipment_active_index');
        });

        Schema::table('requests', function (Blueprint $table) {
            // overdue / reminder / escalation lookups and the equipment history
            $table->index(['pm_due_date', 'status'], 'requests_pm_due_status_index');
            $table->index('pm_completed_date', 'requests_pm_completed_index');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex('requests_pm_completed_index');
            $table->dropIndex('requests_pm_due_status_index');
        });

        Schema::table('pm_schedules', function (Blueprint $table) {
            $table->dropIndex('pm_schedules_equipment_active_index');
            $table->dropIndex('pm_schedules_facility_active_due_index');
        });
    }
};