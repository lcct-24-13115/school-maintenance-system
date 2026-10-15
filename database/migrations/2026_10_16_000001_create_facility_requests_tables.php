<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FORM 1: USE OF FACILITIES & EQUIPMENT
return new class extends Migration
{
    public function up(): void
    {
        // The 19 checkboxes printed on the form (seeded by FacilitySeeder)
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('facilities_equipment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique();                       // FE-2026-00001
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();   // Person Responsible
            $table->foreignId('department_id')->constrained()->restrictOnDelete();         // Office/Department
            $table->date('date_filed');
            $table->string('purpose');
            $table->unsignedSmallInteger('persons_count');                  // No. of Person to use
            $table->date('date_needed');
            $table->time('time_needed');
            $table->string('other_facility')->nullable();                   // "Others: ______"

            // submitted > endorsed > noted > approved > returned > closed  (or rejected)
            $table->string('status', 30)->default('submitted')->index();

            // "Returned by / Date Returned" box (filled in by the Property Custodian)
            $table->string('returned_by_name')->nullable();
            $table->date('returned_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['date_needed', 'status']);
        });

        // Facility checkboxes that were ticked
        Schema::create('fe_request_facility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fe_request_id')->constrained('facilities_equipment_requests')->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->unique(['fe_request_id', 'facility_id']);
        });

        // Dynamic table: Quantity | Equipment/Materials Needed
        Schema::create('fe_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fe_request_id')->constrained('facilities_equipment_requests')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fe_request_items');
        Schema::dropIfExists('fe_request_facility');
        Schema::dropIfExists('facilities_equipment_requests');
        Schema::dropIfExists('facilities');
    }
};
