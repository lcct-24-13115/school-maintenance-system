<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PWA: manifest, service worker, offline page and icons are in /public and wired into the pages. */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_links_the_manifest_and_the_pwa_script(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('manifest.webmanifest', false)
            ->assertSee('name="pwa-sw"', false)
            ->assertSee('js/pwa.js', false)
            ->assertSee('apple-mobile-web-app-title', false);
    }

    public function test_pwa_files_exist(): void
    {
        foreach (['manifest.webmanifest', 'sw.js', 'offline.html', 'js/pwa.js'] as $file) {
            $this->assertFileExists(public_path($file));
        }
    }

    public function test_manifest_is_valid_and_every_icon_exists(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertIsArray($manifest);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertNotEmpty($manifest['name']);
        $this->assertNotEmpty($manifest['start_url']);

        $sizes = collect($manifest['icons'])->pluck('sizes')->all();
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertContains('maskable', collect($manifest['icons'])->pluck('purpose')->all());

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path($icon['src']));
        }
    }

    public function test_offline_page_stands_alone(): void
    {
        $html = file_get_contents(public_path('offline.html'));

        $this->assertStringContainsString("You're offline", $html);
        // It is shown from the cache at any address, so no relative <img src> / <link href> / <script src> may be in it.
        $this->assertDoesNotMatchRegularExpression('/<img[^>]+src="(?!data:)/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<(link|script)[^>]+(href|src)=/i', $html);
    }

    public function test_service_worker_never_stores_pages(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("req.mode === 'navigate'", $sw);
        $this->assertStringContainsString('offline.html', $sw);
        $this->assertStringContainsString("req.method !== 'GET'", $sw);
    }
}
