<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 4B: who may SEE a ticket (view only: no claim, assign, transfer or notes here).
 *
 *  - Department Staff / Head (and Maintenance Admin): only tickets whose assigned_department_id is their own department
 *  - the requester: their own tickets
 *  - System Administrator: everything
 *  - a hidden ticket is a 404 (never 403), the same answer as a ticket that does not exist
 */
class TicketViewVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICES = ['FIN', 'REG', 'MNT'];

    private const SCOPED_ROLES = ['staff', 'head', 'maintenance_admin'];

    /** @var array<string, MaintenanceRequest> assigned department code => the ticket that office RECEIVES */
    private array $tickets = [];

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);

        // One person files all three tickets from Finance, so the FILING department never explains who sees what:
        // only assigned_department_id (the office that RECEIVES the ticket) does.
        $this->requester = $this->make('requester', 'FIN');

        foreach (self::OFFICES as $code) {
            $this->tickets[$code] = $this->ticket($code);
        }
    }

    // ---- helpers --------------------------------------------------------------------------------

    private function dept(string $code): int
    {
        return (int) Department::where('code', $code)->value('id');
    }

    private function make(string $slug, ?string $deptCode = null, bool $active = true): User
    {
        return User::create([
            'name' => 'Test '.$slug,
            'email' => $slug.'-'.uniqid().'@lcctanauan.edu.ph',
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'department_id' => $deptCode === null ? null : $this->dept($deptCode),
            'email_verified_at' => now(),
            'is_active' => $active,
        ]);
    }

    private function ticket(string $assignedDeptCode, array $overrides = []): MaintenanceRequest
    {
        return MaintenanceRequest::create($overrides + [
            'requester_id' => $this->requester->id,
            'department_id' => $this->dept('FIN'),
            'assigned_department_id' => $this->dept($assignedDeptCode),
            'category_id' => Category::query()->orderBy('id')->firstOrFail()->id,
            'maintenance_type' => 'repair',
            'location' => 'Room '.$assignedDeptCode,
            'description' => 'Ticket for '.$assignedDeptCode,
        ]);
    }

    /** Ids the model scope lets this user see. */
    private function scopeIds(User $user): array
    {
        return MaintenanceRequest::visibleTo($user)->orderBy('id')->pluck('id')->all();
    }

    /** Ids on the page /tickets. */
    private function listIds(User $user): array
    {
        return $this->actingAs($user)->get(route('tickets.index'))->assertOk()
            ->viewData('tickets')->getCollection()->pluck('id')->sort()->values()->all();
    }

    private function ids(MaintenanceRequest ...$tickets): array
    {
        return collect($tickets)->pluck('id')->sort()->values()->all();
    }

    // ---- role x department matrix (view only) ---------------------------------------------------

    public function test_matrix_scoped_roles_see_only_the_tickets_assigned_to_their_own_department(): void
    {
        foreach (self::SCOPED_ROLES as $slug) {
            foreach (self::OFFICES as $office) {
                $user = $this->make($slug, $office);

                foreach (self::OFFICES as $target) {
                    $ticket = $this->tickets[$target];
                    $own = $office === $target;
                    $label = "$slug of $office, ticket of $target";

                    $this->assertSame($own, $ticket->isVisibleTo($user), "isVisibleTo: $label");
                    $this->assertSame($own, Gate::forUser($user)->allows('view', $ticket), "policy view: $label");
                    $this->assertSame($own, in_array($ticket->id, $this->scopeIds($user), true), "scope: $label");

                    $response = $this->actingAs($user)->get(route('tickets.show', $ticket));
                    $own ? $response->assertOk() : $response->assertNotFound();
                }

                $this->assertSame($this->ids($this->tickets[$office]), $this->scopeIds($user), "scope list: $slug of $office");
                $this->assertSame($this->ids($this->tickets[$office]), $this->listIds($user), "page list: $slug of $office");
            }
        }
    }

    public function test_finance_staff_cannot_see_a_registrar_ticket(): void
    {
        $registrarTicket = $this->tickets['REG'];

        foreach (['staff', 'head'] as $slug) {
            $finance = $this->make($slug, 'FIN');

            $this->assertFalse($registrarTicket->isVisibleTo($finance), $slug);
            $this->assertFalse(Gate::forUser($finance)->allows('view', $registrarTicket), $slug);
            $this->assertNotContains($registrarTicket->id, $this->scopeIds($finance), $slug);
            $this->assertNotContains($registrarTicket->id, $this->listIds($finance), $slug);
            $this->actingAs($finance)->get(route('tickets.show', $registrarTicket))->assertNotFound();

            // ...and the Registrar's own people do see it
            $registrar = $this->make($slug, 'REG');
            $this->assertTrue($registrarTicket->isVisibleTo($registrar), $slug);
            $this->actingAs($registrar)->get(route('tickets.show', $registrarTicket))->assertOk();
        }
    }

    public function test_the_filing_department_does_not_give_access_only_the_assigned_one_does(): void
    {
        // Filed from Finance, received by Registrar: Finance staff (the filing office) still cannot see it.
        $this->assertSame($this->dept('FIN'), (int) $this->tickets['REG']->department_id);
        $this->assertFalse($this->tickets['REG']->isVisibleTo($this->make('staff', 'FIN')));

        // Filed from Registrar, received by Finance: Finance staff see it, Registrar staff do not.
        $otherRequester = $this->make('requester', 'REG');
        $ticket = $this->ticket('FIN', ['requester_id' => $otherRequester->id, 'department_id' => $this->dept('REG')]);

        $this->assertTrue($ticket->isVisibleTo($this->make('staff', 'FIN')));
        $this->assertFalse($ticket->isVisibleTo($this->make('staff', 'REG')));
    }

    public function test_requester_sees_only_their_own_tickets(): void
    {
        $other = $this->make('requester', 'FIN');
        $otherTicket = $this->ticket('FIN', ['requester_id' => $other->id]);

        $this->assertSame($this->ids(...array_values($this->tickets)), $this->scopeIds($this->requester));
        $this->assertSame($this->ids($otherTicket), $this->scopeIds($other));
        $this->actingAs($this->requester)->get(route('tickets.show', $this->tickets['REG']))->assertOk();
        $this->actingAs($other)->get(route('tickets.show', $this->tickets['FIN']))->assertNotFound();
        $this->actingAs($other)->get(route('tickets.show', $otherTicket))->assertOk();

        // A requester who happens to sit in the Registrar department still sees nothing of the office.
        $registrarRequester = $this->make('requester', 'REG');
        $this->assertSame([], $this->scopeIds($registrarRequester));
        $this->actingAs($registrarRequester)->get(route('tickets.show', $this->tickets['REG']))->assertNotFound();
    }

    public function test_system_admin_sees_every_ticket_in_every_department(): void
    {
        $confidential = $this->ticket('REG', ['is_confidential' => true]);
        $all = $this->ids(...array_values($this->tickets), ...[$confidential]);

        foreach ([null, 'FIN', 'MNT'] as $office) {
            $admin = $this->make('system_admin', $office);

            $this->assertSame($all, $this->scopeIds($admin), 'department: '.($office ?? 'none'));
            $this->assertSame($all, $this->listIds($admin));

            foreach ([...array_values($this->tickets), $confidential] as $ticket) {
                $this->assertTrue($ticket->isVisibleTo($admin));
                $this->assertTrue(Gate::forUser($admin)->allows('view', $ticket));
                $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertOk();
            }
        }
    }

    // ---- 404, never 403 -------------------------------------------------------------------------

    public function test_a_hidden_ticket_is_404_not_403_and_looks_like_a_ticket_that_does_not_exist(): void
    {
        $financeStaff = $this->make('staff', 'FIN');
        $hidden = $this->tickets['REG'];

        $hiddenResponse = $this->actingAs($financeStaff)->get(route('tickets.show', $hidden));
        $missingResponse = $this->actingAs($financeStaff)->get(route('tickets.show', 999999));

        $hiddenResponse->assertNotFound();
        $this->assertNotSame(403, $hiddenResponse->getStatusCode());
        $this->assertSame($missingResponse->getStatusCode(), $hiddenResponse->getStatusCode());
        $this->assertStringNotContainsString($hidden->ticket_no, $hiddenResponse->getContent());
        $this->assertStringNotContainsString($hidden->description, $hiddenResponse->getContent());

        // A POST route that carries {ticket} gives the same 404 (the binder runs before the policy).
        $this->actingAs($financeStaff)->post(route('tickets.replies.store', $hidden), ['body' => 'hello'])->assertNotFound();
    }

    // ---- edges ----------------------------------------------------------------------------------

    public function test_a_scoped_user_without_a_department_sees_no_department_tickets(): void
    {
        foreach (self::SCOPED_ROLES as $slug) {
            $user = $this->make($slug, null);

            $this->assertSame([], $this->scopeIds($user), $slug);
            foreach ($this->tickets as $ticket) {
                $this->assertFalse($ticket->isVisibleTo($user), $slug);
                $this->actingAs($user)->get(route('tickets.show', $ticket))->assertNotFound();
            }
        }
    }

    public function test_inactive_users_see_nothing(): void
    {
        $user = $this->make('staff', 'FIN', false);

        $this->assertSame([], $this->scopeIds($user));
        $this->assertFalse($this->tickets['FIN']->isVisibleTo($user));
    }

    public function test_own_and_assigned_tickets_stay_visible_whatever_the_department(): void
    {
        $staff = $this->make('staff', 'FIN');
        $registrarTicket = $this->tickets['REG'];
        $this->assertFalse($registrarTicket->isVisibleTo($staff));

        $registrarTicket->update(['assigned_to' => $staff->id]);

        $this->assertTrue($registrarTicket->refresh()->isVisibleTo($staff));
        $this->assertContains($registrarTicket->id, $this->scopeIds($staff));
        $this->actingAs($staff)->get(route('tickets.show', $registrarTicket))->assertOk();

        // A staff member's own ticket (as requester) is also visible, in any department.
        $own = $this->ticket('REG', ['requester_id' => $staff->id, 'department_id' => $this->dept('FIN')]);
        $this->assertTrue($own->isVisibleTo($staff));
        $this->assertContains($own->id, $this->scopeIds($staff));
    }

    public function test_confidential_tickets_still_need_view_confidential_inside_the_department(): void
    {
        $confidential = $this->ticket('FIN', ['is_confidential' => true]);

        $staff = $this->make('staff', 'FIN');
        $head = $this->make('head', 'FIN');
        $otherHead = $this->make('head', 'REG');

        $this->assertFalse($staff->can_do('view_confidential'));
        $this->assertFalse($confidential->isVisibleTo($staff));
        $this->actingAs($staff)->get(route('tickets.show', $confidential))->assertNotFound();

        $this->assertTrue($head->can_do('view_confidential'));
        $this->assertTrue($confidential->isVisibleTo($head));
        $this->actingAs($head)->get(route('tickets.show', $confidential))->assertOk();

        // view_confidential is not a way into another department.
        $this->assertFalse($confidential->isVisibleTo($otherHead));
        $this->actingAs($otherHead)->get(route('tickets.show', $confidential))->assertNotFound();
    }

    public function test_the_list_scope_and_the_single_ticket_rule_agree_for_every_role_and_department(): void
    {
        $confidential = $this->ticket('FIN', ['is_confidential' => true]);
        $tickets = [...array_values($this->tickets), $confidential];

        $roles = ['requester', 'staff', 'head', 'maintenance_admin', 'system_admin', 'finance', 'president', 'department_head', 'treasurer', 'custodian'];

        foreach ($roles as $slug) {
            foreach ([null, 'FIN', 'REG', 'MNT'] as $office) {
                $user = $this->make($slug, $office);
                $fromScope = $this->scopeIds($user);

                foreach ($tickets as $ticket) {
                    $this->assertSame(
                        in_array($ticket->id, $fromScope, true),
                        $ticket->isVisibleTo($user),
                        "$slug / ".($office ?? 'no department').' / ticket '.$ticket->id
                    );
                }
            }
        }
    }
}