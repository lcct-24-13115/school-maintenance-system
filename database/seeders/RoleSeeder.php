<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [Role::REQUESTER, 'Requester / Teacher', 'Submits and tracks requests'],
            [Role::MAINTENANCE_ADMIN, 'Maintenance Admin', 'Reviews requests, assigns queue and priority'],
            [Role::FINANCE, 'Finance / Bookkeeper', 'Budget review, receipts and expenditures'],
            [Role::CUSTODIAN, 'Property Custodian', 'Inventory, property tags and clearance'],
            [Role::PRESIDENT, 'School President', 'Final approving authority'],
            [Role::DEPARTMENT_HEAD, 'OIC / Unit Head / Dept. Head', 'Endorses and notes requests of their own department'],
            [Role::TREASURER, 'Treasurer', 'Approves repair requests (Form FO-08)'],
        ];

        foreach ($roles as [$slug, $name, $description]) {
            Role::updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description]);
        }
    }
}
