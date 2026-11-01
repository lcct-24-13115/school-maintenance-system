<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 18: branding and UI polish is wired into the pages. */
class UiPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_has_seal_fonts_and_ui_scripts(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('images/lcct-logo.png', false)
            ->assertSee('family=Fraunces', false)
            ->assertSee('family=Nunito+Sans', false)
            ->assertSee('css/phase6.css', false)
            ->assertSee('js/pwtoggle.js', false)
            ->assertSee('js/cselect.js', false)
            ->assertSee('images/apple-touch-icon.png', false);
    }

    public function test_login_page_no_longer_has_its_own_password_toggle(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('togglePassword', false);
    }

    public function test_ui_files_exist(): void
    {
        foreach (['js/pwtoggle.js', 'js/cselect.js', 'css/phase6.css', 'images/lcct-logo.png', 'images/favicon-32.png', 'images/apple-touch-icon.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }
    }
}
