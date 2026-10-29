<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            Category::TYPE_MATERIALS => ['Plumbing (faucets, pipes)', 'Electrical', 'Office Supplies', 'Carpentry / Civil Works', 'Cleaning Supplies'],
            Category::TYPE_EQUIPMENT => ['Computers / IT', 'Air Conditioning', 'Water Pumps', 'Furniture', 'Electrical Equipment'],
        ];

        foreach ($categories as $type => $names) {
            foreach ($names as $name) {
                Category::updateOrCreate(['name' => $name, 'type' => $type]);
            }
        }
    }
}
