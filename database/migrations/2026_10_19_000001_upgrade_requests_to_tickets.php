<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 1: turn the `requests` table into THE maintenance ticket table.
 *  - status enum -> string, remapped to the 8 proposal statuses
 *  - reference_no -> ticket_no  (MT-YYYY-00001)
 *  - emergency flag + reason, confidential flag, assignment time, FO-08 fields,
 *    work-completion notes (problems / recommendations)
 *  - request_type becomes optional and gains "supplies"
 *  - status log can now record assignments, emergency flags, notes (not only status changes)
 */
return new class extends Migration
{
    private const STATUS_MAP = [
        'under_review' => 'pending_authorization',
        'for_finance' => 'pending_authorization',
        'for_president_approval' => 'pending_authorization',
        'queued' => 'approved',
        'completed' => 'work_completed',
        'rejected' => 'disapproved',
    ];

    public function up(): void
    {
        // ---- 1. widen enums into plain strings -------------------------------------------------
        Schema::table('requests', function (Blueprint $table) {
            $table->string('status', 30)->default('submitted')->change();
            $table->string('request_type', 20)->nullable()->change();   // materials | equipment | supplies | null
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('type', 20)->change();                       // adds "supplies"
        });

        // ---- 2. old status values -> new ones ---------------------------------------------------
        foreach (self::STATUS_MAP as $old => $new) {
            DB::table('requests')->where('status', $old)->update(['status' => $new]);
        }

        DB::table('categories')
            ->whereIn('name', ['Office Supplies', 'Cleaning Supplies'])
            ->where('type', 'materials')
            ->update(['type' => 'supplies']);

        // ---- 3. reference_no -> ticket_no, MR-... -> MT-... ----------------------------------
        Schema::table('requests', function (Blueprint $table) {
            $table->renameColumn('reference_no', 'ticket_no');
        });

        DB::table('requests')->where('ticket_no', 'like', 'MR-%')->orderBy('id')->each(function ($row) {
            DB::table('requests')->where('id', $row->id)->update(['ticket_no' => 'MT-'.substr($row->ticket_no, 3)]);
        });

        // ---- 4. new ticket columns --------------------------------------------------------------
        Schema::table('requests', function (Blueprint $table) {
            $table->boolean('is_emergency')->default(false);
            $table->text('emergency_reason')->nullable();
            $table->foreignId('emergency_flagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('emergency_flagged_at')->nullable();

            $table->boolean('is_confidential')->default(false);

            $table->date('needed_by')->nullable();                      // FO-08 "date needed"
            $table->text('reason')->nullable();                         // FO-08 "reason for request"

            $table->timestamp('assigned_at')->nullable();

            $table->text('problems_encountered')->nullable();           // used by accomplishment reports
            $table->text('recommendations')->nullable();

            $table->index(['is_emergency', 'submitted_at'], 'requests_queue_idx');
            $table->index('completed_at', 'requests_completed_at_idx');  // analytics groups by this
        });

        // old rows: keep the emergency flag / assignment time that already existed in another form
        DB::table('requests')->where('priority', 'emergency')->update([
            'is_emergency' => true,
            'emergency_reason' => 'Flagged as emergency before the ticketing upgrade.',
        ]);
        DB::table('requests')->whereNotNull('assigned_to')->update(['assigned_at' => DB::raw('updated_at')]);

        // ---- 5. history log can record more than status changes -------------------------------
        Schema::table('request_status_logs', function (Blueprint $table) {
            $table->string('to_status')->nullable()->change();
        });

        Schema::table('request_status_logs', function (Blueprint $table) {
            $table->string('action', 30)->default('status_change')->index();   // status_change|assignment|reassignment|emergency_flag|authorization|note
            $table->json('meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('request_status_logs', function (Blueprint $table) {
            $table->dropIndex(['action']);
            $table->dropColumn(['action', 'meta']);
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex('requests_queue_idx');
            $table->dropIndex('requests_completed_at_idx');
            $table->dropConstrainedForeignId('emergency_flagged_by');
            $table->dropColumn([
                'is_emergency', 'emergency_reason', 'emergency_flagged_at', 'is_confidential',
                'needed_by', 'reason', 'assigned_at', 'problems_encountered', 'recommendations',
            ]);
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->renameColumn('ticket_no', 'reference_no');
        });

        // several old values collapsed into one new value; restore the simplest old one
        DB::table('requests')->where('status', 'work_completed')->update(['status' => 'completed']);
        DB::table('requests')->where('status', 'disapproved')->update(['status' => 'rejected']);
        DB::table('requests')->where('status', 'pending_authorization')->update(['status' => 'under_review']);

        // note: the enum constraint on status/request_type/categories.type is not restored on rollback.
    }
};
