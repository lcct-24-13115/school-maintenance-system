<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Personnel only: registrations that are still waiting for approval and are NOT Faculty / Staff (students, parents,
 * guardians, alumni, outside organizations) are marked Not approved, so the "Waiting for approval" list only holds
 * personnel. They are kept (never deleted) and show the reason. Nothing is e-mailed.
 * Skipped when PERSONNEL_ONLY=false. Safe to run again.
 */
return new class extends Migration
{
    private const REASON = 'This system is for LCCT personnel only.';

    public function up(): void
    {
        if (! config('school.personnel_only', true)) {
            return;
        }

        DB::table('users')
            ->where('approval_status', 'pending')
            ->whereNotNull('requester_type')
            ->where('requester_type', '!=', 'FACULTY/STAFF')
            ->update([
                'approval_status' => 'rejected',
                'rejection_reason' => self::REASON,
                'decided_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('approval_status', 'rejected')
            ->where('rejection_reason', self::REASON)
            ->update(['approval_status' => 'pending', 'rejection_reason' => null, 'decided_at' => null]);
    }
};
