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
            Category::TYPE_SUPPLIES => ['Cleaning Supplies'],
            Category::TYPE_EQUIPMENT => ['Air Conditioning', 'Water Pumps', 'Furniture', 'Electrical Equipment'],
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

        // Starter subcategories (optional sub-types). firstOrCreate: re-seeding never undoes what an admin changed.
        $subcategories = [
            [Category::TYPE_MATERIALS, 'Plumbing (faucets, pipes)', ['Leaking faucet', 'Clogged drain', 'Broken pipe', 'Toilet / flush problem']],
            [Category::TYPE_MATERIALS, 'Electrical', ['Lights', 'Outlets / switches', 'Wiring', 'Circuit breaker']],
            [Category::TYPE_MATERIALS, 'Carpentry / Civil Works', ['Doors and windows', 'Walls and ceiling', 'Roof leak']],
            [Category::TYPE_EQUIPMENT, 'Air Conditioning', ['Not cooling', 'Water leaking', 'Noisy', 'Remote / control problem']],
            [Category::TYPE_EQUIPMENT, 'Furniture', ['Chairs', 'Tables and desks', 'Cabinets and shelves']],
        ];

        foreach ($subcategories as [$type, $categoryName, $names]) {
            $category = Category::where('name', $categoryName)->where('type', $type)->first();

            foreach ($names as $i => $name) {
                $category?->subcategories()->firstOrCreate(['name' => $name], ['sort_order' => $i + 1]);
            }
        }
    }
}
