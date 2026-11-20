<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Department Head (Office-In-Charge) accounts for the offices of the demo teachers, so the Use of Facilities and
 * Requisition Slip forms of a Teacher can be endorsed / noted. A Department Head signs ONLY for their own department.
 * Password: "password" (change before going live). Safe to run again. Add more offices to $codes, or make the
 * account on Admin > Users > New user (Role: Department Head, and choose the Department).
 *
 *   php artisan db:seed --class=DepartmentHeadSeeder
 */
class DepartmentHeadSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $codes = ['ACAD' => 'Academic', 'LIB' => 'Library'];
        $role = Role::where('slug', Role::HEAD)->firstOrFail();

        foreach ($codes as $code => $label) {
            $department = Department::where('code', $code)->first();

            if ($department === null) {
                $this->command?->warn("Department {$code} not found. Skipped.");

                continue;
            }

            User::updateOrCreate(['email' => 'depthead.'.strtolower($code).'@lcctanauan.edu.ph'], [
                'name' => "{$label} Department Head",
                'password' => Hash::make('password'),
                'role_id' => $role->id,
                'department_id' => $department->id,
                'email_verified_at' => now(),
                'is_active' => true,
            ]);
        }
    }
}
