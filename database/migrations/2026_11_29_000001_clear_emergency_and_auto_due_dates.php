<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The emergency feature is switched off and the target date is set by the Head of Maintenance (not automatic).
 *  - every emergency flag is cleared;
 *  - a ticket that is not assigned yet loses its automatic target date (the Head sets it when assigning).
 * A ticket that is already assigned keeps its date. Not reversible: the old values are not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('requests')->where('is_emergency', true)->update([
            'is_emergency' => false,
            'emergency_reason' => null,
            'emergency_flagged_by' => null,
            'emergency_flagged_at' => null,
        ]);

        DB::table('requests')->whereNull('assigned_to')->whereNotNull('due_at')->update(['due_at' => null]);
    }

    public function down(): void
    {
        // nothing to restore
    }
};
