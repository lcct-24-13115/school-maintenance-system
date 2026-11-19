<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\TicketWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoApprovalSeeder extends Seeder
{
    public function run(): void
    {
        config(['school.ticket_emails' => false]);

        $this->call([RoleSeeder::class, DepartmentSeeder::class, CategorySeeder::class, UserSeeder::class]);

        $teacher1 = $this->account('teacher1@lcctanauan.edu.ph', 'Maria Santos (Teacher)', Role::REQUESTER, 'ACAD');
        $teacher2 = $this->account('teacher2@lcctanauan.edu.ph', 'Jose Reyes (Teacher)', Role::REQUESTER, 'LIB');
        $this->account('staff1@lcctanauan.edu.ph', 'Pedro Cruz (Maintenance Staff)', Role::STAFF, 'MNT');
        $this->account('staff2@lcctanauan.edu.ph', 'Ana Lim (Maintenance Staff)', Role::STAFF, 'MNT');

        if (MaintenanceRequest::whereIn('requester_id', [$teacher1->id, $teacher2->id])->exists()) {
            $this->command?->warn('Demo tickets already exist. Nothing added.');

            return;
        }

        $vp = $this->byEmail('viceprincipal@lcctanauan.edu.ph');
        $finance = $this->byEmail('finance@lcctanauan.edu.ph');
        $president = $this->byEmail('president@lcctanauan.edu.ph');
        $head = $this->byEmail('maintenance@lcctanauan.edu.ph');
        $staff1 = $this->byEmail('staff1@lcctanauan.edu.ph');

        $category = Category::visible()->ofDepartment(Department::maintenanceId())->ordered()->first() ?? Category::query()->first();

        $file = fn (User $teacher, string $location, string $what, string $why) => TicketWorkflow::open($teacher, [
            'department_id' => $teacher->department_id,
            'category_id' => $category->id,
            'location' => $location,
            'description' => $what,
            'reason' => $why,
        ]);

        // 1. Waiting for the Vice Principal
        $file($teacher1, 'Grade 5 Room 204', 'The door cannot be locked.', 'Students belongings are not safe.');

        // 2. Waiting for Finance
        $t = $file($teacher2, 'Library reading area', 'Two ceiling lights are not working.', 'Students cannot read in the afternoon.');
        TicketWorkflow::authorize($t, $vp, 'Please check the cost.');

        // 3. Waiting for the President
        $t = $file($teacher1, 'Grade 6 Comfort Room', 'The faucet is leaking and the floor is always wet.', 'Safety risk: students can slip.');
        $t = TicketWorkflow::authorize($t, $vp);
        TicketWorkflow::authorize($t, $finance, 'Budget available.', [], 2500);

        // 4. Fully approved: waiting for Sir Pamplona to schedule and assign
        $t = $file($teacher2, 'Library entrance', 'The glass door hinge is broken.', 'The door may fall.');
        $t = TicketWorkflow::authorize($t, $vp);
        $t = TicketWorkflow::authorize($t, $finance, null, [], 1800);
        TicketWorkflow::authorize($t, $president, 'Approved. Please schedule it.');

        // 5. Scheduled and assigned
        $t = $file($teacher1, 'Grade 4 Room 101', 'The ceiling has a roof leak that needs repair.', 'Water drips on the desks when it rains.');
        $t = TicketWorkflow::authorize($t, $vp);
        $t = TicketWorkflow::authorize($t, $finance, null, [], 6500);
        $t = TicketWorkflow::authorize($t, $president);
        TicketWorkflow::assign($t, $staff1, $head, 'Needs a ladder and materials.', now()->addDays(7)->toDateString(), now()->addDays(2)->toDateString());

        // 6. Disapproved by Finance
        $t = $file($teacher2, 'Faculty room', 'Replace all chairs with new ones.', 'They look old.');
        $t = TicketWorkflow::authorize($t, $vp);
        TicketWorkflow::disapprove($t, $finance, 'The chairs are still usable. Repair only the two broken ones.');

        // 7. Disapproved by the Vice Principal
        $t = $file($teacher1, 'Grade 5 Room 204', 'Repaint the whole room.', 'The color is old.');
        TicketWorkflow::disapprove($t, $vp, 'Painting is scheduled for the summer break.');

        $this->command?->info('Demo data ready: 7 tickets at different steps of the approval chain.');
    }

    private function account(string $email, string $name, string $slug, string $deptCode): User
    {
        User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'department_id' => Department::where('code', $deptCode)->value('id'),
            'is_active' => true,
        ]);

        return $this->byEmail($email);
    }

    private function byEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }
}