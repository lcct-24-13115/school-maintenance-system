<?php

namespace Tests\Feature;

use App\Mail\AccountMail;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Account approval: a self-registered account is Pending, cannot sign in, and an admin approves or rejects it.
 * Accounts that already exist (and admin-made / seeded ones) are approved, so nothing that worked stops working.
 */
class AccountApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'juan@lcctanauan.edu.ph';

    private const PASSWORD = 'Sup3r-Secret-Pw!9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['school.allow_public_registration' => true]);
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(UserSeeder::class);
    }

    // ---- helpers --------------------------------------------------------------------------------

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function register(string $email = self::EMAIL): void
    {
        $this->post('/register', [
            'name' => 'Juan Dela Cruz', 'email' => $email,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'department_id' => Department::query()->value('id'),
        ]);
    }

    private function pendingUser(): User
    {
        $this->register();

        return User::where('email', self::EMAIL)->firstOrFail();
    }

    private function signIn(string $email = self::EMAIL, string $password = self::PASSWORD)
    {
        return $this->from('/login')->post('/login', ['email' => $email, 'password' => $password]);
    }

    // ---- registration ---------------------------------------------------------------------------

    public function test_a_new_registration_is_pending_and_is_not_signed_in(): void
    {
        $this->register();

        $this->assertGuest();
        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame(User::STATUS_PENDING, $user->approval_status);
        $this->assertTrue($user->isPending());
        $this->assertFalse($user->isApproved());
    }

    public function test_after_registering_the_person_is_sent_to_the_login_page_with_a_message(): void
    {
        $this->post('/register', [
            'name' => 'Juan Dela Cruz', 'email' => self::EMAIL,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->get(route('login'))->assertOk();
    }

    public function test_the_register_page_says_that_approval_is_needed(): void
    {
        $this->get('/register')->assertOk()->assertSee('must approve your account');

        config(['school.registration.require_approval' => false]);
        $this->get('/register')->assertOk()->assertDontSee('must approve your account');
    }

    public function test_with_approval_switched_off_the_account_works_at_once(): void
    {
        config(['school.registration.require_approval' => false]);

        $this->register();

        $this->assertAuthenticated();
        $this->assertSame(User::STATUS_APPROVED, User::where('email', self::EMAIL)->firstOrFail()->approval_status);
    }

    public function test_existing_seeded_and_factory_accounts_are_approved(): void
    {
        foreach (User::all() as $user) {
            $this->assertTrue($user->fresh()->isApproved(), $user->email);
        }

        $this->assertTrue(User::factory()->create()->fresh()->isApproved());
    }

    // ---- signing in -----------------------------------------------------------------------------

    public function test_a_pending_person_cannot_sign_in_and_is_told_why(): void
    {
        $this->pendingUser();

        $this->signIn()->assertRedirect('/login')->assertSessionHasErrors(['email' => 'Your account is waiting for approval by a Maintenance Admin. You will get an email when it is decided.']);
        $this->assertGuest();
    }

    public function test_a_wrong_password_does_not_reveal_that_the_account_is_pending(): void
    {
        $this->pendingUser();

        $response = $this->signIn(self::EMAIL, 'Not-the-password-1!');

        $response->assertSessionHasErrors('email');
        $this->assertStringNotContainsString('approval', session('errors')->first('email'));
        $this->assertStringContainsString('attempt', session('errors')->first('email'));   // the normal failed-sign-in rule
        $this->assertGuest();
    }

    public function test_a_rejected_person_sees_the_reason_when_signing_in(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($this->user('maintenance'))
            ->post(route('admin.users.reject', $user), ['reason' => 'Not a student of LCCT']);
        auth()->logout();

        $this->signIn()->assertSessionHasErrors('email');
        $this->assertStringContainsString('not approved', session('errors')->first('email'));
        $this->assertStringContainsString('Not a student of LCCT', session('errors')->first('email'));
        $this->assertGuest();
    }

    // ---- the decision ---------------------------------------------------------------------------

    public function test_an_admin_can_approve_and_the_person_can_then_sign_in(): void
    {
        $user = $this->pendingUser();
        $admin = $this->user('maintenance');

        $this->actingAs($admin)->post(route('admin.users.approve', $user))
            ->assertRedirect()->assertSessionHas('status');

        $user->refresh();
        $this->assertSame(User::STATUS_APPROVED, $user->approval_status);
        $this->assertSame($admin->id, (int) $user->decided_by);
        $this->assertNotNull($user->decided_at);

        auth()->logout();
        $this->signIn()->assertRedirect(route('requester.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_admin_can_reject_with_a_reason_and_later_change_their_mind(): void
    {
        $user = $this->pendingUser();
        $admin = $this->user('maintenance');

        $this->actingAs($admin)->post(route('admin.users.reject', $user), ['reason' => 'Unknown person']);

        $user->refresh();
        $this->assertSame(User::STATUS_REJECTED, $user->approval_status);
        $this->assertSame('Unknown person', $user->rejection_reason);

        $this->actingAs($admin)->post(route('admin.users.approve', $user));

        $user->refresh();
        $this->assertSame(User::STATUS_APPROVED, $user->approval_status);
        $this->assertNull($user->rejection_reason);
    }

    public function test_only_a_pending_account_can_be_rejected(): void
    {
        $requester = $this->user('requester');

        $this->actingAs($this->user('maintenance'))
            ->post(route('admin.users.reject', $requester))
            ->assertSessionHas('error');

        $this->assertTrue($requester->fresh()->isApproved());
    }

    public function test_only_the_maintenance_admin_may_decide(): void
    {
        $user = $this->pendingUser();

        foreach (['requester', 'finance', 'president', 'custodian'] as $name) {
            $this->actingAs($this->user($name))->post(route('admin.users.approve', $user))->assertForbidden();
            $this->actingAs($this->user($name))->post(route('admin.users.reject', $user))->assertForbidden();
        }

        $this->assertTrue($user->fresh()->isPending());
    }

    public function test_a_decision_is_written_to_the_audit_log_without_the_reason_text(): void
    {
        $approved = $this->pendingUser();
        $this->register('maria@lcctanauan.edu.ph');
        $rejected = User::where('email', 'maria@lcctanauan.edu.ph')->firstOrFail();
        $admin = $this->user('maintenance');

        $this->actingAs($admin)->post(route('admin.users.approve', $approved));
        $this->actingAs($admin)->post(route('admin.users.reject', $rejected), ['reason' => 'PRIVATE-REASON-TEXT']);

        $a = AuditLog::where('action', AuditLogger::ACCOUNT_APPROVED)->firstOrFail();
        $this->assertSame($admin->id, $a->user_id);
        $this->assertStringContainsString(self::EMAIL, $a->description);

        $r = AuditLog::where('action', AuditLogger::ACCOUNT_REJECTED)->firstOrFail();
        $this->assertStringContainsString('maria@lcctanauan.edu.ph', $r->description);
        $this->assertStringNotContainsString('PRIVATE-REASON-TEXT', json_encode($r->toArray()));
    }

    public function test_the_new_audit_actions_are_listed_and_fit_the_column(): void
    {
        foreach ([AuditLogger::ACCOUNT_APPROVED, AuditLogger::ACCOUNT_REJECTED] as $action) {
            $this->assertContains($action, AuditLogger::actions());
            $this->assertLessThanOrEqual(50, strlen($action));
        }
    }

    // ---- the Users page -------------------------------------------------------------------------

    public function test_the_users_page_lists_pending_people_in_their_own_panel(): void
    {
        $this->pendingUser();

        $page = $this->actingAs($this->user('maintenance'))->get(route('admin.users.index'));

        $page->assertOk()->assertSee('Waiting for approval')->assertSee(self::EMAIL)->assertSee('Approve');
    }

    public function test_the_panel_disappears_when_nobody_is_waiting(): void
    {
        $this->actingAs($this->user('maintenance'))->get(route('admin.users.index'))
            ->assertOk()->assertDontSee('Waiting for approval');
    }

    public function test_the_menu_shows_how_many_are_waiting_to_the_admin_only(): void
    {
        $this->pendingUser();

        $this->actingAs($this->user('maintenance'))->get(route('admin.users.index'))
            ->assertOk()->assertSee('Accounts waiting for approval');

        $this->actingAs($this->user('president'))->get(route('admin.audit-logs.index'))
            ->assertOk()->assertDontSee('Accounts waiting for approval');
    }

    public function test_a_rejected_person_is_shown_as_rejected_in_the_list(): void
    {
        $user = $this->pendingUser();
        $this->actingAs($this->user('maintenance'))->post(route('admin.users.reject', $user), ['reason' => 'x']);

        $this->actingAs($this->user('maintenance'))->get(route('admin.users.index'))
            ->assertOk()->assertSee('Rejected')->assertDontSee('Waiting for approval');
    }

    // ---- e-mails --------------------------------------------------------------------------------

    public function test_the_admins_are_emailed_about_a_new_registration(): void
    {
        Mail::fake();

        $this->register();

        Mail::assertQueued(AccountMail::class, fn (AccountMail $m) => $m->event === 'account_pending'
            && $m->hasTo('maintenance@lcctanauan.edu.ph')
            && str_contains((string) $m->data['intro'], 'Juan Dela Cruz'));
        Mail::assertNotQueued(AccountMail::class, fn (AccountMail $m) => $m->hasTo(self::EMAIL));
    }

    public function test_the_person_is_emailed_the_decision(): void
    {
        $user = $this->pendingUser();
        $maria = $this->pendingUserNamed('maria@lcctanauan.edu.ph');
        $admin = $this->user('maintenance');

        Mail::fake();

        $this->actingAs($admin)->post(route('admin.users.approve', $user));
        $this->actingAs($admin)->post(route('admin.users.reject', $maria), ['reason' => 'Unknown person']);

        Mail::assertQueued(AccountMail::class, fn (AccountMail $m) => $m->event === 'account_approved' && $m->hasTo(self::EMAIL));
        Mail::assertQueued(AccountMail::class, fn (AccountMail $m) => $m->event === 'account_rejected'
            && $m->hasTo('maria@lcctanauan.edu.ph') && $m->data['reason'] === 'Unknown person');
    }

    public function test_the_account_emails_can_be_switched_off(): void
    {
        config(['school.registration.account_emails' => false]);
        Mail::fake();

        $user = $this->pendingUser();
        $this->actingAs($this->user('maintenance'))->post(route('admin.users.approve', $user));

        Mail::assertNothingQueued();
        $this->assertTrue($user->fresh()->isApproved());   // the approval itself does not depend on e-mail
    }

    public function test_the_decision_email_renders_with_the_brand_layout(): void
    {
        $html = (new AccountMail('account_rejected', [
            'subject' => 'S', 'headline' => 'Your registration was not approved', 'intro' => 'Intro text.', 'name' => 'Juan',
            'details' => [], 'reason' => 'Because', 'url' => null, 'button' => null,
        ]))->render();

        $this->assertStringContainsString(config('mail.brand.name'), $html);
        $this->assertStringContainsString('Your registration was not approved', $html);
        $this->assertStringContainsString('Reason: Because', $html);
    }

    private function pendingUserNamed(string $email): User
    {
        $this->register($email);

        return User::where('email', $email)->firstOrFail();
    }
}
