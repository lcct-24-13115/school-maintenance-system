<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\RequisitionSlip;
use App\Models\Role;
use App\Models\User;
use App\Rules\AdvanceNotice;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Form 2: Requisition Slip (Form 1-08): filing, 3 working days rule, signature chain, visibility, close. */
class RequisitionSlipTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->dept = Department::query()->orderBy('id')->firstOrFail();
    }

    private function make(string $slug, ?int $departmentId = null, string $tag = 'a'): User
    {
        return User::updateOrCreate(['email' => "{$slug}-{$tag}-req@lcctanauan.edu.ph"], [
            'name' => "Test {$slug} {$tag}",
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'department_id' => $departmentId,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'department_id' => $this->dept->id,
            'date_needed' => AdvanceNotice::earliest('requisition')->toDateString(),
            'request_types' => ['supplies', 'cash'],
            'payee_name' => 'Juan Dela Cruz',
            'remarks' => 'Urgent',
            'items' => [
                ['quantity' => 2, 'description' => 'Bond paper', 'account_charged' => 'Office supplies', 'amount' => '250.50'],
                ['quantity' => 1, 'description' => 'Ink', 'account_charged' => '', 'amount' => '100'],
            ],
        ], $over);
    }

    private function file(User $requester): RequisitionSlip
    {
        $this->actingAs($requester)->post(route('requisitions.store'), $this->payload())->assertSessionHasNoErrors();

        return RequisitionSlip::latest('id')->firstOrFail();
    }

    public function test_filing_saves_the_slip_items_total_and_the_signature_lines(): void
    {
        $slip = $this->file($this->make(Role::REQUESTER, $this->dept->id));

        $this->assertStringStartsWith('RS-', $slip->reference_no);
        $this->assertSame('submitted', $slip->status);
        $this->assertSame('350.50', (string) $slip->total_amount);
        $this->assertCount(2, $slip->items);
        $this->assertSame(['requested', 'noted', 'recommended', 'president'], $slip->approvals->pluck('step')->all());
    }

    public function test_date_needed_must_respect_three_working_days(): void
    {
        $user = $this->make(Role::REQUESTER, $this->dept->id);

        $this->actingAs($user)
            ->post(route('requisitions.store'), $this->payload(['date_needed' => now()->toDateString()]))
            ->assertSessionHasErrors('date_needed');

        $this->assertSame(0, RequisitionSlip::count());
    }

    public function test_others_needs_a_description_and_at_least_one_item(): void
    {
        $user = $this->make(Role::REQUESTER, $this->dept->id);

        $this->actingAs($user)->post(route('requisitions.store'), $this->payload(['request_types' => ['others']]))
            ->assertSessionHasErrors('other_type');
        $this->actingAs($user)->post(route('requisitions.store'), $this->payload(['items' => []]))
            ->assertSessionHasErrors('items');
    }

    public function test_full_chain_then_close(): void
    {
        $slip = $this->file($this->make(Role::REQUESTER, $this->dept->id));
        $oic = $this->make(Role::DEPARTMENT_HEAD, $this->dept->id);
        $finance = $this->make(Role::FINANCE);
        $president = $this->make(Role::PRESIDENT);

        // not allowed to close before the President signs
        $this->actingAs($finance)->post(route('requisitions.close', $slip))->assertSessionHas('error');

        $this->actingAs($oic)->post(route('requisitions.sign', $slip))->assertSessionHas('status');
        $this->assertSame('noted', $slip->refresh()->status);

        $this->actingAs($finance)->post(route('requisitions.sign', $slip))->assertSessionHas('status');
        $this->assertSame('recommended', $slip->refresh()->status);

        $this->actingAs($president)->post(route('requisitions.sign', $slip))->assertSessionHas('status');
        $this->assertSame('approved', $slip->refresh()->status);

        $this->actingAs($finance)->post(route('requisitions.close', $slip))->assertSessionHas('status');
        $this->assertSame('closed', $slip->refresh()->status);
    }

    public function test_only_the_right_person_can_sign_and_a_reason_is_needed_to_disapprove(): void
    {
        $slip = $this->file($this->make(Role::REQUESTER, $this->dept->id));
        $president = $this->make(Role::PRESIDENT);

        // the President cannot skip the OIC line
        $this->actingAs($president)->post(route('requisitions.sign', $slip))->assertSessionHas('error');
        $this->assertSame('submitted', $slip->refresh()->status);

        $oic = $this->make(Role::DEPARTMENT_HEAD, $this->dept->id);
        $this->actingAs($oic)->post(route('requisitions.reject', $slip), [])->assertSessionHasErrors('reason');
        $this->actingAs($oic)->post(route('requisitions.reject', $slip), ['reason' => 'No budget'])->assertSessionHas('status');
        $this->assertSame('rejected', $slip->refresh()->status);
    }

    public function test_visibility_and_pages(): void
    {
        $owner = $this->make(Role::REQUESTER, $this->dept->id, 'owner');
        $other = $this->make(Role::REQUESTER, $this->dept->id, 'other');
        $slip = $this->file($owner);

        $this->actingAs($owner)->get(route('requisitions.index'))->assertOk()->assertSee($slip->reference_no);
        $this->actingAs($owner)->get(route('requisitions.show', $slip))->assertOk();
        $this->actingAs($owner)->get(route('requisitions.print', $slip))->assertOk()->assertSee('REQUISITION SLIP');
        $this->actingAs($owner)->get(route('requisitions.create'))->assertOk();

        // someone else's slip is hidden (404); the President sees everything
        $this->actingAs($other)->get(route('requisitions.show', $slip))->assertNotFound();
        $this->actingAs($this->make(Role::PRESIDENT))->get(route('requisitions.show', $slip))->assertOk();
    }
}
