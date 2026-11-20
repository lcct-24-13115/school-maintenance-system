<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [Role::REQUESTER, 'Student', 'Submits and tracks maintenance requests'],
            [Role::TEACHER, 'Teacher', 'Files requests; advises students and approves their complaints first'],
            [Role::MAINTENANCE_ADMIN, 'Maintenance Admin', 'Manages users, settings and categories; can do everything the Head and Staff can'],
            [Role::SYSTEM_ADMIN, 'System Administrator', 'Global: not tied to a department; manages users, settings and categories across all departments'],
            [Role::HEAD, 'Department Head', 'Scoped to their department: reviews and authorizes requests, assigns the department queue, closes tickets, reads reports and the audit log'],
            [Role::STAFF, 'Department Staff', 'Scoped to their department: takes and completes tasks; can take an unassigned ticket or transfer their own'],
            [Role::VICE_PRINCIPAL, 'Vice Principal', 'Step 1 of the approval chain: reviews every request first'],
            [Role::FINANCE, 'Finance', 'Step 2 of the approval chain: checks what is spent, where and why; records the approved budget'],
            [Role::PRESIDENT, 'School President', 'Step 3 (final) of the approval chain: only a President-approved request reaches Maintenance'],
            [Role::CUSTODIAN, 'Property Custodian', 'Inventory, property tags and clearance'],
        ];

        // Retired roles: seeded only under phpunit, because the older tests still use them.
        if (app()->environment('testing')) {
            $roles = array_merge($roles, [
                [Role::DEPARTMENT_HEAD, 'Department Head / OIC', 'Approves requests for the department'],
                [Role::TREASURER, 'Treasurer', 'Budget approval'],
            ]);
        }

        foreach ($roles as [$slug, $name, $description]) {
            Role::updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description]);
        }
    }
}
