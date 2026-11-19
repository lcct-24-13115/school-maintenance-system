<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sequential approval chain for every ticket: Vice Principal -> Finance -> President.
 * One row per step. Only the FIRST pending row is "the current step"; a later step cannot be signed before it.
 * Also adds the schedule field Maintenance needs:
 *  - requests.scheduled_start  when the Head of Maintenance schedules the work to start
 *    (the target finish date uses the existing requests.due_at)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->unsignedTinyInteger('step_order');
            $table->string('step', 30);                    // vice_principal | finance | president
            $table->string('label');                       // "Vice Principal"
            $table->string('role_slug', 30);               // who may sign this step
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();   // who actually signed
            $table->string('status', 12)->default('pending');   // pending | approved | rejected
            $table->decimal('amount', 12, 2)->nullable();  // Finance: the budget it approved
            $table->text('remarks')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->unique(['request_id', 'step']);
            $table->index(['role_slug', 'status']);
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->date('scheduled_start')->nullable()->after('needed_by');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('scheduled_start');
        });

        Schema::dropIfExists('ticket_approvals');
    }
};
