<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Mail\TicketNotificationMail;
use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
use App\Notifications\FormActivity;
use App\Rules\AdvanceNotice;
use App\Services\TicketRouter;
use App\Services\TicketRoutingException;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Multi-department Phase 3: the generic ticket form (/tickets/create), TicketRouter, the unassigned queue,
 * and alerts that go to the RECEIVING department. The FO-08 repair flow must keep working.
 */
class TicketRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** Every department that receives tickets on the generic form (all except the Maintenance Office). */
    private const CODES = ['ITDC', 'DSA', 'GUID', 'FIN', 'REG', 'ACAD', 'LIB', 'AEA', 'ADMS', 'HR', 'MKT'];

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
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private function dept(string $code): Department
    {
        return Department::where('code', $code)->firstOrFail();
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function makeUser(string $role, ?string $deptCode, string $email): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'department_id' => $deptCode ? $this->dept($deptCode)->id : null,
            'email' => $email,
            'is_active' => true,
        ]);
    }

    /** A real (non "Other") category of the department. */
    private function categoryOf(string $code): Category
    {
        return Category::where('department_id', $this->dept($code)->id)
            ->where('name', '!=', Category::OTHER)->orderBy('id')->firstOrFail();
    }

    private function form(string $code, array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Please help me',
            'description' => 'I need this from your office.',
            'assigned_department_id' => $this->dept($code)->id,
            'category_id' => $this->categoryOf($code)->id,
            'priority' => 'first_come_first_served',
        ];
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('photo.png', base64_decode(self::PNG));
    }

    // ---- routing: every department ---------------------------------------------------------------

    public function test_a_ticket_goes_to_the_department_of_its_category_for_every_department(): void
    {
        $requester = $this->user('requester');

        foreach (self::CODES as $code) {
            $this->actingAs($requester)
                ->post(route('tickets.store'), $this->form($code, [
                    'title' => "Ticket for {$code}",
                    'contact_name' => 'Juan Dela Cruz',
                    'contact_email' => 'juan@lcctanauan.edu.ph',
                    'contact_phone' => '0917 123 4567',
                ]))
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('dashboard'));

            $ticket = MaintenanceRequest::where('title', "Ticket for {$code}")->firstOrFail();

            $this->assertSame($this->dept($code)->id, $ticket->assigned_department_id, "{$code} receives its own ticket");
            $this->assertSame($requester->department_id, $ticket->department_id, 'department_id stays the requester\'s');
            $this->assertSame($this->categoryOf($code)->id, $ticket->category_id);
            $this->assertNull($ticket->assigned_to, 'default queue: nobody is assigned');
            $this->assertSame(TicketStatus::Submitted, $ticket->status);
            $this->assertSame('Juan Dela Cruz', $ticket->contact_name);
            $this->assertSame('juan@lcctanauan.edu.ph', $ticket->contact_email);
            $this->assertSame('0917 123 4567', $ticket->contact_phone);
        }

        $this->assertSame(count(self::CODES), MaintenanceRequest::count());
    }

    public function test_the_ticket_history_says_where_it_was_routed(): void
    {
        $this->actingAs($this->user('requester'))->post(route('tickets.store'), $this->form('REG'))->assertSessionHasNoErrors();

        $log = MaintenanceRequest::firstOrFail()->statusLogs->firstWhere('action', 'note');

        $this->assertNotNull($log);
        $this->assertNull($log->user_id, 'written by the system');
        $this->assertStringContainsString('Registrar', $log->note);
        $this->assertStringContainsString('unassigned queue', $log->note);
    }

    // ---- mismatched department / category --------------------------------------------------------

    public function test_a_category_that_does_not_belong_to_the_chosen_department_is_rejected(): void
    {
        $this->actingAs($this->user('requester'))
            ->post(route('tickets.store'), $this->form('LIB', ['category_id' => $this->categoryOf('REG')->id]))
            ->assertSessionHasErrors('category_id');

        $this->assertSame(0, MaintenanceRequest::count());
    }

    public function test_a_maintenance_category_on_another_departments_form_is_rejected(): void
    {
        $electrical = Category::where('name', 'Electrical')->firstOrFail();

        $this->actingAs($this->user('requester'))
            ->post(route('tickets.store'), $this->form('REG', ['category_id' => $electrical->id]))
            ->assertSessionHasErrors('category_id');

        $this->assertSame(0, MaintenanceRequest::count());
    }

    public function test_the_generic_form_does_not_file_maintenance_requests(): void
    {
        $electrical = Category::where('name', 'Electrical')->firstOrFail();

        $this->actingAs($this->user('requester'))
            ->post(route('tickets.store'), $this->form('REG', [
                'assigned_department_id' => $this->dept('MNT')->id,
                'category_id' => $electrical->id,
            ]))
            ->assertSessionHasErrors('assigned_department_id');

        $this->assertSame(0, MaintenanceRequest::count());
    }

    public function test_a_department_that_is_switched_off_or_not_a_service_department_is_refused(): void
    {
        $requester = $this->user('requester');

        $this->dept('LIB')->update(['is_active' => false]);
        $this->actingAs($requester)->post(route('tickets.store'), $this->form('LIB'))->assertSessionHasErrors('assigned_department_id');

        $this->dept('HR')->update(['is_service_department' => false]);
        $this->actingAs($requester)->post(route('tickets.store'), $this->form('HR'))->assertSessionHasErrors('assigned_department_id');

        $this->assertSame(0, MaintenanceRequest::count());
    }

    public function test_a_hidden_category_is_refused(): void
    {
        $category = $this->categoryOf('REG');
        $category->update(['is_hidden' => true]);

        $this->actingAs($this->user('requester'))
            ->post(route('tickets.store'), $this->form('REG', ['category_id' => $category->id]))
            ->assertSessionHasErrors('category_id');
    }

    public function test_a_subcategory_must_belong_to_the_chosen_category(): void
    {
        $requester = $this->user('requester');
        $own = Subcategory::create(['category_id' => $this->categoryOf('REG')->id, 'name' => 'Rush']);
        $foreign = Subcategory::create(['category_id' => $this->categoryOf('LIB')->id, 'name' => 'Overdue']);

        $this->actingAs($requester)->post(route('tickets.store'), $this->form('REG', ['subcategory_id' => $foreign->id]))
            ->assertSessionHasErrors('subcategory_id');
        $this->assertSame(0, MaintenanceRequest::count());

        $this->actingAs($requester)->post(route('tickets.store'), $this->form('REG', ['subcategory_id' => $own->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($own->id, MaintenanceRequest::firstOrFail()->subcategory_id);
    }

    // ---- the form itself -------------------------------------------------------------------------

    public function test_the_form_lists_departments_and_tags_each_category_with_its_department(): void
    {
        $registrar = $this->dept('REG');
        $category = $this->categoryOf('REG');

        $html = $this->actingAs($this->user('requester'))->get(route('tickets.create'))
            ->assertOk()
            ->assertSee('name="title"', false)
            ->assertSee('name="description"', false)
            ->assertSee('name="assigned_department_id"', false)
            ->assertSee('name="category_id"', false)
            ->assertSee('name="subcategory_id"', false)
            ->assertSee('name="priority"', false)
            ->assertSee('name="contact_phone"', false)
            ->assertSee('name="files[]"', false)
            ->assertSee('>Registrar</option>', false)
            ->assertDontSee('>Maintenance Office</option>', false)
            ->assertDontSee('>Electrical</option>', false)
            ->getContent();

        $this->assertStringContainsString('value="'.$category->id.'" data-department="'.$registrar->id.'"', $html);
    }

    public function test_an_account_without_a_department_must_say_where_it_files_from(): void
    {
        $admin = $this->user('maintenance');
        $this->assertNull($admin->department_id);

        $this->actingAs($admin)->get(route('tickets.create'))->assertOk()->assertSee('name="from_department_id"', false);

        $this->actingAs($admin)->post(route('tickets.store'), $this->form('REG'))->assertSessionHasErrors('from_department_id');
        $this->assertSame(0, MaintenanceRequest::count());

        $this->actingAs($admin)->post(route('tickets.store'), $this->form('REG', ['from_department_id' => $this->dept('HR')->id]))
            ->assertSessionHasNoErrors();

        $ticket = MaintenanceRequest::firstOrFail();
        $this->assertSame($this->dept('HR')->id, $ticket->department_id);
        $this->assertSame($this->dept('REG')->id, $ticket->assigned_department_id);
    }

    public function test_the_required_fields_and_contact_formats_are_validated(): void
    {
        $this->actingAs($this->user('requester'))
            ->post(route('tickets.store'), ['priority' => 'first_come_first_served'])
            ->assertSessionHasErrors(['title', 'description', 'assigned_department_id', 'category_id']);

        $this->actingAs($this->user('requester'))
            ->post(route('tickets.store'), $this->form('REG', ['contact_email' => 'not-an-email', 'contact_phone' => 'call me']))
            ->assertSessionHasErrors(['contact_email', 'contact_phone']);
    }

    public function test_who_may_file_is_still_decided_by_the_filing_roles(): void
    {
        config(['school.filing_roles' => ['requester']]);

        $this->actingAs($this->user('finance'))->get(route('tickets.create'))->assertForbidden();
        $this->actingAs($this->user('finance'))->post(route('tickets.store'), $this->form('REG'))->assertForbidden();
    }

    // ---- attachments -----------------------------------------------------------------------------

    public function test_files_are_stored_through_the_attachment_service_and_bad_files_are_refused(): void
    {
        $requester = $this->user('requester');

        $this->actingAs($requester)->post(route('tickets.store'), $this->form('REG', ['files' => [$this->png()]]))
            ->assertSessionHasNoErrors();

        $ticket = MaintenanceRequest::firstOrFail();
        $this->assertSame(1, $ticket->attachments()->count());

        $this->actingAs($requester)->post(route('tickets.store'), $this->form('REG', [
            'files' => [UploadedFile::fake()->createWithContent('run.php', '<?php echo 1;')],
        ]))->assertSessionHasErrors('files.0');

        $this->assertSame(1, MaintenanceRequest::count(), 'the refused upload filed no ticket');
    }

    // ---- priority (same rule as the repair form) -------------------------------------------------

    public function test_asking_for_an_emergency_without_the_capability_files_a_routine_ticket(): void
    {
        $this->actingAs($this->user('requester'))
            ->post(route('tickets.store'), $this->form('REG', ['priority' => 'emergency']))
            ->assertSessionHasNoErrors();

        $ticket = MaintenanceRequest::firstOrFail();

        $this->assertFalse($ticket->is_emergency);
        $this->assertTrue($ticket->statusLogs->contains(fn ($l) => str_contains((string) $l->note, 'asked for emergency review')));
    }

    // ---- TicketRouter (no HTTP) ------------------------------------------------------------------

    public function test_the_router_takes_the_department_from_the_category(): void
    {
        $category = $this->categoryOf('LIB');

        $decision = TicketRouter::route($category);   // no department given: the category alone decides

        $this->assertTrue($decision->department->is($this->dept('LIB')));
        $this->assertTrue($decision->queued());
        $this->assertSame(
            ['assigned_department_id' => $this->dept('LIB')->id, 'category_id' => $category->id, 'subcategory_id' => null, 'assigned_to' => null],
            $decision->attributes(),
        );
    }

    public function test_the_router_refuses_a_mismatch_and_says_which_field(): void
    {
        try {
            TicketRouter::route($this->categoryOf('REG'), $this->dept('LIB')->id);
            $this->fail('A mismatched department must be refused.');
        } catch (TicketRoutingException $e) {
            $this->assertSame('category_id', $e->field);
            $this->assertStringContainsString('Registrar', $e->getMessage());
        }
    }

    public function test_the_router_refuses_unroutable_categories(): void
    {
        $orphan = Category::create(['name' => 'Orphan', 'type' => 'service', 'department_id' => $this->dept('REG')->id]);
        Category::whereKey($orphan->id)->update(['department_id' => null]);

        foreach ([null, 999999, $orphan->id] as $bad) {
            try {
                TicketRouter::route($bad);
                $this->fail('Category '.var_export($bad, true).' must be refused.');
            } catch (TicketRoutingException $e) {
                $this->assertSame('category_id', $e->field);
            }
        }
    }

    public function test_an_assignee_must_be_active_staff_of_the_receiving_department(): void
    {
        $category = $this->categoryOf('REG');
        $staff = $this->makeUser(Role::STAFF, 'REG', 'reg.staff@lcctanauan.edu.ph');
        $elsewhere = $this->makeUser(Role::STAFF, 'LIB', 'lib.staff@lcctanauan.edu.ph');
        $requester = $this->makeUser(Role::REQUESTER, 'REG', 'reg.requester@lcctanauan.edu.ph');

        $decision = TicketRouter::route($category, null, null, $staff);
        $this->assertFalse($decision->queued());
        $this->assertSame($staff->id, $decision->attributes()['assigned_to']);

        foreach ([$elsewhere, $requester] as $wrong) {
            try {
                TicketRouter::route($category, null, null, $wrong);
                $this->fail("{$wrong->email} must not be accepted as assignee.");
            } catch (TicketRoutingException $e) {
                $this->assertSame('assigned_to', $e->field);
            }
        }
    }

    public function test_auto_assigning_the_department_head_is_an_option_and_falls_back_to_the_queue(): void
    {
        $category = $this->categoryOf('REG');
        $head = $this->makeUser(Role::HEAD, 'REG', 'reg.head@lcctanauan.edu.ph');
        $this->dept('REG')->update(['head_user_id' => $head->id]);

        $this->assertTrue(TicketRouter::route($category)->queued(), 'off by default');

        config(['school.routing.auto_assign_head' => true]);
        $this->assertSame($head->id, TicketRouter::route($category)->assignee?->id);

        $head->update(['is_active' => false]);
        $this->assertTrue(TicketRouter::route($category)->queued(), 'an inactive head means the queue');
    }

    public function test_a_ticket_created_with_an_assignee_is_not_in_the_queue(): void
    {
        $staff = $this->makeUser(Role::STAFF, 'REG', 'reg.staff@lcctanauan.edu.ph');
        $decision = TicketRouter::route($this->categoryOf('REG'), null, null, $staff);

        $ticket = \App\Services\TicketWorkflow::open($this->user('requester'), $decision->attributes() + [
            'department_id' => $this->user('requester')->department_id,
            'maintenance_type' => 'request',
            'title' => 'Pre-assigned',
            'description' => 'x',
            'routing_note' => $decision->note(),
        ]);

        $this->assertSame($staff->id, $ticket->assigned_to);
        $this->assertNotNull($ticket->assigned_at);
        $this->assertSame(0, TicketRouter::unassignedQueue($this->dept('REG'))->count());
    }

    // ---- the unassigned queue --------------------------------------------------------------------

    public function test_the_unassigned_queue_lists_only_open_unowned_tickets_of_that_department(): void
    {
        $requester = $this->user('requester');
        $this->actingAs($requester);

        foreach (['REG', 'REG', 'LIB'] as $i => $code) {
            $this->post(route('tickets.store'), $this->form($code, ['title' => "T{$i}-{$code}"]))->assertSessionHasNoErrors();
        }

        $this->assertSame(['T0-REG', 'T1-REG'], TicketRouter::unassignedQueue($this->dept('REG'))->orderBy('id')->pluck('title')->all());
        $this->assertSame(['T2-LIB'], TicketRouter::unassignedQueue($this->dept('LIB')->id)->pluck('title')->all());
        $this->assertSame(3, MaintenanceRequest::unassignedQueue()->count(), 'no department = every queue');

        // an owned ticket and a finished ticket leave the queue
        $first = MaintenanceRequest::where('title', 'T0-REG')->firstOrFail();
        $first->update(['assigned_to' => $this->makeUser(Role::STAFF, 'REG', 'reg.staff@lcctanauan.edu.ph')->id]);
        MaintenanceRequest::where('title', 'T1-REG')->firstOrFail()->update(['status' => TicketStatus::Cancelled]);

        $this->assertSame(0, TicketRouter::unassignedQueue($this->dept('REG'))->count());
    }

    // ---- who is alerted --------------------------------------------------------------------------

    private function mailedTo(string $event, string $email): bool
    {
        return Mail::queued(TicketNotificationMail::class, fn ($m) => $m->event === $event)
            ->contains(fn ($m) => $m->hasTo($email));
    }

    public function test_a_new_ticket_alerts_the_staff_and_head_of_the_receiving_department_not_the_maintenance_admin(): void
    {
        Mail::fake();
        Notification::fake();

        $regStaff = $this->makeUser(Role::STAFF, 'REG', 'reg.staff@lcctanauan.edu.ph');
        $regHead = $this->makeUser(Role::HEAD, 'REG', 'reg.head@lcctanauan.edu.ph');
        $libStaff = $this->makeUser(Role::STAFF, 'LIB', 'lib.staff@lcctanauan.edu.ph');
        $admin = $this->user('maintenance');

        $this->actingAs($this->user('requester'))->post(route('tickets.store'), $this->form('REG'))->assertSessionHasNoErrors();

        foreach ([$regStaff, $regHead] as $receiver) {
            $this->assertTrue($this->mailedTo('submitted', $receiver->email), "{$receiver->email} gets the email");
            Notification::assertSentTo($receiver, FormActivity::class);
        }

        foreach ([$admin, $libStaff] as $other) {
            $this->assertFalse($this->mailedTo('submitted', $other->email), "{$other->email} gets no email");
            Notification::assertNotSentTo($other, FormActivity::class);
        }
    }

    public function test_the_departments_head_user_is_alerted_too(): void
    {
        $pointer = $this->makeUser(Role::STAFF, null, 'pointer@lcctanauan.edu.ph');   // not a REG account, but the REG head_user_id
        $this->dept('REG')->update(['head_user_id' => $pointer->id]);

        $this->assertSame([$pointer->id], TicketRouter::receivers($this->dept('REG'))->pluck('id')->all());
    }

    public function test_a_department_without_staff_falls_back_to_the_maintenance_team(): void
    {
        Mail::fake();

        $this->actingAs($this->user('requester'))->post(route('tickets.store'), $this->form('HR'))->assertSessionHasNoErrors();
        $this->assertTrue($this->mailedTo('submitted', $this->user('maintenance')->email), 'nobody in HR yet: the maintenance team is told');

        config(['school.routing.fallback_to_maintenance' => false]);
        $this->assertTrue(TicketRouter::receivers($this->dept('HR'))->isEmpty());
    }

    public function test_later_events_also_go_to_the_receiving_department(): void
    {
        $regStaff = $this->makeUser(Role::STAFF, 'REG', 'reg.staff@lcctanauan.edu.ph');
        $libStaff = $this->makeUser(Role::STAFF, 'LIB', 'lib.staff@lcctanauan.edu.ph');
        $requester = $this->user('requester');

        $this->actingAs($requester)->post(route('tickets.store'), $this->form('REG'))->assertSessionHasNoErrors();
        $ticket = MaintenanceRequest::firstOrFail();

        Mail::fake();
        Notification::fake();

        // the requester cancels: the receiving department is told (an in-app alert), not the other department
        $this->actingAs($requester)->post(route('tickets.cancel', $ticket), ['cancel_reason' => 'No longer needed'])->assertSessionHasNoErrors();

        Notification::assertSentTo($regStaff, FormActivity::class);
        Notification::assertNotSentTo($libStaff, FormActivity::class);
        $this->assertTrue($this->mailedTo('cancelled', $regStaff->email));
        $this->assertFalse($this->mailedTo('cancelled', $libStaff->email));
    }

    // ---- the Maintenance flow still works --------------------------------------------------------

    public function test_the_repair_form_still_files_to_the_maintenance_office_and_alerts_the_maintenance_team(): void
    {
        Mail::fake();
        Notification::fake();

        $requester = $this->user('requester');
        $admin = $this->user('maintenance');
        $regStaff = $this->makeUser(Role::STAFF, 'REG', 'reg.staff@lcctanauan.edu.ph');

        $this->actingAs($requester)->get(route('repairs.create'))->assertOk()->assertSee('>Electrical</option>', false);

        $this->actingAs($requester)->post(route('repairs.store'), [
            'department_id' => $requester->department_id,
            'category_id' => Category::where('name', 'Electrical')->value('id'),
            'location' => 'Room 1',
            'job_description' => 'Broken light',
            'reason' => 'Dark room',
            'priority' => 'first_come_first_served',
            'needed_by' => AdvanceNotice::earliest('repair')->toDateString(),
        ])->assertSessionHasNoErrors();

        $ticket = MaintenanceRequest::firstOrFail();

        $this->assertSame($this->dept('MNT')->id, $ticket->assigned_department_id);
        $this->assertSame('Room 1', $ticket->headline());
        $this->assertNull($ticket->title);
        $this->assertTrue($this->mailedTo('submitted', $admin->email));
        Notification::assertSentTo($admin, FormActivity::class);
        Notification::assertNotSentTo($regStaff, FormActivity::class);
    }

    public function test_the_repair_form_still_refuses_another_departments_category(): void
    {
        $requester = $this->user('requester');

        $this->actingAs($requester)->post(route('repairs.store'), [
            'department_id' => $requester->department_id,
            'category_id' => $this->categoryOf('REG')->id,
            'location' => 'Room 1',
            'job_description' => 'x',
            'reason' => 'x',
            'priority' => 'first_come_first_served',
            'needed_by' => AdvanceNotice::earliest('repair')->toDateString(),
        ])->assertSessionHasErrors('category_id');
    }

    public function test_a_generic_ticket_shows_its_title_and_contact_on_the_ticket_page(): void
    {
        $requester = $this->user('requester');

        $this->actingAs($requester)->post(route('tickets.store'), $this->form('REG', [
            'title' => 'Transcript of records',
            'contact_phone' => '0917 123 4567',
        ]))->assertSessionHasNoErrors();

        $this->actingAs($requester)->get(route('tickets.show', MaintenanceRequest::firstOrFail()))
            ->assertOk()
            ->assertSee('Transcript of records')
            ->assertSee('0917 123 4567')
            ->assertSee('Registrar');
    }
}
