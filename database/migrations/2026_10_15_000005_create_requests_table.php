<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table is "requests"; name the model MaintenanceRequest to avoid
        // clashing with Illuminate\Http\Request.
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique();                 // e.g. MR-2026-00001

            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();

            $table->enum('request_type', ['materials', 'equipment']);
            $table->string('maintenance_type');                       // repair, replacement, new acquisition...
            $table->string('location');
            $table->text('description');

            // Only two priority types. FCFS order = submitted_at ASC.
            $table->enum('priority', ['first_come_first_served', 'emergency'])
                  ->default('first_come_first_served');

            $table->enum('status', [
                'submitted',
                'under_review',
                'queued',
                'in_progress',
                'for_finance',
                'for_president_approval',
                'approved',
                'completed',
                'closed',
                'rejected',
            ])->default('submitted');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // President sign-off: required before completed/closed
            $table->foreignId('president_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('president_approved_at')->nullable();
            $table->text('president_remarks')->nullable();

            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority', 'submitted_at']);
        });

        Schema::create('request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->string('item_name');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit')->nullable();
            $table->text('specification')->nullable();
            // No price here: amounts are recorded post-purchase via receipts.
            $table->timestamps();
        });

        Schema::create('request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });

        // Internal audit trail (never exposed on the public announcements board)
        Schema::create('request_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_status_logs');
        Schema::dropIfExists('request_attachments');
        Schema::dropIfExists('request_items');
        Schema::dropIfExists('requests');
    }
};
