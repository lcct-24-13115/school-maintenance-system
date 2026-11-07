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
            [Role::MAINTENANCE_ADMIN, 'Head of Maintenance', 'Reviews and approves requests, assigns queue and priority'],
            [Role::FINANCE, 'Finance / Bookkeeper', 'Budget review, receipts and expenditures'],
            [Role::CUSTODIAN, 'Property Custodian', 'Inventory, property tags and clearance'],
        ];

        foreach ($roles as [$slug, $name, $description]) {
            Role::updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description]);
        }
    }
}
