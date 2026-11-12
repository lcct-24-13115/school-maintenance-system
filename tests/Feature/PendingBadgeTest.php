<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Live badge on the "Users" menu item: /admin/users/pending-count.
class PendingBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'school.allow_public_registration' => true,
            'school.registration.require_approval' => true,   // the local .env may switch this off
        ]);
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function register(string $email): void
    {
        auth()->logout();   // a new person registers from another browser, not while the admin is signed in
        $this->post('/register', [
            'name' => 'New Person', 'email' => $email, 'requester_type' => 'STUDENT',
            'password' => 'Sup3r-Secret-Pw!9', 'password_confirmation' => 'Sup3r-Secret-Pw!9',
        ])->assertRedirect('/login');
    }

    private function admin(): User
    {
        return User::where('email', 'maintenance@lcctanauan.edu.ph')->firstOrFail();
    }

    public function test_admin_gets_the_number_of_pending_accounts(): void
    {
        $this->actingAs($this->admin())->getJson(route('admin.users.pending-count'))->assertOk()->assertExactJson(['count' => 0]);

        $this->register('one@lcctanauan.edu.ph');
        $this->register('two@lcctanauan.edu.ph');

        $this->actingAs($this->admin())->getJson(route('admin.users.pending-count'))->assertOk()->assertExactJson(['count' => 2]);
    }

    public function test_the_menu_badge_is_in_the_page_and_hidden_when_nobody_waits(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.users.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="pending-users-badge"[^>]*display:none/s', $html);
    }

    public function test_the_menu_badge_shows_the_number_when_someone_waits(): void
    {
        $this->register('one@lcctanauan.edu.ph');

        $html = $this->actingAs($this->admin())->get(route('admin.users.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="pending-users-badge"[^>]*style="margin-left:auto;"[^>]*>\s*1\s*</s', $html);
    }

    public function test_other_roles_and_guests_cannot_use_it(): void
    {
        $this->getJson(route('admin.users.pending-count'))->assertUnauthorized();

        $requester = User::where('email', 'requester@lcctanauan.edu.ph')->firstOrFail();
        $this->actingAs($requester)->getJson(route('admin.users.pending-count'))->assertForbidden();
    }
}