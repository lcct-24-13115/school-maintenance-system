<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// Staff accounts (students are added with: php artisan students:import). Password: "password". Change before going live.
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [Role::SYSTEM_ADMIN, 'System Administrator', 'sysadmin@lcctanauan.edu.ph', null],
            [Role::MAINTENANCE_ADMIN, 'Head of Maintenance', 'maintenance@lcctanauan.edu.ph', Department::MAINTENANCE_CODE],
            [Role::CUSTODIAN, 'Property Custodian', 'custodian@lcctanauan.edu.ph', null],
            [Role::FINANCE, 'Finance', 'finance@lcctanauan.edu.ph', 'FIN'],
        ];

        // Retired roles: seeded only under phpunit, because the older tests still use them.
        if (app()->environment('testing')) {
            $accounts = array_merge($accounts, [
                [Role::REQUESTER, 'Test Requester', 'requester@lcctanauan.edu.ph', 'FIRST'],
                [Role::DEPARTMENT_HEAD, 'Test Dept Head', 'depthead@lcctanauan.edu.ph', 'FIRST'],
                [Role::TREASURER, 'Test Treasurer', 'treasurer@lcctanauan.edu.ph', 'FIN'],
                [Role::PRESIDENT, 'Test President', 'president@lcctanauan.edu.ph', null],
            ]);
        }

        foreach ($accounts as [$slug, $name, $email, $deptCode]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make('password'),
                'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
                'department_id' => match (true) {
                    $deptCode === 'FIRST' => Department::query()->orderBy('id')->value('id'),
                    (bool) $deptCode => Department::where('code', $deptCode)->value('id'),
                    default => null,
                },
                'email_verified_at' => now(),
            ]);
        }
    }
}
