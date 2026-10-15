<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FORM 2: REQUISITION SLIP (Form 1-08)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisition_slips', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique();                       // RS-2026-00001
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->date('date_filed');
            $table->date('date_needed');

            // Checkboxes: cash, supplies, material_equipment, repair, others (can tick more than one)
            $table->json('request_types');
            $table->string('other_type')->nullable();
            $table->string('payee_name')->nullable();                       // "For check only"
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('remarks')->nullable();

            // submitted > noted > recommended > approved > closed  (or rejected)
            $table->string('status', 30)->default('submitted')->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('requisition_slip_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_slip_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);                // QTY
            $table->string('description');                                  // DESCRIPTION
            $table->string('account_charged')->nullable();                  // ACCOUNT TO BE CHARGED
            $table->decimal('amount', 12, 2)->default(0);                   // TOTAL AMOUNT
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_slip_items');
        Schema::dropIfExists('requisition_slips');
    }
};
