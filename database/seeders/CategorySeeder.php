<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            Category::TYPE_MATERIALS => ['Plumbing (faucets, pipes)', 'Electrical', 'Carpentry / Civil Works'],
            Category::TYPE_SUPPLIES => ['Office Supplies', 'Cleaning Supplies'],
            Category::TYPE_EQUIPMENT => ['Computers / IT', 'Air Conditioning', 'Water Pumps', 'Furniture', 'Electrical Equipment'],
        ];

        foreach ($categories as $type => $names) {
            foreach ($names as $i => $name) {
                // firstOrCreate: re-seeding never undoes what an admin changed (order, hidden, lock)
                Category::firstOrCreate(['name' => $name, 'type' => $type], ['sort_order' => $i + 1]);
            }
        }

        // The locked catch-all of each type. Created last, so it never becomes the "first" category.
        foreach (array_keys($categories) as $type) {
            Category::otherFor($type);
        }
    }
}
