<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

// The 12 offices. Each one can file tickets AND (multi-department) receive them.
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            'ITDC' => 'ITDC',
            'DSA' => 'Discipline Office/Student Affairs',
            'GUID' => 'Guidance',
            'FIN' => 'Finance',
            'REG' => 'Registrar',
            'ACAD' => 'Academic',
            'LIB' => 'Library',
            'AEA' => 'Alumni/External Affairs',
            'ADMS' => 'Admission and Scholarship',
            'HR' => 'HR',
            'MKT' => 'Marketing',
            'MNT' => 'Maintenance Office',   // listed last on purpose: other seeders pick the first department by id
        ];

        foreach ($departments as $code => $name) {
            Department::updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
