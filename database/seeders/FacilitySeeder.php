<?php

namespace Database\Seeders;

use App\Models\Facility;
use Illuminate\Database\Seeder;

// The checkboxes on the "Use of Facilities & Equipment" form, in printed order.
class FacilitySeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'Audio-Visual Room', 'Chapel', 'Mini Hotel', 'Quadrangle', 'Classroom',
            'HE Laboratory', 'Food Laboratory', 'Conference Room', 'Gymnasium', 'Mini Gymnasium',
            'Canteen', 'Dry Laboratory', 'Wet Laboratory', 'Library Open Area', 'LS Library',
            'MS-JHS Library', 'SHS Library', 'College Library', 'Others',
        ];

        foreach ($names as $i => $name) {
            Facility::updateOrCreate(['name' => $name], ['sort_order' => $i + 1]);
        }
    }
}
