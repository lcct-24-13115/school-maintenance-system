<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teacher (adviser) step: a complaint filed by a STUDENT is first approved by the student's adviser, and only then
 * goes Vice Principal -> Finance -> President -> Head of Maintenance.
 *
 *  - users.adviser_id             the teacher who is this student's adviser (set by the admin on the Users page)
 *  - ticket_approvals.approver_id the ONE person who may sign this step (used by the adviser step; the other steps
 *                                 stay NULL and are signed by anyone holding the step's role, as before)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'adviser_id')) {
                $table->foreignId('adviser_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('ticket_approvals', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_approvals', 'approver_id')) {
                $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
                $table->index(['approver_id', 'status']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approver_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adviser_id');
        });
    }
};
