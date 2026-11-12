<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Department;
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

        $this->seedDepartmentCategories();

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

    /**
     * Multi-department Phase 2: the sample ticket categories of every other department (checklist section 3).
     * The Maintenance Office keeps its own categories above. Every department also gets a locked "Other".
     * firstOrCreate: re-seeding never undoes what an admin changed.
     */
    private function seedDepartmentCategories(): void
    {
        $byDepartment = [
            'REG' => ['Enrollment records', 'Transcript requests', 'Diploma requests', 'Transfer credentials', 'Correction of records'],
            'FIN' => ['Payment concerns', 'Assessment queries', 'Refunds', 'Billing disputes', 'Scholarship billing'],
            'HR' => ['Employment records', 'Payroll concerns', 'Leave applications', 'Benefits', 'Faculty and staff requests'],
            'ITDC' => ['Network access', 'Wi-Fi concerns', 'Computer laboratory and office support', 'Software installation', 'Email and account access'],
            'DSA' => ['Student activities', 'Organization requests', 'Disciplinary concerns', 'Student welfare', 'Campus events'],
            'ADMS' => ['Admission requirements', 'Application status', 'Scholarship applications', 'Scholarship renewal', 'Financial assistance'],
            'LIB' => ['Book reservations', 'Library card concerns', 'Database access', 'Overdue notices', 'Research assistance'],
            'MKT' => ['Publication requests', 'Event promotions', 'Social-media content', 'Branding materials', 'Media inquiries'],
            'AEA' => ['Alumni records', 'Partnerships', 'Memoranda of agreement', 'Alumni events', 'External linkages'],
            'GUID' => ['Counseling appointment', 'Career guidance', 'Psychological services', 'Student concerns', 'Referral requests'],
            'ACAD' => ['Curriculum concerns', 'Class scheduling', 'Academic policies', 'Faculty concerns', 'Basic Education and College academic requests'],
        ];

        foreach ($byDepartment as $code => $names) {
            $department = Department::where('code', $code)->first();

            if (! $department) {
                continue;
            }

            foreach ($names as $i => $name) {
                Category::firstOrCreate(
                    ['department_id' => $department->id, 'name' => $name, 'type' => Category::TYPE_SERVICE],
                    ['sort_order' => $i + 1],
                );
            }

            Category::otherFor(Category::TYPE_SERVICE, $department->id);
        }
    }
}
