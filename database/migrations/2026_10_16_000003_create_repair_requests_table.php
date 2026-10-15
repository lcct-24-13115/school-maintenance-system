<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FORM 3: REQUEST FOR REPAIRS & IMPROVEMENT (Form FO-08)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique();                       // RR-2026-00001
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();   // From: (Department/Office)
            $table->date('date_requested');
            $table->date('needed_by')->nullable();                          // when the work is needed (drives the 5-working-day rule)
            $table->string('location');                                     // shown big on the maintenance card
            $table->text('job_description');
            $table->text('reason');
            $table->string('photo_path')->nullable();                       // optional photo from the requester

            // Queue: emergency first, then first come first served (by created_at)
            $table->enum('priority', ['first_come_first_served', 'emergency'])->default('first_come_first_served');

            // Approval track: submitted > noted > treasurer_approved > approved > closed (or rejected)
            $table->string('status', 30)->default('submitted')->index();

            // Work track (what the maintenance staff taps): pending > accepted > completed
            $table->enum('work_status', ['pending', 'accepted', 'completed'])->default('pending');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['work_status', 'priority', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_requests');
    }
};
