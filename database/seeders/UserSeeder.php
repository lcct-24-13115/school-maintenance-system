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
