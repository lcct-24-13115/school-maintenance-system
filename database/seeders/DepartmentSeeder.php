<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

// Placeholder departments: edit the names to match your school.
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            'ELEM' => 'Elementary Department',
            'HS' => 'High School Department',
            'SHS' => 'Senior High School Department',
            'ADMIN' => 'Administration',
            'FIN' => 'Finance Office',
            'MNT' => 'Maintenance Office',
        ];

        foreach ($departments as $code => $name) {
            Department::updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
