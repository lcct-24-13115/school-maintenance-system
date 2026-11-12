<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real "Staff" and "Head" roles (same idea as the reference system: Requester < Staff < Head < Admin).
 *
 *   maintenance_admin  -> shown as "Maintenance Admin" (was "Head of Maintenance"). Keeps EVERY power it has today.
 *   head               -> new: "Head of Maintenance" (assigns anyone, authorizes, closes, reports, audit log, FAQ)
 *   staff              -> new: "Maintenance Staff" (takes / transfers its own tickets, does the work)
 *
 * The old "staff only" tick box (users.staff_only) is replaced by the Staff role: every Maintenance Admin account that
 * was ticked "staff only" becomes a Staff account, then the column is dropped. Nobody else changes role, so nobody
 * loses a power by running this migration. Re-running is safe.
 */
return new class extends Migration
{
    /** slug => [name, description]. The order matters: maintenance_admin is renamed first because roles.name is unique. */
    private const ROLES = [
        'maintenance_admin' => ['Maintenance Admin', 'Manages users, settings and categories; can do everything the Head and Staff can'],
        'head' => ['Head of Maintenance', 'Reviews and authorizes requests, assigns the queue, closes tickets, reads reports and the audit log'],
        'staff' => ['Maintenance Staff', 'Takes and completes tasks; can take an unassigned ticket or transfer their own'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ROLES as $slug => [$name, $description]) {
            if (DB::table('roles')->where('slug', $slug)->exists()) {
                DB::table('roles')->where('slug', $slug)->update(['name' => $name, 'description' => $description, 'updated_at' => $now]);
            } else {
                DB::table('roles')->insert(['name' => $name, 'slug' => $slug, 'description' => $description, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        if (Schema::hasColumn('users', 'staff_only')) {
            $adminId = DB::table('roles')->where('slug', 'maintenance_admin')->value('id');
            $staffId = DB::table('roles')->where('slug', 'staff')->value('id');

            DB::table('users')->where('role_id', $adminId)->where('staff_only', true)->update(['role_id' => $staffId]);

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('staff_only');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'staff_only')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('staff_only')->default(false)->after('is_active');
            });
        }

        $adminId = DB::table('roles')->where('slug', 'maintenance_admin')->value('id');
        $staffId = DB::table('roles')->where('slug', 'staff')->value('id');
        $headId = DB::table('roles')->where('slug', 'head')->value('id');

        // Staff go back to "Maintenance Admin + staff only"; Heads go back to a plain Maintenance Admin (the old Head).
        if ($adminId && $staffId) {
            DB::table('users')->where('role_id', $staffId)->update(['role_id' => $adminId, 'staff_only' => true]);
        }

        if ($adminId && $headId) {
            DB::table('users')->where('role_id', $headId)->update(['role_id' => $adminId]);
        }

        DB::table('roles')->whereIn('slug', ['staff', 'head'])->delete();
        DB::table('roles')->where('slug', 'maintenance_admin')->update(['name' => 'Head of Maintenance', 'updated_at' => now()]);
    }
};
