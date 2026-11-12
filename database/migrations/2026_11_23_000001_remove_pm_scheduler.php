<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the PM Scheduler, Equipment, PM reports and Maintenance Calendar from the database.
 *
 * The six migrations that created these things were deleted from the project, so a NEW database never gets them
 * and every step below does nothing. An EXISTING database still has them, and this migration drops them:
 *   - the requests.pm_* columns (and their indexes / foreign keys). PM tickets stay as normal tickets.
 *   - the tables pm_ticket_checklist_items, pm_checklist_items, pm_job_runs, pm_schedules and equipment
 *   - facilities.location (it was added only for the PM Scheduler)
 *   - the starter FAQ entries about preventive maintenance
 *
 * Every step checks that the thing exists first, so it is safe to run again after a failure.
 * THE DROPPED DATA CANNOT BE RESTORED by rolling back. Make a database backup first if you need any of it.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $requestColumns = [
        'pm_schedule_id', 'pm_due_date', 'pm_completed_date', 'pm_technician_id', 'pm_notes',
        'pm_notified_at', 'pm_reminded_at', 'pm_overdue_at', 'pm_escalated_at',
    ];

    public function up(): void
    {
        if (Schema::hasTable('requests')) {
            $this->dropRequestColumns();
        }

        // children first, then the tables they point to
        foreach (['pm_ticket_checklist_items', 'pm_checklist_items', 'pm_job_runs', 'pm_schedules', 'equipment'] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasTable('facilities') && Schema::hasColumn('facilities', 'location')) {
            Schema::table('facilities', function (Blueprint $table) {
                $table->dropColumn('location');
            });
        }

        if (Schema::hasTable('faqs') && Schema::hasColumn('faqs', 'source')) {
            DB::table('faqs')
                ->where('source', 'starter')
                ->where(function ($q) {
                    $q->where('topic', 'Preventive maintenance')
                        ->orWhere('question', 'How do I complete a preventive maintenance (PM) task?');
                })
                ->delete();
        }
    }

    /** Nothing can be brought back: the tables and their rows are gone. Restore from a backup or from git history. */
    public function down(): void
    {
        //
    }

    private function dropRequestColumns(): void
    {
        $present = array_values(array_filter($this->requestColumns, fn (string $c) => Schema::hasColumn('requests', $c)));

        if ($present === []) {
            return;
        }

        // 1. foreign keys on those columns (MySQL will not drop an index a foreign key still uses)
        $foreign = array_values(array_filter(
            Schema::getForeignKeys('requests'),
            fn (array $fk) => array_intersect($fk['columns'], $present) !== [],
        ));

        if ($foreign !== []) {
            Schema::table('requests', function (Blueprint $table) use ($foreign) {
                foreach ($foreign as $fk) {
                    $table->dropForeign($fk['name']);
                }
            });
        }

        // 2. every index that includes one of those columns
        $indexes = array_values(array_filter(
            Schema::getIndexes('requests'),
            fn (array $index) => ! $index['primary'] && array_intersect($index['columns'], $present) !== [],
        ));

        if ($indexes !== []) {
            Schema::table('requests', function (Blueprint $table) use ($indexes) {
                foreach ($indexes as $index) {
                    $index['unique'] ? $table->dropUnique($index['name']) : $table->dropIndex($index['name']);
                }
            });
        }

        // 3. the columns
        Schema::table('requests', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }
};
