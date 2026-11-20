<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            DepartmentSeeder::class,
            CategorySeeder::class,
            FacilitySeeder::class,
            UserSeeder::class,
        ]);

        // Optional: 100+ rows of test data in every table. Turn on with SEED_TEST_DATA=true in .env (testing only).
        if (filter_var(env('SEED_TEST_DATA', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->call(TestDataSeeder::class);
        }
    }
}
