<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\RequestAttachment;
use App\Models\RequestStatusLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocxPreview;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Pop-up file viewer (markup + Word text preview) and removing an uploaded file (policy + audit log). */
class AttachmentViewerTest extends TestCase
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

    // ---- helpers --------------------------------------------------------------------------------

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('photo.png', base64_decode(self::PNG));
    }

    private function docx(string $name = 'letter.docx'): UploadedFile
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not enabled.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types/>');
        $zip->addFromString('word/document.xml', '<w:document><w:body>'
            .'<w:p><w:r><w:t>Hello Maria &amp; Juan</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t xml:space="preserve">Second </w:t></w:r><w:r><w:t>line</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();
        $bytes = file_get_contents($tmp);
        unlink($tmp);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function open(User $requester, array $files): MaintenanceRequest
    {
        return TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => Category::where('type', 'materials')->orderBy('id')->firstOrFail()->id,
            'location' => 'Library',
            'description' => 'Leaking roof',
            'is_confidential' => false,
        ], $files);
    }

    private function fileOf(MaintenanceRequest $ticket): RequestAttachment
    {
        return $ticket->attachments()->firstOrFail();
    }

    // ---- viewer ---------------------------------------------------------------------------------

    public function test_ticket_page_has_the_viewer_and_the_file_attributes(): void
    {
        $requester = $this->user('requester');
        $ticket = $this->open($requester, [$this->png(), $this->docx()]);

        $this->actingAs($requester)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('id="file-viewer"', false)
            ->assertSee('js/file-viewer.js', false)
            ->assertSee('data-file-view', false)
            ->assertSee('data-kind="image"', false)
            ->assertSee('data-kind="docx"', false)
            ->assertSee('data-delete-url', false);
    }

    public function test_viewer_files_exist(): void
    {
        $this->assertFileExists(public_path('js/file-viewer.js'));
        $this->assertFileExists(public_path('css/file-viewer.css'));
    }

    public function test_word_text_preview_returns_the_text_and_is_logged(): void
    {
        $requester = $this->user('requester');
        $file = $this->fileOf($this->open($requester, [$this->docx()]));

        $this->actingAs($requester)->get(route('attachments.text', $file))
            ->assertOk()
            ->assertJsonPath('text', "Hello Maria & Juan\nSecond line")
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertTrue(AuditLog::where('action', AuditLogger::ATTACHMENT_DOWNLOADED)->where('description', 'like', '%Previewed letter.docx%')->exists());
    }

    public function test_text_preview_is_only_for_word_files_and_only_for_people_who_may_see_the_ticket(): void
    {
        $requester = $this->user('requester');
        $ticket = $this->open($requester, [$this->png(), $this->docx()]);
        $png = $ticket->attachments()->where('mime_type', 'image/png')->firstOrFail();
        $docx = $ticket->attachments()->where('mime_type', '!=', 'image/png')->firstOrFail();

        $this->actingAs($requester)->get(route('attachments.text', $png))->assertNotFound();
        $this->actingAs($this->user('requester2'))->get(route('attachments.text', $docx))->assertNotFound();
    }

    public function test_text_preview_needs_a_signed_in_user(): void
    {
        $file = $this->fileOf($this->open($this->user('requester'), [$this->docx()]));

        $this->get(route('attachments.text', $file))->assertRedirect(route('login'));
    }

    public function test_docx_preview_class_handles_junk_without_throwing(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($tmp, 'not a zip');
        $this->assertNull(DocxPreview::text($tmp));
        unlink($tmp);
    }

    // ---- removing a file ------------------------------------------------------------------------

    public function test_the_uploader_can_remove_their_file_and_it_is_logged(): void
    {
        $requester = $this->user('requester');
        $ticket = $this->open($requester, [$this->png()]);
        $file = $this->fileOf($ticket);
        $path = $file->path;
        Storage::disk('local')->assertExists($path);

        $this->actingAs($requester)->delete(route('attachments.destroy', $file))
            ->assertRedirect(route('tickets.show', $ticket).'#attachments')
            ->assertSessionHas('status', 'File removed.');

        $this->assertDatabaseMissing('request_attachments', ['id' => $file->id]);
        Storage::disk('local')->assertMissing($path);

        $audit = AuditLog::where('action', AuditLogger::ATTACHMENT_DELETED)->firstOrFail();
        $this->assertSame($requester->id, $audit->user_id);
        $this->assertStringContainsString('photo.png', $audit->description);
        $this->assertSame($file->id, $audit->meta['attachment_id']);
        $this->assertStringNotContainsString($path, json_encode($audit->meta).$audit->description);   // never the storage path

        $this->assertTrue(RequestStatusLog::where('request_id', $ticket->id)->where('action', RequestStatusLog::ACTION_ATTACHMENT)
            ->where('note', 'like', 'Removed file%photo.png%')->exists());
    }

    public function test_maintenance_admin_can_remove_a_file_somebody_else_uploaded(): void
    {
        $ticket = $this->open($this->user('requester'), [$this->png()]);
        $file = $this->fileOf($ticket);

        $this->actingAs($this->user('maintenance'))->delete(route('attachments.destroy', $file))->assertRedirect();

        $this->assertDatabaseMissing('request_attachments', ['id' => $file->id]);
    }

    public function test_people_who_may_only_look_cannot_remove_and_strangers_do_not_even_find_it(): void
    {
        $ticket = $this->open($this->user('requester'), [$this->png()]);
        $file = $this->fileOf($ticket);

        $this->actingAs($this->user('finance'))->delete(route('attachments.destroy', $file))->assertForbidden();
        $this->actingAs($this->user('requester2'))->delete(route('attachments.destroy', $file))->assertNotFound();

        $this->assertDatabaseHas('request_attachments', ['id' => $file->id]);
        $this->assertSame(0, AuditLog::where('action', AuditLogger::ATTACHMENT_DELETED)->count());
    }

    public function test_a_guest_cannot_remove(): void
    {
        $file = $this->fileOf($this->open($this->user('requester'), [$this->png()]));

        $this->delete(route('attachments.destroy', $file))->assertRedirect(route('login'));
        $this->assertDatabaseHas('request_attachments', ['id' => $file->id]);
    }

    public function test_the_requester_cannot_remove_a_file_once_the_ticket_is_finished(): void
    {
        $requester = $this->user('requester');
        $ticket = $this->open($requester, [$this->png()]);
        $file = $this->fileOf($ticket);

        $terminal = collect(TicketStatus::cases())->first(fn ($s) => $s->isTerminal());
        $ticket->forceFill(['status' => $terminal])->save();

        $this->actingAs($requester)->delete(route('attachments.destroy', $file))->assertForbidden();
        $this->assertDatabaseHas('request_attachments', ['id' => $file->id]);
    }

    public function test_remove_button_shows_only_to_people_who_may_use_it(): void
    {
        $ticket = $this->open($this->user('requester'), [$this->png()]);

        $this->actingAs($this->user('requester'))->get(route('tickets.show', $ticket))->assertSee('data-delete-url', false);
        $this->actingAs($this->user('finance'))->get(route('tickets.show', $ticket))->assertOk()->assertDontSee('data-delete-url', false);
    }
}
