# FAQ Phase 4B: "source" column (starter / custom) + safe seeder.
# Run from the project root (the folder that has "artisan"):
#   powershell -ExecutionPolicy Bypass -File .\apply-4b.ps1

if (-not (Test-Path (Join-Path $PWD 'artisan'))) {
    Write-Host 'Run this from the project root (the folder with the artisan file).' -ForegroundColor Red
    exit 1
}

# Writes UTF-8 WITHOUT a BOM (a BOM at the start of a PHP file breaks pages).
function Save([string]$path, [string]$text) {
    $full = Join-Path $PWD $path
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
    [System.IO.File]::WriteAllText($full, $text, (New-Object System.Text.UTF8Encoding $false))
    Write-Host "wrote $path" -ForegroundColor Green
}

# ---------------------------------------------------------------- 1. migration
Save 'database/migrations/2026_11_15_000001_add_source_to_faqs_table.php' @'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Help & FAQ, Phase 4B: where an entry came from.
 *
 *  - starter  made by faq:seed from database/data/faq_starter.php. faq:seed may update it, until an admin edits it.
 *  - custom   written by an admin, or a starter entry an admin has edited. faq:seed never touches it.
 *
 * New rows default to "custom", so nothing an admin adds can ever be overwritten by mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->string('source', 10)->default('custom')->after('answer');
        });

        // Rows made by the seeder have no author and were never touched in the FAQ manager (the manager always
        // records created_by / updated_by). Those are the starter rows; everything else stays custom.
        DB::table('faqs')->whereNull('created_by')->whereNull('updated_by')->update(['source' => 'starter']);
    }

    public function down(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
'@

# ---------------------------------------------------------------- 2. model
Save 'app/Models/Faq.php' @'
<?php

namespace App\Models;

use App\Support\FaqText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * One question and answer of the Help & FAQ page.
 *
 *  - audience  ["all"] or role slugs. A signed-in person sees an entry when it is for "all" or lists their role.
 *  - is_public a visitor who is NOT signed in may read it. Only an "all" entry can be public.
 *  - source    "starter" (made by faq:seed) or "custom" (written or edited by an admin). faq:seed only touches starter rows.
 *  - Only published entries are ever shown to readers. The FAQ manager (Phase 3) sees the rest.
 */
class Faq extends Model
{
    public const ALL = 'all';

    /** Made by faq:seed from database/data/faq_starter.php. faq:seed may update it, until an admin edits it. */
    public const SOURCE_STARTER = 'starter';

    /** Written by an admin, or a starter entry an admin has edited. faq:seed never touches it. */
    public const SOURCE_CUSTOM = 'custom';

    /**
     * Changing one of these on a starter entry turns it into a custom one (see booted()), so the next faq:seed can
     * never overwrite the admin's work. Hiding / showing an entry and moving it up or down are NOT content changes:
     * faq:seed does not touch is_published or sort_order of an existing row anyway.
     */
    public const CONTENT_FIELDS = ['topic', 'audience', 'is_public', 'question', 'answer'];

    protected $fillable = [
        'topic', 'audience', 'is_public', 'question', 'answer', 'source', 'sort_order', 'is_published', 'created_by', 'updated_by',
    ];

    /** A row made by hand is custom unless the seeder says otherwise. */
    protected $attributes = [
        'source' => self::SOURCE_CUSTOM,
    ];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'is_public' => 'boolean',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The safety net for "never overwrite an entry an admin edited": whoever edits the content of a starter
        // entry (the FAQ manager, tinker, a test), it becomes custom in the same save. The seeder itself saves
        // quietly (no events), so its own updates never trigger this.
        static::updating(function (Faq $faq) {
            if ($faq->source === self::SOURCE_STARTER
                && ! $faq->isDirty('source')
                && $faq->isDirty(self::CONTENT_FIELDS)) {
                $faq->source = self::SOURCE_CUSTOM;
            }
        });
    }

    /** The role slugs an entry may be aimed at: every role that has a row in config/permissions.php. @return list<string> */
    public static function roleSlugs(): array
    {
        return array_values(array_map('strval', array_keys((array) config('permissions', []))));
    }

    // ---------- scopes ----------

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** Entries a visitor who is not signed in may read (the audience is checked in isVisibleTo()). */
    public function scopePublicOnly(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopeStarter(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_STARTER);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    // ---------- who sees it ----------

    /** @return list<string> the audience as a clean list; empty or missing means everyone */
    public function audienceList(): array
    {
        $list = array_values(array_unique(array_filter(
            array_map('strval', (array) $this->audience),
            fn (string $v) => $v !== ''
        )));

        return $list === [] ? [self::ALL] : $list;
    }

    public function isForEveryone(): bool
    {
        return in_array(self::ALL, $this->audienceList(), true);
    }

    /** May this person (null = a visitor who is not signed in) read this entry? Unpublished entries are never visible. */
    public function isVisibleTo(?User $user): bool
    {
        if (! $this->is_published) {
            return false;
        }

        if ($user === null) {
            return $this->is_public && $this->isForEveryone();
        }

        return $this->isForEveryone() || in_array((string) $user->role?->slug, $this->audienceList(), true);
    }

    /** What this person may read, in display order. The table is small, so the audience is filtered in PHP (same on SQLite and MySQL). @return Collection<int, Faq> */
    public static function visibleTo(?User $user): Collection
    {
        return static::query()->published()->when($user === null, fn ($q) => $q->publicOnly())->ordered()->get()
            ->filter(fn (Faq $faq) => $faq->isVisibleTo($user))
            ->values();
    }

    // ---------- answer ----------

    /** The answer as safe HTML (see FaqText). Use it with {{ $faq->answer_html }}: it is already escaped. */
    protected function answerHtml(): Attribute
    {
        return Attribute::get(fn () => new HtmlString(FaqText::toHtml((string) $this->answer)));
    }

    // ---------- relations ----------

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
'@

# ---------------------------------------------------------------- 3. seeder
Save 'database/seeders/FaqSeeder.php' @'
<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * Help & FAQ starter content (database/data/faq_starter.php).   php artisan faq:seed
 *
 * Safe to run again and again. For every row of the file, matched by its QUESTION:
 *
 *  - a STARTER row with that question exists  -> its topic, audience, public flag and answer are brought up to date
 *                                                (only when something really changed).
 *  - a CUSTOM row with that question exists   -> left alone (an admin wrote or edited it).
 *  - nothing with that question exists        -> a new starter row is added (visible, at the order from the file).
 *
 * It never deletes anything. It also never changes is_published or sort_order of a row that already exists, so an
 * admin who hid or moved a starter entry keeps that. (To remove a starter entry for good: hide it. A deleted starter
 * entry is added again by the next faq:seed.)
 */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $this->sync();
    }

    /**
     * @return array{added: int, updated: int, unchanged: int, kept: int}
     *         kept = rows of the file whose question is already used by a custom entry
     */
    public function sync(): array
    {
        /** @var list<array{0: string, 1: list<string>, 2: bool, 3: string, 4: string, 5: int}> $rows */
        $rows = require database_path('data/faq_starter.php');

        $result = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'kept' => 0];

        foreach ($rows as [$topic, $audience, $public, $question, $answer, $order]) {
            $content = [
                'topic' => $topic,
                'audience' => $audience,
                'is_public' => $public,
                'answer' => $answer,
            ];

            $starter = Faq::query()->starter()->where('question', $question)->first();

            if ($starter !== null) {
                $starter->forceFill($content);

                if ($starter->isDirty()) {
                    $starter->saveQuietly();   // quietly: this is the seeder, not an admin edit
                    $result['updated']++;
                } else {
                    $result['unchanged']++;
                }

                continue;
            }

            if (Faq::query()->where('question', $question)->exists()) {
                $result['kept']++;

                continue;
            }

            Faq::create($content + [
                'question' => $question,
                'sort_order' => $order,
                'is_published' => true,
                'source' => Faq::SOURCE_STARTER,
            ]);
            $result['added']++;
        }

        return $result;
    }
}
'@

# ---------------------------------------------------------------- 4. command
Save 'app/Console/Commands/SeedFaq.php' @'
<?php

namespace App\Console\Commands;

use Database\Seeders\FaqSeeder;
use Illuminate\Console\Command;

/**
 * Help & FAQ: bring the starter questions up to date.   php artisan faq:seed
 * Safe to run more than once: it adds what is missing, updates starter entries nobody edited, and never touches
 * an entry an admin wrote or edited in the FAQ manager. It never deletes anything.
 */
class SeedFaq extends Command
{
    protected $signature = 'faq:seed';

    protected $description = 'Add missing Help & FAQ starter entries and update the starter ones nobody has edited';

    public function handle(): int
    {
        $r = (new FaqSeeder)->sync();

        if ($r['added'] === 0 && $r['updated'] === 0) {
            $this->info('Nothing to change: the starter entries are already up to date.');
        } else {
            $this->info("Starter entries: added {$r['added']}, updated {$r['updated']}, unchanged {$r['unchanged']}.");
        }

        if ($r['kept'] > 0) {
            $this->line("Left alone: {$r['kept']} starter question(s) that an admin already wrote or edited.");
        }

        return self::SUCCESS;
    }
}
'@

# ---------------------------------------------------------------- 5. tests
Save 'tests/Feature/FaqContentTest.php' @'
<?php

namespace Tests\Feature;

use App\Models\Faq;
use Database\Seeders\FaqSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Help & FAQ Phase 4B: the "source" column and the safe starter seeder.
 * (Phase 4F adds the content checks to this same file.)
 */
class FaqContentTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: string, 1: list<string>, 2: bool, 3: string, 4: string, 5: int}> */
    private function rows(): array
    {
        return require database_path('data/faq_starter.php');
    }

    /** @return array{added: int, updated: int, unchanged: int, kept: int} */
    private function seed(): array
    {
        return (new FaqSeeder)->sync();
    }

    private function firstQuestion(): string
    {
        return $this->rows()[0][3];
    }

    public function test_an_entry_made_by_hand_is_custom_by_default(): void
    {
        $faq = Faq::create([
            'topic' => 'Getting started', 'audience' => ['all'], 'is_public' => false,
            'question' => 'A question typed by an admin?', 'answer' => 'An answer.', 'sort_order' => 10, 'is_published' => true,
        ]);

        $this->assertSame('custom', $faq->fresh()->source);
    }

    public function test_the_seeder_adds_every_row_as_starter(): void
    {
        $result = $this->seed();

        $this->assertSame(count($this->rows()), $result['added']);
        $this->assertSame(count($this->rows()), Faq::where('source', 'starter')->count());
        $this->assertSame(0, Faq::where('source', 'custom')->count());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->seed();
        $before = Faq::orderBy('id')->get()->map(fn (Faq $f) => [$f->id, $f->answer, (string) $f->updated_at])->all();

        $result = $this->seed();

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(count($this->rows()), $result['unchanged']);
        $this->assertSame(count($this->rows()), Faq::count());
        $this->assertSame($before, Faq::orderBy('id')->get()->map(fn (Faq $f) => [$f->id, $f->answer, (string) $f->updated_at])->all());
    }

    public function test_it_updates_a_starter_entry_nobody_edited(): void
    {
        $this->seed();
        [$topic, , , $question, $answer] = $this->rows()[0];

        // a query-builder update fires no model events, so the row stays "starter" (like an old version of the file)
        Faq::where('question', $question)->update(['answer' => 'Old text from an older version.', 'topic' => 'Old topic']);

        $result = $this->seed();

        $this->assertSame(1, $result['updated']);
        $faq = Faq::where('question', $question)->firstOrFail();
        $this->assertSame($answer, $faq->answer);
        $this->assertSame($topic, $faq->topic);
        $this->assertSame('starter', $faq->source);
    }

    public function test_an_entry_an_admin_edited_is_never_overwritten(): void
    {
        $this->seed();
        $question = $this->firstQuestion();

        $faq = Faq::where('question', $question)->firstOrFail();
        $faq->update(['answer' => 'My own words.', 'topic' => 'My own topic', 'audience' => ['custodian'], 'is_public' => false]);

        $this->assertSame('custom', $faq->fresh()->source, 'editing the content must turn it into a custom entry');

        $result = $this->seed();

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['kept']);
        $this->assertSame(1, Faq::where('question', $question)->count(), 'no second copy may be added');

        $faq->refresh();
        $this->assertSame('My own words.', $faq->answer);
        $this->assertSame('My own topic', $faq->topic);
        $this->assertSame(['custodian'], $faq->audience);
    }

    public function test_hiding_or_moving_a_starter_entry_is_kept_and_does_not_make_it_custom(): void
    {
        $this->seed();
        $question = $this->firstQuestion();

        $faq = Faq::where('question', $question)->firstOrFail();
        $faq->update(['is_published' => false, 'sort_order' => 999]);

        $this->assertSame('starter', $faq->fresh()->source);

        $this->seed();

        $faq->refresh();
        $this->assertFalse($faq->is_published);
        $this->assertSame(999, $faq->sort_order);
        $this->assertSame('starter', $faq->source);
    }

    public function test_custom_entries_are_never_deleted_or_changed(): void
    {
        $custom = Faq::create([
            'topic' => 'School rules', 'audience' => ['all'], 'is_public' => false,
            'question' => 'Where is the maintenance office?', 'answer' => 'Written by the admin.', 'sort_order' => 10, 'is_published' => true,
        ]);

        $this->seed();
        $this->seed();

        $this->assertSame(count($this->rows()) + 1, Faq::count());
        $this->assertSame('Written by the admin.', $custom->fresh()->answer);
        $this->assertSame('custom', $custom->fresh()->source);
    }

    public function test_a_custom_entry_with_the_same_question_stops_the_starter_copy(): void
    {
        [, , , $question] = $this->rows()[0];

        Faq::create([
            'topic' => 'Getting started', 'audience' => ['all'], 'is_public' => false,
            'question' => $question, 'answer' => 'The admin wrote this first.', 'sort_order' => 10, 'is_published' => true,
        ]);

        $result = $this->seed();

        $this->assertSame(1, $result['kept']);
        $this->assertSame(count($this->rows()) - 1, $result['added']);
        $this->assertSame(1, Faq::where('question', $question)->count());
        $this->assertSame('The admin wrote this first.', Faq::where('question', $question)->firstOrFail()->answer);
    }

    public function test_a_starter_entry_that_was_deleted_is_added_again(): void
    {
        $this->seed();
        Faq::where('question', $this->firstQuestion())->delete();

        $result = $this->seed();

        $this->assertSame(1, $result['added']);
        $this->assertSame(count($this->rows()), Faq::count());
    }

    public function test_the_command_reports_what_it_did(): void
    {
        $this->artisan('faq:seed')
            ->expectsOutputToContain('added '.count($this->rows()))
            ->assertExitCode(0);

        $this->artisan('faq:seed')
            ->expectsOutputToContain('Nothing to change')
            ->assertExitCode(0);
    }
}
'@

Write-Host ''
Write-Host 'Done. Next:' -ForegroundColor Cyan
Write-Host '  php artisan migrate'
Write-Host '  php artisan test --filter=FaqContentTest'
Write-Host '  php artisan test --filter=Faq'
