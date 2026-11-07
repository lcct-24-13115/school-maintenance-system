<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PM Scheduler, Phase 1: facility location, equipment, and the recurring PM schedules. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('location')->nullable()->after('name');
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->string('category', 100)->nullable();
            $table->string('serial_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pm_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();   // used by the ticket in Phase 2
            $table->string('ticket_priority', 30)->default('first_come_first_served');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->string('frequency_type', 20);                 // daily|weekly|monthly|quarterly|yearly|custom
            $table->unsignedSmallInteger('frequency_interval')->default(1);   // every N units (custom = every N days)
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_due_date')->nullable();            // null = the schedule has ended
            $table->unsignedSmallInteger('lead_time_days')->default(7);   // ticket is made this many days before the due date

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'next_due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_schedules');
        Schema::dropIfExists('equipment');

        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};