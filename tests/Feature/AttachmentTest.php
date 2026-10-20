<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MaintenanceRequest;
use App\Models\RequestAttachment;
use App\Models\User;
use App\Rules\AdvanceNotice;
use App\Services\AttachmentService;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Phase 3: request registration (files, classification, items, confidential) + private, policy-protected attachments. */
class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1x1 PNG. finfo reads the type from the bytes, so these tiny fixtures are detected like real files. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    // ---- helpers --------------------------------------------------------------------------------

    private function seedBase(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(UserSeeder::class);
    }

    /** user('requester') => requester@lcctanauan.edu.ph */
    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function category(string $type): Category
    {
        return Category::where('type', $type)->orderBy('id')->firstOrFail();
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function jpg(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
    }

    private function webp(string $name = 'photo.webp'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "RIFF\x0C\x00\x00\x00WEBPVP8 \x00\x00\x00\x00");
    }

    private function pdf(string $name = 'quote.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    /** A minimal but structurally real .docx / .xlsx (a zip with the parts Word / Excel always write). */
    private function office(string $name, string $ext): UploadedFile
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not enabled.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ooxml');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types/>');
        $zip->addFromString($ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml', '<root/>');
        $zip->close();
        $bytes = file_get_contents($tmp);
        unlink($tmp);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function formData(User $user, array $overrides = []): array
    {
        return $overrides + [
            'department_id' => $user->department_id,
            'category_id' => $this->category('materials')->id,
            'location' => 'Grade 5 Room 204',
            'job_description' => 'Broken door lock',
            'reason' => 'Students cannot lock the room.',
            'priority' => 'first_come_first_served',
            'needed_by' => AdvanceNotice::earliest('repair')->toDateString(),
        ];
    }

    private function open(User $requester, bool $confidential = false, ?UploadedFile $file = null): MaintenanceRequest
    {
        return TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => $this->category('materials')->id,
            'location' => 'Library',
            'description' => 'Leaking roof',
            'is_confidential' => $confidential,
        ], [$file ?? $this->png()]);
    }

    // ---- validation -----------------------------------------------------------------------------

    public function test_valid_files_are_accepted(): void
    {
        $files = [
            $this->png(), $this->jpg(), $this->jpg('photo.jpeg'), $this->webp(), $this->pdf(),
            $this->office('letter.docx', 'docx'), $this->office('budget.xlsx', 'xlsx'),
            $this->jpg('My photo 2026.10.20.jpg'),   // dots in an ordinary name are fine
        ];

        foreach ($files as $file) {
            $this->assertNull(AttachmentService::check($file), $file->getClientOriginalName());
        }
    }

    public function test_a_wrong_file_type_is_rejected_with_a_clear_message(): void
    {
        $exe = UploadedFile::fake()->createWithContent('setup.exe', "MZ\x90\x00\x03\x00\x00\x00");
        $txt = UploadedFile::fake()->createWithContent('notes.txt', 'hello');
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $gif = UploadedFile::fake()->createWithContent('anim.gif', 'GIF89a'."\x01\x00\x01\x00\x00\x00\x00;");

        foreach ([$exe, $txt, $svg, $gif] as $file) {
            $message = AttachmentService::check($file);
            $this->assertNotNull($message, $file->getClientOriginalName());
            $this->assertStringContainsString('not an allowed file type', $message);
            $this->assertStringContainsString('JPG', $message);
        }
    }

    public function test_fake_and_double_extensions_are_rejected(): void
    {
        // a real image hidden behind a double extension
        $this->assertStringContainsString('more than one file extension', AttachmentService::check($this->jpg('shell.php.jpg')));
        $this->assertStringContainsString('more than one file extension', AttachmentService::check($this->png('photo.png.jpg')));

        // PHP source dressed up as an image: the extension says jpg, the contents say otherwise
        $php = UploadedFile::fake()->createWithContent('avatar.jpg', '<?php system($_GET["c"]); ?>');
        $this->assertStringContainsString('does not look like a real .jpg', AttachmentService::check($php));

        // extension and real type disagree
        $this->assertStringContainsString('does not look like a real .jpg', AttachmentService::check($this->png('photo.jpg')));
        $this->assertStringContainsString('does not look like a real .pdf', AttachmentService::check($this->png('scan.pdf')));

        // a zip that is not a Word document
        $zip = UploadedFile::fake()->createWithContent('letter.docx', "PK\x03\x04".str_repeat("\0", 40));
        $this->assertStringContainsString('does not look like a real .docx', AttachmentService::check($zip));

        // the browser's own mime type is ignored: PHP code claiming image/jpeg is still refused
        $lying = UploadedFile::fake()->create('x.jpg', 1, 'image/jpeg');
        $this->assertNotNull(AttachmentService::check($lying));
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        $big = UploadedFile::fake()->createWithContent('big.png', base64_decode(self::PNG).str_repeat('0', 6 * 1048576));

        $message = AttachmentService::check($big);

        $this->assertStringContainsString('6.0 MB', $message);
        $this->assertStringContainsString('maximum is 5 MB', $message);
    }

    public function test_the_displayed_name_is_sanitized(): void
    {
        $this->assertSame('_script_.png', AttachmentService::sanitizeName('../../etc/<script>.png'));
        $this->assertSame('evil.png', AttachmentService::sanitizeName('C:\\temp\\evil.png'));
        $this->assertSame('file', AttachmentService::sanitizeName("\x00\x01"));

        $long = AttachmentService::sanitizeName(str_repeat('a', 300).'.pdf');
        $this->assertLessThanOrEqual(100, mb_strlen($long));
        $this->assertStringEndsWith('.pdf', $long);
    }

    public function test_too_many_files_are_rejected_by_the_form(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');

        $files = array_map(fn ($i) => $this->png("p{$i}.png"), range(1, 6));

        $this->actingAs($requester)->post(route('repairs.store'), $this->formData($requester, ['files' => $files]))
            ->assertSessionHasErrors('files');

        $this->assertStringContainsString('at most 5 files', session('errors')->first('files'));
        $this->assertDatabaseCount('requests', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_form_rejects_a_bad_file_and_saves_nothing(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');

        $this->actingAs($requester)->post(route('repairs.store'), $this->formData($requester, [
            'files' => [$this->png(), UploadedFile::fake()->createWithContent('run.php.jpg', $this->jpg()->get())],
        ]))->assertSessionHasErrors('files.1');

        $this->assertStringContainsString('more than one file extension', session('errors')->first('files.1'));
        $this->assertDatabaseCount('requests', 0);
        $this->assertDatabaseCount('request_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_one_bad_file_cancels_the_whole_ticket(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');

        try {
            TicketWorkflow::open($requester, [
                'category_id' => $this->category('materials')->id, 'location' => 'Gym', 'description' => 'Net torn',
            ], [$this->png(), UploadedFile::fake()->createWithContent('x.exe', 'MZ')]);
            $this->fail('A DomainException was expected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('not an allowed file type', $e->getMessage());
        }

        $this->assertDatabaseCount('requests', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ---- filing a full ticket -------------------------------------------------------------------

    public function test_a_full_ticket_is_filed_with_files_items_classification_and_confidential_flag(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');
        $category = $this->category('materials');

        $this->actingAs($requester)->post(route('repairs.store'), $this->formData($requester, [
            'request_type' => 'materials',
            'category_id' => $category->id,
            'is_confidential' => '1',
            'items' => [
                ['item_name' => 'Door lock set', 'quantity' => '4', 'unit' => 'set', 'specification' => 'Stainless, 60mm'],
                ['item_name' => '', 'quantity' => '1', 'unit' => '', 'specification' => ''],   // empty row: ignored
            ],
            'files' => [$this->png('door-1.png'), $this->jpg('door-2.jpg'), $this->pdf('quote.pdf')],
        ]))->assertRedirect(route('dashboard'));

        $ticket = MaintenanceRequest::with(['items', 'attachments'])->firstOrFail();

        $this->assertSame('materials', $ticket->request_type);
        $this->assertSame($category->id, $ticket->category_id);
        $this->assertTrue($ticket->is_confidential);

        $this->assertCount(1, $ticket->items);
        $this->assertSame('Door lock set', $ticket->items[0]->item_name);
        $this->assertSame(4, (int) $ticket->items[0]->quantity);
        $this->assertSame('set', $ticket->items[0]->unit);
        $this->assertSame('Stainless, 60mm', $ticket->items[0]->specification);

        $this->assertCount(3, $ticket->attachments);
        $this->assertEqualsCanonicalizing(['door-1.png', 'door-2.jpg', 'quote.pdf'], $ticket->attachments->pluck('original_name')->all());

        foreach ($ticket->attachments as $file) {
            $this->assertSame('local', $file->disk);
            $this->assertSame('request', $file->kind);
            $this->assertSame($requester->id, $file->uploaded_by);
            $this->assertGreaterThan(0, $file->size_bytes);
            $this->assertMatchesRegularExpression('#^ticket-attachments/\d{4}/\d{2}/[a-z0-9]{40}\.(png|jpg|pdf)$#', $file->path);
            $this->assertStringNotContainsString(pathinfo($file->original_name, PATHINFO_FILENAME), $file->path);   // random name

            Storage::disk('local')->assertExists($file->path);
            Storage::disk('public')->assertMissing($file->path);
        }
    }

    public function test_items_are_ignored_without_a_request_type_and_validated_with_one(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');

        // no request type: the (hidden) items are dropped, the ticket is still filed
        $this->actingAs($requester)->post(route('repairs.store'), $this->formData($requester, [
            'items' => [['item_name' => 'Stray row', 'quantity' => '1']],
        ]))->assertRedirect(route('dashboard'));

        $ticket = MaintenanceRequest::firstOrFail();
        $this->assertNull($ticket->request_type);
        $this->assertFalse($ticket->is_confidential);
        $this->assertCount(0, $ticket->items);

        // with a type: a row needs a name and a whole-number quantity
        $this->actingAs($requester)->post(route('repairs.store'), $this->formData($requester, [
            'request_type' => 'materials',
            'items' => [['item_name' => '', 'quantity' => '2', 'specification' => 'only a spec']],
        ]))->assertSessionHasErrors('items.0.item_name');

        $this->actingAs($requester)->post(route('repairs.store'), $this->formData($requester, [
            'request_type' => 'materials',
            'items' => [['item_name' => 'Pipe', 'quantity' => '0']],
        ]))->assertSessionHasErrors('items.0.quantity');

        $this->assertDatabaseCount('requests', 1);
    }

    public function test_a_category_that_does_not_match_the_request_type_is_rejected(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');

        $this->actingAs($requester)->post(route('repairs.store'), $this->formData($requester, [
            'request_type' => 'equipment',
            'category_id' => $this->category('materials')->id,
        ]))->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('requests', 0);

        // matching type + category is fine
        $this->actingAs($requester)->post(route('repairs.store'), $this->formData($requester, [
            'request_type' => 'equipment',
            'category_id' => $this->category('equipment')->id,
        ]))->assertRedirect(route('dashboard'));

        $this->assertSame('equipment', MaintenanceRequest::firstOrFail()->request_type);
    }

    public function test_the_model_still_refuses_a_mismatched_category(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');

        $this->expectException(DomainException::class);

        TicketWorkflow::open($requester, [
            'category_id' => $this->category('materials')->id, 'request_type' => 'equipment',
            'location' => 'Lab', 'description' => 'Need a projector',
        ]);
    }

    public function test_the_form_still_requires_category_location_description_reason_and_date(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');

        $this->actingAs($requester)->post(route('repairs.store'), ['department_id' => $requester->department_id, 'priority' => 'first_come_first_served'])
            ->assertSessionHasErrors(['category_id', 'location', 'job_description', 'reason', 'needed_by']);
    }

    public function test_the_form_shows_the_new_fields_and_the_alias_url_works(): void
    {
        $this->seedBase();

        foreach ([route('repairs.create'), route('tickets.create')] as $url) {
            $this->actingAs($this->user('requester'))->get($url)->assertOk()
                ->assertSee('name="files[]"', false)
                ->assertSee('name="request_type"', false)
                ->assertSee('Mark as confidential')
                ->assertSee('Take photo')
                ->assertDontSee('name="photo"', false);
        }

        config(['school.filing_roles' => ['requester', 'department_head']]);

        $this->actingAs($this->user('finance'))->get(route('tickets.create'))->assertForbidden();
    }

    // ---- private storage + download route -------------------------------------------------------

    public function test_files_are_on_the_private_disk_and_storage_urls_do_not_work(): void
    {
        $this->seedBase();
        $ticket = $this->open($this->user('requester'));
        $file = $ticket->attachments->first();

        $this->assertSame('local', $file->disk);
        $this->assertFalse(config('filesystems.disks.local.serve'));

        // the link is the authorized route, never a /storage URL
        $this->assertSame(route('attachments.show', $file), $file->url());
        $this->assertStringNotContainsString('/storage', $file->url());
        $this->assertStringContainsString('download=1', $file->downloadUrl());

        // guessing the path under /storage gets nowhere, for a logged in user too
        $this->actingAs($this->user('maintenance'))->get('/storage/'.$file->path)->assertNotFound();
        $this->get('/storage/'.$file->path)->assertNotFound();
    }

    public function test_a_confidential_attachment_can_be_opened_only_by_people_who_may_see_the_ticket(): void
    {
        $this->seedBase();
        $ticket = $this->open($this->user('requester'), confidential: true);
        $file = $ticket->attachments->first();

        $this->get(route('attachments.show', $file))->assertRedirect(route('login'));

        // requester (owner) and the administrator
        foreach (['requester', 'maintenance'] as $who) {
            $this->actingAs($this->user($who))->get(route('attachments.show', $file))
                ->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }

        // an unrelated requester, finance and a department head: 404, as if the file did not exist
        foreach (['requester2', 'finance', 'depthead'] as $who) {
            $this->actingAs($this->user($who))->get(route('attachments.show', $file))->assertNotFound();
            $this->actingAs($this->user($who))->get(route('attachments.show', ['attachment' => $file, 'download' => 1]))->assertNotFound();
        }
    }

    public function test_a_normal_attachment_follows_the_same_visibility_as_the_ticket(): void
    {
        $this->seedBase();
        $file = $this->open($this->user('requester'))->attachments->first();

        $this->actingAs($this->user('finance'))->get(route('attachments.show', $file))->assertOk();           // may view all non-confidential
        $this->actingAs($this->user('requester2'))->get(route('attachments.show', $file))->assertNotFound();   // another person's ticket
    }

    public function test_images_and_pdfs_show_inline_everything_else_downloads(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');
        $ticket = $this->open($requester);

        $docx = TicketWorkflow::addAttachment($ticket, $this->office('letter.docx', 'docx'), $requester);
        $pdf = TicketWorkflow::addAttachment($ticket, $this->pdf(), $requester);
        $png = $ticket->attachments()->where('original_name', 'photo.png')->firstOrFail();

        $this->actingAs($requester);

        $r = $this->get(route('attachments.show', $png))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith('inline', $r->headers->get('Content-Disposition'));

        $r = $this->get(route('attachments.show', $pdf))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline', $r->headers->get('Content-Disposition'));

        $r = $this->get(route('attachments.show', $docx))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment', $r->headers->get('Content-Disposition'));

        // ?download=1 forces a download even for an image
        $r = $this->get(route('attachments.show', ['attachment' => $png, 'download' => 1]))->assertOk();
        $this->assertStringStartsWith('attachment', $r->headers->get('Content-Disposition'));
    }

    public function test_a_missing_file_is_a_404_and_the_board_photo_uses_the_route(): void
    {
        $this->seedBase();
        $ticket = $this->open($this->user('requester'));
        $file = $ticket->attachments->first();

        $this->assertSame(route('attachments.show', $file), $ticket->fresh('attachments')->photoUrl());

        Storage::disk('local')->delete($file->path);
        $this->actingAs($this->user('maintenance'))->get(route('attachments.show', $file))->assertNotFound();
    }

    // ---- adding files after filing --------------------------------------------------------------

    public function test_policy_for_adding_attachments(): void
    {
        $this->seedBase();
        $ticket = $this->open($this->user('requester'));
        $can = fn (string $who) => Gate::forUser($this->user($who))->allows('addAttachment', $ticket);

        $this->assertTrue($can('requester'));        // own, not closed
        $this->assertTrue($can('maintenance'));      // add_attachment capability
        $this->assertFalse($can('requester2'));      // cannot even see it
        $this->assertFalse($can('finance'));         // can see it, but not add
        $this->assertFalse($can('president'));

        TicketWorkflow::disapprove($ticket, $this->user('president'), 'No budget');
        $ticket->refresh();

        $this->assertFalse($can('requester'));       // terminal ticket: the requester can no longer add
        $this->assertTrue($can('maintenance'));
    }

    public function test_the_requester_adds_files_later_and_each_upload_is_a_history_line(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');
        $ticket = $this->open($requester);

        $this->actingAs($requester)->post(route('tickets.attachments.store', $ticket), [
            'files' => [$this->pdf('invoice.pdf'), $this->jpg('wall.jpg')],
        ])->assertRedirect();

        $this->assertCount(3, $ticket->attachments()->get());
        $this->assertSame(1, $ticket->statusLogs()->where('action', 'attachment')->count());
        $log = $ticket->statusLogs()->where('action', 'attachment')->firstOrFail();
        $this->assertStringContainsString('invoice.pdf', $log->note);
        $this->assertStringContainsString('wall.jpg', $log->note);
        $this->assertSame($requester->id, $log->user_id);

        $this->actingAs($requester)->post(route('tickets.attachments.store', $ticket), ['files' => [$this->png('third.png')]]);
        $this->assertSame(2, $ticket->statusLogs()->where('action', 'attachment')->count());

        // the detail page lists them with a download link and the uploader
        $this->actingAs($requester)->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('invoice.pdf')->assertSee('wall.jpg')->assertSee('Download')->assertSee($requester->name);
    }

    public function test_adding_files_is_refused_for_people_who_may_not(): void
    {
        $this->seedBase();
        $ticket = $this->open($this->user('requester'), confidential: true);
        $url = route('tickets.attachments.store', $ticket);

        $this->actingAs($this->user('requester2'))->post($url, ['files' => [$this->png()]])->assertNotFound();

        $open = $this->open($this->user('requester'));
        $this->actingAs($this->user('finance'))->post(route('tickets.attachments.store', $open), ['files' => [$this->png()]])->assertForbidden();

        TicketWorkflow::disapprove($open, $this->user('president'), 'No budget');
        $this->actingAs($this->user('requester'))->post(route('tickets.attachments.store', $open), ['files' => [$this->png()]])->assertForbidden();

        $this->assertCount(1, $ticket->attachments()->get());
    }

    public function test_the_same_file_rules_apply_when_adding_later(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');
        $ticket = $this->open($requester);

        $this->actingAs($requester)->post(route('tickets.attachments.store', $ticket), [
            'files' => [UploadedFile::fake()->createWithContent('notes.txt', 'hi')],
        ])->assertSessionHasErrors('files.0');

        $this->actingAs($requester)->post(route('tickets.attachments.store', $ticket), [])->assertSessionHasErrors('files');

        $this->actingAs($requester)->post(route('tickets.attachments.store', $ticket), [
            'files' => array_map(fn ($i) => $this->png("n{$i}.png"), range(1, 6)),
        ])->assertSessionHasErrors('files');

        $this->assertCount(1, $ticket->attachments()->get());
    }

    public function test_the_signed_evidence_kind_is_uploadable_by_staff_but_not_by_a_requester(): void
    {
        $this->seedBase();
        $ticket = $this->open($this->user('requester'));

        $this->actingAs($this->user('requester'))->post(route('tickets.attachments.store', $ticket), [
            'files' => [$this->pdf('signed.pdf')], 'kind' => 'signed_evidence',
        ])->assertSessionHasErrors('kind');

        $this->actingAs($this->user('maintenance'))->post(route('tickets.attachments.store', $ticket), [
            'files' => [$this->pdf('signed.pdf')], 'kind' => 'signed_evidence',
        ])->assertRedirect();

        $this->assertDatabaseHas('request_attachments', ['request_id' => $ticket->id, 'original_name' => 'signed.pdf', 'kind' => 'signed_evidence']);

        $this->expectException(DomainException::class);
        TicketWorkflow::addAttachment($ticket, $this->png('x.png'), $this->user('maintenance'), 'nonsense');
    }

    // ---- attachments:move-to-private ------------------------------------------------------------

    public function test_the_command_moves_a_legacy_public_file_to_the_private_disk(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');
        $ticket = $this->open($requester);

        Storage::disk('public')->put('ticket-attachments/legacy123.png', base64_decode(self::PNG));
        $legacy = RequestAttachment::create([
            'request_id' => $ticket->id, 'uploaded_by' => $requester->id,
            'path' => 'ticket-attachments/legacy123.png', 'disk' => 'public',
            'original_name' => 'old-photo.png', 'mime_type' => 'image/jpeg',   // the browser said jpeg; the bytes are png
        ]);

        $this->artisan('attachments:move-to-private')->expectsOutputToContain('1 file(s) moved')->assertExitCode(0);

        $legacy->refresh();
        $expected = 'ticket-attachments/'.now()->format('Y/m').'/legacy123.png';

        $this->assertSame('local', $legacy->disk);
        $this->assertSame($expected, $legacy->path);
        $this->assertSame('image/png', $legacy->mime_type);
        $this->assertGreaterThan(0, $legacy->size_bytes);
        Storage::disk('local')->assertExists($expected);
        Storage::disk('public')->assertMissing('ticket-attachments/legacy123.png');

        // the moved file is now served through the route
        $this->actingAs($requester)->get(route('attachments.show', $legacy))->assertOk();

        // running it again changes nothing
        $this->artisan('attachments:move-to-private')->expectsOutputToContain('0 file(s) moved')->assertExitCode(0);
    }

    public function test_the_command_dry_run_and_missing_files_change_nothing(): void
    {
        $this->seedBase();
        $requester = $this->user('requester');
        $ticket = $this->open($requester);

        Storage::disk('public')->put('ticket-attachments/keep.png', base64_decode(self::PNG));
        $keep = RequestAttachment::create([
            'request_id' => $ticket->id, 'uploaded_by' => $requester->id, 'path' => 'ticket-attachments/keep.png',
            'disk' => 'public', 'original_name' => 'keep.png', 'mime_type' => 'image/png',
        ]);
        $gone = RequestAttachment::create([
            'request_id' => $ticket->id, 'uploaded_by' => $requester->id, 'path' => 'ticket-attachments/gone.png',
            'disk' => 'public', 'original_name' => 'gone.png', 'mime_type' => 'image/png',
        ]);

        $this->artisan('attachments:move-to-private', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame('public', $keep->fresh()->disk);
        Storage::disk('public')->assertExists('ticket-attachments/keep.png');

        $this->artisan('attachments:move-to-private')->expectsOutputToContain('1 missing')->assertExitCode(0);
        $this->assertSame('local', $keep->fresh()->disk);
        $this->assertSame('public', $gone->fresh()->disk);   // nothing to move: left as it was, reported
    }
}
