<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PM integration, Phase A: checklist template per schedule, a copy of it on every PM ticket, and fixed / floating next due date. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_schedules', function (Blueprint $table) {
            $table->string('schedule_basis', 10)->default('fixed')->after('lead_time_days');   // fixed | floating
        });

        Schema::create('pm_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_schedule_id')->constrained('pm_schedules')->cascadeOnDelete();
            $table->string('label', 200);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // A COPY of the template, made when the ticket is created: editing the template later never changes an existing ticket.
        Schema::create('pm_ticket_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->string('label', 200);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_done')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_ticket_checklist_items');
        Schema::dropIfExists('pm_checklist_items');

        Schema::table('pm_schedules', function (Blueprint $table) {
            $table->dropColumn('schedule_basis');
        });
    }
};