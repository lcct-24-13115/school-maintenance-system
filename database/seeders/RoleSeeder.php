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
            [Role::MAINTENANCE_ADMIN, 'Maintenance Admin', 'Manages users, settings and categories; can do everything the Head and Staff can'],
            [Role::HEAD, 'Head of Maintenance', 'Reviews and authorizes requests, assigns the queue, closes tickets, reads reports and the audit log'],
            [Role::STAFF, 'Maintenance Staff', 'Takes and completes tasks; can take an unassigned ticket or transfer their own'],
            [Role::FINANCE, 'Finance', 'Budget review, receipts and expenditures'],
            [Role::CUSTODIAN, 'Property Custodian', 'Inventory, property tags and clearance'],
        ];

        // Retired roles: seeded only under phpunit, because the older tests still use them.
        if (app()->environment('testing')) {
            $roles = array_merge($roles, [
                [Role::DEPARTMENT_HEAD, 'Department Head / OIC', 'Approves requests for the department'],
                [Role::TREASURER, 'Treasurer', 'Budget approval'],
                [Role::PRESIDENT, 'School President', 'Final approval'],
            ]);
        }

        foreach ($roles as [$slug, $name, $description]) {
            Role::updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description]);
        }
    }
}
