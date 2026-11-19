<?php

namespace Tests\Feature;

use App\Mail\TicketNotificationMail;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Head of Maintenance sets the target finish date when assigning: the complainant (requester) is told when
 * the work should be finished, but only for an APPROVED ticket. Approvers (teacher, VP, Finance, President) only approve.
 */
class FinishDateNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(UserSeeder::class);
        config(['school.workflow.authorizers' => ['president']]);
        config(['school.workflow.chain' => [
            ['step' => 'president', 'role' => 'president', 'label' => 'School President', 'needs_amount' => false],
        ]]);
    }

    private function seeded(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function member(string $key, string $roleSlug): User
    {
        return User::updateOrCreate(['email' => $key.'-finish@lcctanauan.edu.ph'], [
            'name' => 'Test '.$key,
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $roleSlug)->firstOrFail()->id,
            'department_id' => \App\Models\Department::maintenanceId(),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function filed(): MaintenanceRequest
    {
        $requester = $this->seeded('requester');

        return TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Leaking faucet',
        ]);
    }

    private function finishMails(string $email): int
    {
        return Mail::queued(TicketNotificationMail::class, fn ($m) => $m->event === 'finish_date' && $m->hasTo($email))->count();
    }

    public function test_requester_is_told_the_finish_date_when_the_head_assigns_an_approved_ticket(): void
    {
        $ticket = $this->filed();
        TicketWorkflow::authorize($ticket, $this->seeded('president'));
        $staff = $this->member('staff1', Role::STAFF);
        $head = $this->member('head1', Role::HEAD);
        Mail::fake();

        $this->actingAs($head)->post(route('tickets.assign', $ticket), [
            'assignee_id' => $staff->id,
            'target_date' => now()->addDays(3)->toDateString(),
        ])->assertSessionHas('status');

        $this->assertSame(1, $this->finishMails($this->seeded('requester')->email));
        $this->assertSame(0, $this->finishMails($this->seeded('president')->email));   // approvers only approve
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->seeded('requester')->id]);
    }

    public function test_no_finish_date_is_sent_while_the_ticket_is_not_approved(): void
    {
        $ticket = $this->filed();   // still waiting for approval
        Mail::fake();

        TicketWorkflow::tellFinishDate($ticket->fresh(), $this->member('head1', Role::HEAD));

        $this->assertSame(0, $this->finishMails($this->seeded('requester')->email));
    }

    public function test_staff_claiming_a_ticket_sends_no_finish_date(): void
    {
        $ticket = $this->filed();
        TicketWorkflow::authorize($ticket, $this->seeded('president'));
        $staff = $this->member('staff1', Role::STAFF);
        Mail::fake();

        $this->actingAs($staff)->post(route('tickets.claim', $ticket))->assertSessionHas('status');

        $this->assertSame(0, $this->finishMails($this->seeded('requester')->email));
    }
}
