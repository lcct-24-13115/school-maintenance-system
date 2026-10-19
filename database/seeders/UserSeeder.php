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
            [Role::REQUESTER, 'Test Requester', 'requester@school.test', 'ELEM'],
            [Role::MAINTENANCE_ADMIN, 'Test Maintenance Admin', 'maintenance@school.test', 'MNT'],
            [Role::FINANCE, 'Test Finance', 'finance@school.test', 'FIN'],
            [Role::CUSTODIAN, 'Test Custodian', 'custodian@school.test', 'ADMIN'],
            [Role::PRESIDENT, 'Test President', 'president@school.test', 'ADMIN'],
            [Role::DEPARTMENT_HEAD, 'Test Dept Head (Elementary)', 'depthead@school.test', 'ELEM'],
            [Role::TREASURER, 'Test Treasurer', 'treasurer@school.test', 'FIN'],
            // extra accounts so filters/analytics (by department, by assignee) have something to show
            [Role::REQUESTER, 'Test Requester (High School)', 'requester2@school.test', 'HS'],
            [Role::REQUESTER, 'Test Requester (Senior High)', 'requester3@school.test', 'SHS'],
            [Role::MAINTENANCE_ADMIN, 'Test Maintenance Staff 2', 'maintenance2@school.test', 'MNT'],
            [Role::MAINTENANCE_ADMIN, 'Test Maintenance Staff 3', 'maintenance3@school.test', 'MNT'],
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
