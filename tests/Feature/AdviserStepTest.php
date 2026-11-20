<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\TicketWorkflow;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Student files -> the student's adviser (teacher) -> Vice Principal -> Finance -> President -> Head of Maintenance.
 * Written without being able to run PHP in the build environment: run `php artisan test --filter=AdviserStepTest`.
 */
class AdviserStepTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $slug, array $extra = []): User
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);

        return User::factory()->create($extra + [
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function teacher(): User
    {
        return $this->user(Role::TEACHER, ['email' => 'teacher'.uniqid().'@lcctanauan.edu.ph']);
    }

    private function student(?User $adviser): User
    {
        return $this->user(Role::REQUESTER, [
            'email' => random_int(20, 29).'-'.random_int(10000, 99999).'@lcctanauan.edu.ph',
            'adviser_id' => $adviser?->id,
        ]);
    }

    private function file(User $by): MaintenanceRequest
    {
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $this->seed(\Database\Seeders\CategorySeeder::class);

        return TicketWorkflow::open($by, [
            'department_id' => Department::maintenanceId(),
            'category_id' => Category::query()->value('id'),
            'location' => 'Grade 5 Room 204',
            'description' => 'Leaking faucet',
            'reason' => 'Water on the floor',
        ]);
    }

    public function test_a_student_without_an_adviser_cannot_file(): void
    {
        $this->expectException(DomainException::class);
        $this->file($this->student(null));
    }

    public function test_a_students_complaint_starts_with_the_adviser(): void
    {
        $adviser = $this->teacher();
        $ticket = $this->file($this->student($adviser));

        $this->assertSame(['adviser', 'vice_principal', 'finance', 'president'], $ticket->approvals->pluck('step')->all());
        $this->assertSame($adviser->id, $ticket->currentApproval()->approver_id);
        $this->assertTrue($adviser->fresh()->can('authorize', $ticket));
    }

    public function test_only_that_students_adviser_may_sign_and_the_vice_principal_cannot_skip_ahead(): void
    {
        $adviser = $this->teacher();
        $otherTeacher = $this->teacher();
        $this->student($otherTeacher);   // another adviser, with a student of his own
        $ticket = $this->file($this->student($adviser));
        $vp = $this->user(Role::VICE_PRINCIPAL);

        $this->assertFalse($otherTeacher->fresh()->can('authorize', $ticket));
        $this->assertFalse($vp->can('authorize', $ticket));
        $this->assertFalse($ticket->isVisibleTo($otherTeacher->fresh()));

        $this->expectException(DomainException::class);
        TicketWorkflow::authorize($ticket, $vp, 'skipping the teacher');
    }

    public function test_after_the_adviser_signs_it_is_the_vice_principals_turn(): void
    {
        $adviser = $this->teacher();
        $ticket = $this->file($this->student($adviser));
        $vp = $this->user(Role::VICE_PRINCIPAL);

        $ticket = TicketWorkflow::authorize($ticket, $adviser);

        $this->assertNull($ticket->president_approved_at);
        $this->assertSame('vice_principal', $ticket->currentApproval()->step);
        $this->assertTrue($vp->can('authorize', $ticket));
        $this->assertFalse($adviser->fresh()->can('authorize', $ticket));
    }

    public function test_a_teachers_own_request_skips_the_adviser_step(): void
    {
        $ticket = $this->file($this->teacher());

        $this->assertSame(['vice_principal', 'finance', 'president'], $ticket->approvals->pluck('step')->all());
    }

    public function test_if_the_adviser_disapproves_the_chain_stops(): void
    {
        $adviser = $this->teacher();
        $ticket = $this->file($this->student($adviser));

        $ticket = TicketWorkflow::disapprove($ticket, $adviser, 'Not urgent, use the other faucet.');

        $this->assertSame('rejected', $ticket->approvals->first()->status);
        $this->assertNull($ticket->currentApproval());
    }
}
