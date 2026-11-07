<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Equipment;
use App\Models\Facility;
use App\Models\PmSchedule;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Two sample PM schedules. Safe to run again: it never duplicates them. Run: php artisan db:seed --class=PmScheduleSeeder */
class PmScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([FacilitySeeder::class, CategorySeeder::class]);

        $adminId = User::where('email', 'maintenance@lcctanauan.edu.ph')->value('id');
        $start = now(config('app.timezone'))->startOfMonth()->toDateString();

        $samples = [
            [
                'title' => 'Aircon Cleaning',
                'description' => 'Clean the filters and coils, check the refrigerant, test the thermostat.',
                'facility' => 'Conference Room',
                'equipment' => 'Split-type Aircon',
                'equipment_category' => 'Air Conditioning',
                'category' => 'Air Conditioning',
                'frequency_type' => PmSchedule::QUARTERLY,   // every 3 months
            ],
            [
                'title' => 'Generator Checkup',
                'description' => 'Check oil, coolant and battery; run the generator under load for 15 minutes.',
                'facility' => 'Gymnasium',
                'equipment' => 'Standby Generator',
                'equipment_category' => 'Electrical Equipment',
                'category' => 'Electrical Equipment',
                'frequency_type' => PmSchedule::MONTHLY,
            ],
        ];

        foreach ($samples as $s) {
            $facility = Facility::where('name', $s['facility'])->first();

            if (! $facility) {
                continue;
            }

            $equipment = Equipment::firstOrCreate(
                ['name' => $s['equipment'], 'facility_id' => $facility->id],
                ['category' => $s['equipment_category']],
            );

            PmSchedule::firstOrCreate(
                ['title' => $s['title'], 'facility_id' => $facility->id],
                [
                    'description' => $s['description'],
                    'equipment_id' => $equipment->id,
                    'category_id' => Category::where('name', $s['category'])->value('id'),
                    'ticket_priority' => PmSchedule::PRIORITY_FCFS,
                    'assigned_to' => $adminId,
                    'frequency_type' => $s['frequency_type'],
                    'frequency_interval' => 1,
                    'start_date' => $start,
                    'lead_time_days' => 7,
                    'is_active' => true,
                    'created_by' => $adminId,
                ],
            );
        }
    }
}