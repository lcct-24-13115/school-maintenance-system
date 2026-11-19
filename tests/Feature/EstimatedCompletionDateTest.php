<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Mail\TicketNotificationMail;
use App\Models\TicketApproval;
use App\Models\User;
use App\Services\TicketEstimate;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The Head of Maintenance tells the requester the estimated completion date once the President approved. */
class EstimatedCompletionDateTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $head;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);

        $this->requester = $this->make('requester', 'FIN');
        $this->head = $this->make('head', 'FIN');
        $this->staff = $this->make('staff', 'FIN');
    }

    private function make(string $slug, ?string $deptCode = null): User
    {
        return User::create([
            'name' => 'Test '.$slug.' '.$deptCode,
            'email' => $slug.'-'.($deptCode ?? 'none').'-'.uniqid().'@lcctanauan.edu.ph',
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'department_id' => $deptCode === null ? null : (int) Department::where('code', $deptCode)->value('id'),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    /** A ticket that is still waiting for approval. */
    private function pending(): MaintenanceRequest
    {
        $fin = (int) Department::where('code', 'FIN')->value('id');

        $ticket = MaintenanceRequest::create([
            'requester_id' => $this->requester->id,
            'department_id' => $fin,
            'assigned_department_id' => $fin,
            'category_id' => Category::query()->orderBy('id')->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Room 101',
            'description' => 'Broken door',
        ]);

        TicketWorkflow::startChain($ticket);

        return $ticket->refresh();
    }

    /** Fully approved (every signature of the chain signed). */
    private function approved(): MaintenanceRequest
    {
        $ticket = $this->pending();
        $ticket->approvals()->update(['status' => TicketApproval::APPROVED]);
        $ticket->update(['status' => TicketStatus::Approved, 'president_approved_at' => now()]);

        return $ticket->refresh();
    }

    public function test_the_head_sets_the_estimate_and_the_requester_sees_it(): void
    {
        Mail::fake();
        $ticket = $this->approved();
        $date = now()->addDays(5);

        $this->assertFalse(TicketEstimate::has($ticket));

        $this->actingAs($this->head)
            ->post(route('tickets.estimate', $ticket), ['estimated_date' => $date->toDateString(), 'estimate_message' => 'Waiting for the door parts.'])
            ->assertSessionHas('status');

        $ticket->refresh();
        $this->assertTrue(TicketEstimate::has($ticket));
        $this->assertSame($date->toDateString(), $ticket->due_at->toDateString());
        $this->assertSame($this->head->id, (int) $ticket->eta_set_by);
        $this->assertSame(TicketStatus::Approved, $ticket->status);   // the status does not change

        // the requester gets an in-app alert and an email; the Head (who acted) gets none
        $this->assertSame(1, $this->requester->notifications()->where('data->event', 'estimate_set')->count());
        $this->assertSame(0, $this->head->notifications()->where('data->event', 'estimate_set')->count());
        Mail::assertQueued(TicketNotificationMail::class, fn ($m) => $m->event === 'estimate_set' && $m->hasTo($this->requester->email));

        $this->actingAs($this->requester)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Estimated completion: '.$date->format('F j, Y'))
            ->assertSee('Waiting for the door parts.');
    }

    public function test_the_requester_is_told_it_is_coming_while_there_is_no_estimate_yet(): void
    {
        $ticket = $this->approved();

        $this->actingAs($this->requester)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('will give you an estimated completion date soon');
    }

    public function test_a_new_date_is_a_revised_estimate_and_the_history_keeps_both(): void
    {
        $ticket = $this->approved();

        TicketEstimate::set($ticket, $this->head, now()->addDays(3)->toDateString());
        $ticket = TicketEstimate::set($ticket->fresh(), $this->head, now()->addDays(7)->toDateString(), 'Delayed.');

        $this->assertSame(now()->addDays(7)->toDateString(), $ticket->due_at->toDateString());
        $this->assertSame(2, $ticket->statusLogs()->where('meta->estimate', true)->count());
    }

    public function test_it_cannot_be_set_before_the_president_approved(): void
    {
        $ticket = $this->pending();

        $this->expectException(DomainException::class);
        TicketEstimate::set($ticket, $this->head, now()->addDays(3)->toDateString());
    }

    public function test_staff_and_requesters_cannot_set_it(): void
    {
        $ticket = $this->approved();
        $date = now()->addDays(3)->toDateString();

        $this->actingAs($this->staff)->post(route('tickets.estimate', $ticket), ['estimated_date' => $date])->assertForbidden();
        $this->actingAs($this->requester)->post(route('tickets.estimate', $ticket), ['estimated_date' => $date])->assertForbidden();

        $this->assertFalse(TicketEstimate::has($ticket->fresh()));
    }

    public function test_a_date_in_the_past_is_refused(): void
    {
        $ticket = $this->approved();

        $this->actingAs($this->head)
            ->post(route('tickets.estimate', $ticket), ['estimated_date' => now()->subDay()->toDateString()])
            ->assertSessionHasErrorsIn('estimate', 'estimated_date');

        $this->assertFalse(TicketEstimate::has($ticket->fresh()));
    }
}
