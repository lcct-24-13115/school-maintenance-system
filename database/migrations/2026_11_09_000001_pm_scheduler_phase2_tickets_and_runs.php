<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PM Scheduler, Phase 2: link tickets to the schedule that made them, and keep a log of every generation run. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('pm_schedule_id')->nullable()->constrained('pm_schedules')->nullOnDelete();
            $table->date('pm_due_date')->nullable();

            // The real guard against duplicates: one ticket per schedule per due date.
            $table->unique(['pm_schedule_id', 'pm_due_date'], 'requests_pm_occurrence_unique');
        });

        Schema::table('pm_schedules', function (Blueprint $table) {
            $table->timestamp('last_generated_at')->nullable();
        });

        Schema::create('pm_job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 20);                                   // schedule | command | manual
            $table->foreignId('ran_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('schedules_checked')->default(0);
            $table->unsignedInteger('tickets_created')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_job_runs');

        Schema::table('pm_schedules', function (Blueprint $table) {
            $table->dropColumn('last_generated_at');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropUnique('requests_pm_occurrence_unique');
            $table->dropConstrainedForeignId('pm_schedule_id');
            $table->dropColumn('pm_due_date');
        });
    }
};