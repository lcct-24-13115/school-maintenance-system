<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// One test account per role. Password: "password". Change or delete before going live.
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [Role::REQUESTER, 'Test Requester', 'requester@lcctanauan.edu.ph', 'ELEM'],
            [Role::MAINTENANCE_ADMIN, 'Test Maintenance Admin', 'maintenance@lcctanauan.edu.ph', 'MNT'],
            [Role::FINANCE, 'Test Finance', 'finance@lcctanauan.edu.ph', 'FIN'],
            [Role::CUSTODIAN, 'Test Custodian', 'custodian@lcctanauan.edu.ph', 'ADMIN'],
            [Role::PRESIDENT, 'Test President', 'president@lcctanauan.edu.ph', 'ADMIN'],
            [Role::DEPARTMENT_HEAD, 'Test Dept Head (Elementary)', 'depthead@lcctanauan.edu.ph', 'ELEM'],
            [Role::TREASURER, 'Test Treasurer', 'treasurer@lcctanauan.edu.ph', 'FIN'],
            // extra accounts so filters/analytics (by department, by assignee) have something to show
            [Role::REQUESTER, 'Test Requester (High School)', 'requester2@lcctanauan.edu.ph', 'HS'],
            [Role::REQUESTER, 'Test Requester (Senior High)', 'requester3@lcctanauan.edu.ph', 'SHS'],
            [Role::MAINTENANCE_ADMIN, 'Test Maintenance Staff 2', 'maintenance2@lcctanauan.edu.ph', 'MNT'],
            [Role::MAINTENANCE_ADMIN, 'Test Maintenance Staff 3', 'maintenance3@lcctanauan.edu.ph', 'MNT'],
        ];

        foreach ($accounts as [$slug, $name, $email, $deptCode]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make('password'),
                'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
                'department_id' => Department::where('code', $deptCode)->value('id'),
                'email_verified_at' => now(),
            ]);
        }
    }
}
