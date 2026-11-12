<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4A: department-scoped roles.
 *
 *   system_admin  -> new, GLOBAL role (not tied to a department).
 *   head / staff  -> relabelled "Department Head" / "Department Staff". Same slugs, so nothing in the code changes.
 *   Existing head / staff / maintenance_admin accounts with NO department are given the Maintenance Office, because
 *   until now every one of them worked for Maintenance. After Phase 4B a scoped account without a department sees nothing.
 *
 * No account changes role, so nobody loses or gains a power here. Re-running is safe.
 */
return new class extends Migration
{
    private const ROLES = [
        'system_admin' => ['System Administrator', 'Global: not tied to a department; manages users, settings and categories across all departments'],
        'head' => ['Department Head', 'Scoped to their department: reviews and authorizes requests, assigns the department queue, closes tickets, reads reports and the audit log'],
        'staff' => ['Department Staff', 'Scoped to their department: takes and completes tasks; can take an unassigned ticket or transfer their own'],
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

        // Back-fill: the department is looked up by the code the Department model already uses for Maintenance.
        $maintenanceId = DB::table('departments')->where('code', 'MNT')->value('id');

        if ($maintenanceId) {
            $scopedIds = DB::table('roles')->whereIn('slug', ['staff', 'head', 'maintenance_admin'])->pluck('id');

            DB::table('users')->whereIn('role_id', $scopedIds)->whereNull('department_id')
                ->update(['department_id' => $maintenanceId, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'head')->update(['name' => 'Head of Maintenance', 'updated_at' => now()]);
        DB::table('roles')->where('slug', 'staff')->update(['name' => 'Maintenance Staff', 'updated_at' => now()]);

        // system_admin accounts fall back to maintenance_admin so nobody is left without a role. Department back-fill is not undone.
        $sys = DB::table('roles')->where('slug', 'system_admin')->value('id');
        $admin = DB::table('roles')->where('slug', 'maintenance_admin')->value('id');

        if ($sys && $admin) {
            DB::table('users')->where('role_id', $sys)->update(['role_id' => $admin]);
        }

        DB::table('roles')->where('slug', 'system_admin')->delete();
    }
};
