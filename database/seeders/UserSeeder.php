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
            [Role::MAINTENANCE_ADMIN, 'Head of Maintenance', 'maintenance@lcctanauan.edu.ph', 'MNT'],
            [Role::CUSTODIAN, 'Property Custodian', 'custodian@lcctanauan.edu.ph', 'ADMIN'],
            [Role::FINANCE, 'Finance / Bookkeeper', 'finance@lcctanauan.edu.ph', 'FIN'],
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
