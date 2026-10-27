<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merge Phase M1: target completion date ("due_at") on every ticket.
 * Existing tickets are back-filled as submitted_at + the default SLA hours (config/school.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('submitted_at');
            $table->index('due_at', 'requests_due_at_idx');
        });

        $routine = (int) config('school.settings_defaults.sla_hours_routine', 120);
        $emergency = (int) config('school.settings_defaults.sla_hours_emergency', 24);

        DB::table('requests')
            ->whereNull('due_at')
            ->whereNotNull('submitted_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($routine, $emergency) {
                foreach ($rows as $row) {
                    $hours = $row->is_emergency ? $emergency : $routine;

                    DB::table('requests')->where('id', $row->id)->update([
                        'due_at' => Carbon::parse($row->submitted_at)->addHours($hours),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex('requests_due_at_idx');
            $table->dropColumn('due_at');
        });
    }
};
