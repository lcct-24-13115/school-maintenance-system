<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class WorkflowAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $mnt = Department::where('code', Department::MAINTENANCE_CODE)->value('id');
        $other = Department::where('code', '!=', Department::MAINTENANCE_CODE)->orderBy('id')->value('id');

        $accounts = [
            [Role::TEACHER, 'Test Teacher', 'teacher@lcctanauan.edu.ph', $other],
            [Role::HEAD, 'Sir Pamplona', 'pamplona@lcctanauan.edu.ph', $mnt],
            [Role::STAFF, 'Maintenance Staff 1', 'staff1@lcctanauan.edu.ph', $mnt],
            [Role::STAFF, 'Maintenance Staff 2', 'staff2@lcctanauan.edu.ph', $mnt],
        ];

        foreach ($accounts as [$slug, $name, $email, $dept]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make('password'),
                'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
                'department_id' => $dept,
                'email_verified_at' => now(),
                'must_change_password' => true,
                'approval_status' => 'approved',
                'is_active' => true,
            ]);
        }
    }
}
