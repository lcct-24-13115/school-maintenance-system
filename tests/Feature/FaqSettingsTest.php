<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Help & FAQ Phase 5C: the two texts of the "Still need help?" box (office hours, contact e-mail) on /admin/settings.
 * Capability manage_settings, validation, and where the texts show up.
 */
class FaqSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_ROLES = ['requester', 'custodian', 'finance', 'president', 'depthead', 'treasurer'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(FacilitySeeder::class);
        $this->seed(UserSeeder::class);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'sla_hours_routine' => Setting::int('sla_hours_routine'),
            'sla_hours_emergency' => Setting::int('sla_hours_emergency'),
            'reopen_window_days' => Setting::int('reopen_window_days'),
        ];
    }

    private function save(array $extra)
    {
        return $this->actingAs($this->user('maintenance'))->put(route('admin.settings.update'), $this->payload($extra));
    }

    private function defaultHours(): string
    {
        return (string) config('school.settings_defaults.help_office_hours');
    }

    private function defaultEmail(): string
    {
        return (string) config('school.settings_defaults.help_contact_email');
    }

    // ---------- who may change them ----------

    public function test_only_manage_settings_may_open_or_save_the_texts(): void
    {
        foreach (self::OTHER_ROLES as $who) {
            $this->actingAs($this->user($who));

            $this->get(route('admin.settings.edit'))->assertForbidden();
            $this->put(route('admin.settings.update'), $this->payload([
                'help_office_hours' => 'Changed by '.$who,
                'help_contact_email' => $who.'@example.test',
            ]))->assertForbidden();
        }

        $this->assertSame($this->defaultHours(), Setting::get('help_office_hours'));
        $this->assertSame($this->defaultEmail(), Setting::get('help_contact_email'));
        $this->assertSame(0, AuditLog::where('action', AuditLogger::SETTINGS_UPDATED)->count());
    }

    public function test_a_visitor_is_sent_to_login_from_the_settings_page(): void
    {
        $this->get(route('admin.settings.edit'))->assertRedirect(route('login'));
        $this->put(route('admin.settings.update'), $this->payload(['help_office_hours' => 'x']))->assertRedirect(route('login'));
    }

    // ---------- validation ----------

    public function test_office_hours_may_be_at_most_200_characters(): void
    {
        $this->save(['help_office_hours' => str_repeat('a', 200)])->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('a', 200), Setting::get('help_office_hours'));

        $this->save(['help_office_hours' => str_repeat('b', 201)])->assertSessionHasErrors('help_office_hours');
        $this->assertSame(str_repeat('a', 200), Setting::get('help_office_hours'));   // the refused one was not saved
    }

    public function test_office_hours_must_be_plain_text(): void
    {
        foreach (['<b>Mon</b> to Fri', 'Mon <script>alert(1)</script>', "Mon\nFri", "Mon\x07Fri"] as $bad) {
            $this->save(['help_office_hours' => $bad])->assertSessionHasErrors('help_office_hours');
        }

        $this->assertSame($this->defaultHours(), Setting::get('help_office_hours'));
    }

    public function test_the_contact_email_must_be_a_real_address_of_at_most_120_characters(): void
    {
        foreach (['not an email', 'x@@example.test', 'plain', str_repeat('a', 130).'@example.test'] as $bad) {
            $this->save(['help_contact_email' => $bad])->assertSessionHasErrors('help_contact_email');
        }

        $this->assertSame($this->defaultEmail(), Setting::get('help_contact_email'));

        $this->save(['help_contact_email' => 'help@example.test'])->assertSessionHasNoErrors();
        $this->assertSame('help@example.test', Setting::get('help_contact_email'));
    }

    public function test_one_bad_field_saves_nothing_at_all(): void
    {
        $this->save(['help_office_hours' => 'Good hours', 'help_contact_email' => 'nope'])
            ->assertSessionHasErrors('help_contact_email');

        $this->assertSame($this->defaultHours(), Setting::get('help_office_hours'));
        $this->assertSame($this->defaultEmail(), Setting::get('help_contact_email'));
    }

    public function test_the_email_is_saved_in_lower_case(): void
    {
        $this->save(['help_contact_email' => 'Help@Example.TEST'])->assertSessionHasNoErrors();

        $this->assertSame('help@example.test', Setting::get('help_contact_email'));
    }

    public function test_blank_fields_go_back_to_the_standard_text(): void
    {
        Setting::put('help_office_hours', 'Custom hours');
        Setting::put('help_contact_email', 'custom@example.test');

        $this->save(['help_office_hours' => '', 'help_contact_email' => ''])->assertSessionHasNoErrors();

        $this->assertSame($this->defaultHours(), Setting::get('help_office_hours'));
        $this->assertSame($this->defaultEmail(), Setting::get('help_contact_email'));
    }

    // ---------- where they show ----------

    public function test_the_saved_texts_show_in_the_help_box_for_a_signed_in_person_and_for_a_visitor(): void
    {
        $this->save([
            'help_office_hours' => 'Tuesday to Saturday, 10 AM to 3 PM',
            'help_contact_email' => 'desk@example.test',
        ])->assertSessionHasNoErrors();

        // the visitor first (a later actingAs would keep the person signed in)
        $this->app['auth']->forgetGuards();
        $this->get('/help-public')->assertOk()
            ->assertSee('Tuesday to Saturday, 10 AM to 3 PM')
            ->assertSee('href="mailto:desk@example.test"', false);

        $this->actingAs($this->user('requester'))->get('/help')->assertOk()
            ->assertSee('Tuesday to Saturday, 10 AM to 3 PM')
            ->assertSee('href="mailto:desk@example.test"', false);
    }

    // ---------- audit ----------

    public function test_a_change_is_written_to_the_audit_log_and_no_change_is_not(): void
    {
        $this->save(['help_office_hours' => 'New hours'])->assertSessionHasNoErrors();

        $row = AuditLog::where('action', AuditLogger::SETTINGS_UPDATED)->latest('id')->firstOrFail();
        $this->assertSame([$this->defaultHours(), 'New hours'], $row->meta['changes']['help_office_hours']);

        $before = AuditLog::where('action', AuditLogger::SETTINGS_UPDATED)->count();
        $this->save(['help_office_hours' => 'New hours'])->assertSessionHasNoErrors();   // same value again

        $this->assertSame($before, AuditLog::where('action', AuditLogger::SETTINGS_UPDATED)->count());
    }
}