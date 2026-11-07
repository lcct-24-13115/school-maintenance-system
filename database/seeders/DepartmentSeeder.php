<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

// The 11 offices a request can come from.
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
        ];

        foreach ($departments as $code => $name) {
            Department::updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
