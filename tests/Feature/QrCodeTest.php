<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\QrCode;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 17: QR codes admin (manage_qr_codes): make, edit, print, delete; links from APP_URL; scannable colours. */
class QrCodeTest extends TestCase
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

        config(['app.url' => 'https://school.example.com']);
    }

    // ---- helpers --------------------------------------------------------------------------------

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function admin(): User
    {
        return $this->user('maintenance');
    }

    /** A saved QR code (written straight to the table, so no audit row yet). */
    private function qr(array $overrides = []): QrCode
    {
        return QrCode::create($overrides + [
            'title' => 'Gate poster',
            'kind' => 'login',
            'custom_url' => null,
            'color' => '#1b3355',
            'created_by' => $this->admin()->id,
        ]);
    }

    /** What the "make" form sends. */
    private function form(array $overrides = []): array
    {
        return $overrides + ['title' => 'Front gate', 'kind' => 'login', 'color' => '#1b3355'];
    }

    private function auditCount(): int
    {
        return AuditLog::where('action', AuditLogger::QR_CODE_CHANGED)->count();
    }

    // ---- access ---------------------------------------------------------------------------------

    public function test_only_the_maintenance_admin_can_use_the_qr_pages(): void
    {
        $qr = $this->qr();

        $this->actingAs($this->admin())->get(route('admin.qr-codes.index'))->assertOk()->assertSee('Make a QR code');
        $this->assertTrue($this->admin()->can_do('manage_qr_codes'));

        foreach (['requester', 'finance', 'president', 'custodian', 'depthead', 'treasurer'] as $name) {
            $user = $this->user($name);
            $this->assertFalse($user->can_do('manage_qr_codes'), $name);

            $this->actingAs($user)->get(route('admin.qr-codes.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.qr-codes.store'), $this->form(['title' => 'Nope']))->assertForbidden();
            $this->actingAs($user)->get(route('admin.qr-codes.print', $qr))->assertForbidden();
            $this->actingAs($user)->put(route('admin.qr-codes.update', $qr), $this->form(['title' => 'Nope']))->assertForbidden();
            $this->actingAs($user)->delete(route('admin.qr-codes.destroy', $qr))->assertForbidden();
        }

        $this->assertSame(1, QrCode::count());
        $this->assertSame('Gate poster', $qr->fresh()->title);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('admin.qr-codes.index'))->assertRedirect(route('login'));
    }

    public function test_the_sidebar_link_is_for_the_admin_only(): void
    {
        // (maintenance.dashboard only redirects to the board, so the admin's sidebar is read from the QR page itself)
        $this->actingAs($this->admin())->get(route('admin.qr-codes.index'))->assertOk()->assertSee(route('admin.qr-codes.index'));
        $this->actingAs($this->user('finance'))->get(route('finance.dashboard'))->assertOk()->assertDontSee('/admin/qr-codes');
    }

    // ---- make -----------------------------------------------------------------------------------

    public function test_a_login_qr_is_made_and_points_at_app_url(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.qr-codes.store'), $this->form(['title' => '  Front gate  ']))
            ->assertSessionHasNoErrors();

        $qr = QrCode::firstOrFail();
        $this->assertSame('Front gate', $qr->title);
        $this->assertSame('login', $qr->kind);
        $this->assertNull($qr->custom_url);
        $this->assertSame($this->admin()->id, $qr->created_by);
        $this->assertSame('https://school.example.com/login', $qr->url);
        $this->assertSame(1, $this->auditCount());
    }

    public function test_each_built_in_page_has_its_own_path(): void
    {
        $this->assertSame('https://school.example.com/login', (new QrCode(['kind' => 'login']))->url);
        $this->assertSame('https://school.example.com/register', (new QrCode(['kind' => 'register']))->url);
        $this->assertSame('https://school.example.com/repairs/create', (new QrCode(['kind' => 'repair']))->url);
    }

    public function test_the_built_in_links_follow_app_url_when_it_changes(): void
    {
        $qr = $this->qr(['kind' => 'repair']);
        $this->assertSame('https://school.example.com/repairs/create', $qr->url);

        config(['app.url' => 'https://repairs.lcct.edu.ph/']);
        $this->assertSame('https://repairs.lcct.edu.ph/repairs/create', $qr->fresh()->url);
    }

    public function test_a_custom_qr_keeps_the_link_as_typed(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.qr-codes.store'), $this->form(['kind' => 'custom', 'custom_url' => 'https://example.com/form?id=7']))
            ->assertSessionHasNoErrors();

        $qr = QrCode::firstOrFail();
        $this->assertSame('https://example.com/form?id=7', $qr->custom_url);
        $this->assertSame('https://example.com/form?id=7', $qr->url);
    }

    public function test_a_custom_qr_needs_a_real_web_link(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.qr-codes.store'), $this->form(['kind' => 'custom']))
            ->assertSessionHasErrors('custom_url');

        foreach (['javascript:alert(1)', 'ftp://files.example.com/a', 'not a link', 'example.com'] as $bad) {
            $this->actingAs($admin)->post(route('admin.qr-codes.store'), $this->form(['kind' => 'custom', 'custom_url' => $bad]))
                ->assertSessionHasErrors('custom_url');
        }

        $this->assertSame(0, QrCode::count());
    }

    public function test_a_built_in_qr_ignores_a_leftover_custom_link(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.qr-codes.store'), $this->form(['kind' => 'login', 'custom_url' => 'https://other.example.com']))
            ->assertSessionHasNoErrors();

        $qr = QrCode::firstOrFail();
        $this->assertNull($qr->custom_url);
        $this->assertSame('https://school.example.com/login', $qr->url);
    }

    public function test_title_and_kind_are_checked(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.qr-codes.store'), $this->form(['title' => '']))->assertSessionHasErrors('title');
        $this->actingAs($admin)->post(route('admin.qr-codes.store'), $this->form(['title' => str_repeat('a', 101)]))->assertSessionHasErrors('title');
        $this->actingAs($admin)->post(route('admin.qr-codes.store'), $this->form(['kind' => 'nonsense']))->assertSessionHasErrors('kind');

        $this->assertSame(0, QrCode::count());
    }

    // ---- colour ---------------------------------------------------------------------------------

    public function test_the_contrast_helper_matches_the_standard_formula(): void
    {
        $this->assertEqualsWithDelta(1.0, QrCode::contrast('#ffffff'), 0.001);
        $this->assertEqualsWithDelta(21.0, QrCode::contrast('#000000'), 0.001);
        $this->assertGreaterThan(QrCode::MIN_CONTRAST, QrCode::contrast(QrCode::DEFAULT_COLOR));
        $this->assertLessThan(QrCode::MIN_CONTRAST, QrCode::contrast('#ffff00'));
    }

    public function test_a_colour_too_light_to_scan_is_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.qr-codes.store'), $this->form(['color' => '#ffff00']))->assertSessionHasErrors('color');
        $this->actingAs($admin)->post(route('admin.qr-codes.store'), $this->form(['color' => '#f5f9fd']))->assertSessionHasErrors('color');

        $this->assertSame(0, QrCode::count());
    }

    public function test_the_colour_must_be_a_hex_code_and_is_saved_in_lower_case(): void
    {
        $admin = $this->admin();

        foreach (['red', '1b3355', '#12345', '#gggggg', ''] as $bad) {
            $this->actingAs($admin)->post(route('admin.qr-codes.store'), $this->form(['color' => $bad]))->assertSessionHasErrors('color');
        }

        $this->actingAs($admin)->post(route('admin.qr-codes.store'), $this->form(['color' => '#5E94CF']))->assertSessionHasNoErrors();
        $this->assertSame('#5e94cf', QrCode::firstOrFail()->color);
    }

    // ---- register -------------------------------------------------------------------------------

    public function test_a_register_qr_cannot_be_made_while_registration_is_closed(): void
    {
        config(['school.allow_public_registration' => false]);

        $this->actingAs($this->admin())->post(route('admin.qr-codes.store'), $this->form(['kind' => 'register']))
            ->assertSessionHasErrors('kind');
        $this->assertSame(0, QrCode::count());

        config(['school.allow_public_registration' => true]);

        $this->actingAs($this->admin())->post(route('admin.qr-codes.store'), $this->form(['kind' => 'register']))
            ->assertSessionHasNoErrors();
        $this->assertSame('https://school.example.com/register', QrCode::firstOrFail()->url);
    }

    public function test_an_old_register_qr_can_still_be_edited_after_registration_closes(): void
    {
        config(['school.allow_public_registration' => false]);
        $qr = $this->qr(['kind' => 'register', 'title' => 'Old poster']);

        $this->actingAs($this->admin())
            ->put(route('admin.qr-codes.update', $qr), $this->form(['kind' => 'register', 'title' => 'New title']))
            ->assertSessionHasNoErrors();

        $this->assertSame('New title', $qr->fresh()->title);
    }

    // ---- edit / delete --------------------------------------------------------------------------

    public function test_a_qr_can_be_edited_and_the_change_is_audited(): void
    {
        $qr = $this->qr();

        $this->actingAs($this->admin())
            ->put(route('admin.qr-codes.update', $qr), $this->form(['title' => 'Lobby', 'kind' => 'custom', 'custom_url' => 'https://example.com/lobby', 'color' => '#2F5D95']))
            ->assertSessionHasNoErrors();

        $qr->refresh();
        $this->assertSame('Lobby', $qr->title);
        $this->assertSame('custom', $qr->kind);
        $this->assertSame('https://example.com/lobby', $qr->url);
        $this->assertSame('#2f5d95', $qr->color);
        $this->assertSame(1, $this->auditCount());
    }

    public function test_saving_without_changes_writes_no_audit_row(): void
    {
        $qr = $this->qr();

        $this->actingAs($this->admin())
            ->put(route('admin.qr-codes.update', $qr), $this->form(['title' => 'Gate poster']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->auditCount());
    }

    public function test_a_bad_edit_changes_nothing(): void
    {
        $qr = $this->qr();

        $this->actingAs($this->admin())
            ->put(route('admin.qr-codes.update', $qr), $this->form(['title' => 'Changed', 'color' => '#ffff00']))
            ->assertSessionHasErrors('color');

        $this->assertSame('Gate poster', $qr->fresh()->title);
    }

    public function test_a_qr_can_be_deleted_and_it_is_audited(): void
    {
        $qr = $this->qr();

        $this->actingAs($this->admin())->delete(route('admin.qr-codes.destroy', $qr))->assertSessionHas('status');

        $this->assertDatabaseMissing('qr_codes', ['id' => $qr->id]);
        $this->assertSame(1, $this->auditCount());
    }

    // ---- pages ----------------------------------------------------------------------------------

    public function test_the_list_gives_the_browser_each_link_to_draw(): void
    {
        $a = $this->qr(['title' => 'Gate poster']);
        $b = $this->qr(['title' => 'Repair desk', 'kind' => 'repair']);

        $this->actingAs($this->admin())->get(route('admin.qr-codes.index'))
            ->assertOk()
            ->assertSee('Gate poster')
            ->assertSee('Repair desk')
            ->assertSee('data-qr="'.$a->url.'"', false)
            ->assertSee('data-qr="'.$b->url.'"', false)
            ->assertSee('data-qr-color="#1b3355"', false);
    }

    public function test_the_list_says_so_when_there_are_no_codes(): void
    {
        $this->actingAs($this->admin())->get(route('admin.qr-codes.index'))->assertOk()->assertSee('No QR codes yet');
    }

    public function test_the_page_warns_when_app_url_is_not_reachable_from_a_phone(): void
    {
        foreach (['http://localhost', 'http://127.0.0.1:8000', 'http://school-maintenance-system.test'] as $local) {
            config(['app.url' => $local]);
            $this->actingAs($this->admin())->get(route('admin.qr-codes.index'))->assertOk()->assertSee('cannot open that address');
        }

        config(['app.url' => 'https://school.example.com']);
        $this->actingAs($this->admin())->get(route('admin.qr-codes.index'))->assertOk()->assertDontSee('cannot open that address');
    }

    public function test_the_register_choice_is_switched_off_while_registration_is_closed(): void
    {
        config(['school.allow_public_registration' => false]);
        $this->actingAs($this->admin())->get(route('admin.qr-codes.index'))->assertOk()->assertSee('registration is closed');

        config(['school.allow_public_registration' => true]);
        $this->actingAs($this->admin())->get(route('admin.qr-codes.index'))->assertOk()->assertDontSee('registration is closed');
    }

    public function test_the_print_page_shows_the_title_and_the_link(): void
    {
        $qr = $this->qr(['title' => 'Main entrance', 'kind' => 'repair', 'color' => '#2f5d95']);

        $this->actingAs($this->admin())->get(route('admin.qr-codes.print', $qr))
            ->assertOk()
            ->assertSee('Main entrance')
            ->assertSee('data-qr="https://school.example.com/repairs/create"', false)
            ->assertSee('data-qr-color="#2f5d95"', false)
            ->assertSee('Download SVG');
    }

    public function test_the_print_page_of_a_missing_code_is_404(): void
    {
        $this->actingAs($this->admin())->get(route('admin.qr-codes.print', 9999))->assertNotFound();
    }

    // ---- the library, the audit action ----------------------------------------------------------

    public function test_the_qr_is_drawn_by_the_npm_package_not_a_copied_script(): void
    {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true);

        $this->assertArrayHasKey('qrcode', $package['dependencies']);
        $this->assertStringContainsString("from 'qrcode'", (string) file_get_contents(resource_path('js/qr.js')));
        $this->assertStringContainsString("import('./qr')", (string) file_get_contents(resource_path('js/app.js')));
        $this->assertFileDoesNotExist(public_path('js/qrcode.js'));
    }

    public function test_the_audit_action_is_listed_for_the_audit_page_filter(): void
    {
        $this->assertContains(AuditLogger::QR_CODE_CHANGED, AuditLogger::actions());
    }
}
