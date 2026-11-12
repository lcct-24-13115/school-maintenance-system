<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Preview of the chosen files before sending (thumbnail + X). The behaviour itself is Alpine/JS; here we check the markup is on every page that has the picker. */
class FilePickerPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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

    private function requester(): User
    {
        return User::where('email', 'requester@lcctanauan.edu.ph')->firstOrFail();
    }

    public function test_the_new_request_form_has_the_preview_list_and_the_viewer(): void
    {
        $html = $this->actingAs($this->requester())->get(route('repairs.create'))
            ->assertOk()
            ->assertSee('class="fp-list"', false)
            ->assertSee('data-file-view', false)
            ->assertSee('class="fp-x"', false)
            ->assertSee('id="file-viewer"', false)
            ->assertSee('js/file-viewer.js', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'id="file-viewer"'));
        $this->assertSame(1, substr_count($html, 'js/file-viewer.js'));
    }

    public function test_the_ticket_page_still_has_only_one_viewer_when_it_also_has_a_picker(): void
    {
        $requester = $this->requester();
        $ticket = \App\Services\TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => Category::where('type', 'materials')->orderBy('id')->firstOrFail()->id,
            'location' => 'Library',
            'description' => 'Leaking roof',
            'is_confidential' => false,
        ], [UploadedFile::fake()->createWithContent('photo.png', base64_decode(self::PNG))]);

        $html = $this->actingAs($requester)->get(route('tickets.show', $ticket))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="file-viewer"'));
        $this->assertSame(1, substr_count($html, 'js/file-viewer.js'));
        $this->assertStringContainsString('class="fp-list"', $html);
    }
}
