# FAQ Phase 4E: the <x-help-link> component + the links on 6 pages.
# Run from the project root (the folder that has "artisan"):
#   powershell -ExecutionPolicy Bypass -File .\apply-4e.ps1

if (-not (Test-Path (Join-Path $PWD 'artisan'))) {
    Write-Host 'Run this from the project root (the folder with the artisan file).' -ForegroundColor Red
    exit 1
}

$utf8 = New-Object System.Text.UTF8Encoding $false

# Writes UTF-8 WITHOUT a BOM, with LF line endings (a BOM at the start of a PHP file breaks pages).
function Save([string]$path, [string]$text) {
    $full = Join-Path $PWD $path
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
    $text = $text.Replace("`r`n", "`n")
    [System.IO.File]::WriteAllText($full, $text, $utf8)
    Write-Host "wrote $path" -ForegroundColor Green
}

# Adds ONE line  <x-help-link topic="..." row />  to a view, right after an anchor line. Safe to run twice.
#   $anchor      text that the anchor line contains (the first line that has it)
#   $expectNext  if given, the line AFTER the anchor must be exactly this (then the link goes after that line)
#   $deeper      the anchor opens a wrapper: indent the new line one level deeper
function AddHelpLink([string]$path, [string]$anchor, [string]$topic, [string]$expectNext = '', [bool]$deeper = $false) {
    $full = Join-Path $PWD $path
    if (-not (Test-Path $full)) { Write-Host "MISSING  $path" -ForegroundColor Red; return }
    $raw = [System.IO.File]::ReadAllText($full)
    if ($raw.Contains('x-help-link')) { Write-Host "skip     $path (already has the link)" -ForegroundColor Yellow; return }

    $nl = "`n"
    if ($raw.Contains("`r`n")) { $nl = "`r`n" }
    $lines = New-Object 'System.Collections.Generic.List[string]'
    $lines.AddRange([string[]][regex]::Split($raw, '\r?\n'))

    $idx = -1
    for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i].Contains($anchor)) { $idx = $i; break } }
    if ($idx -lt 0) { Write-Host "NOT DONE $path : the anchor line was not found (file changed?)" -ForegroundColor Red; return }

    $at = $idx
    if ($expectNext -ne '') {
        if (($idx + 1) -ge $lines.Count -or $lines[$idx + 1].Trim() -ne $expectNext) {
            Write-Host "NOT DONE $path : the line after the anchor is not '$expectNext'" -ForegroundColor Red
            return
        }
        $at = $idx + 1
    }

    $indent = [regex]::Match($lines[$at], '^\s*').Value
    if ($deeper) { $indent = $indent + '    ' }
    $lines.Insert($at + 1, $indent + '<x-help-link topic="' + $topic + '" row />')

    [System.IO.File]::WriteAllText($full, ($lines -join $nl), $utf8)
    Write-Host "linked   $path  ->  $topic" -ForegroundColor Green
}

# ---------------------------------------------------------------- 1. the component
Save 'resources/views/components/help-link.blade.php' @'
@props(['topic', 'label' => 'Help with this page', 'row' => false])

{{-- FAQ Phase 4E: a link to the Help & FAQ page, already filtered to one topic.
     Usage:  <x-help-link topic="Reports and records" row />
       topic  the exact topic name of the FAQ (see database/data/faq_starter/*.php)
       label  the link text (default "Help with this page")
       row    true = wrap it in a right-aligned row (class help-link-row), so it sits on its own line under the title
     It is shown only to a signed-in person who may read at least one published entry of that topic, so it can never
     lead to an empty page (topic hidden by the FAQ manager, or meant for other roles). The pages that use it are
     already limited to the roles that may open them. --}}
@php
    $helpUser = auth()->user();
    $helpShow = $helpUser !== null && \App\Models\Faq::visibleTo($helpUser)->contains('topic', $topic);
@endphp
@if ($helpShow)
    @if ($row)<div class="help-link-row">@endif
    <a href="{{ route('help.index', ['topic' => $topic]) }}" {{ $attributes->merge(['class' => 'help-link']) }}>
        <i class="fa-regular fa-circle-question"></i> {{ $label }}
    </a>
    @if ($row)</div>@endif
@endif
'@

# ---------------------------------------------------------------- 2. its style (added once to the end of phase6.css)
$cssText = @'
/* FAQ 4E help-link */
.help-link-row{display:flex;justify-content:flex-end;margin:0 0 12px}
.help-link{display:inline-flex;align-items:center;gap:6px;font-size:13.5px;font-weight:700;color:var(--deep);text-decoration:none}
.help-link:hover{text-decoration:underline}
@media (pointer:coarse){.help-link{min-height:38px}}
'@
$cssPath = Join-Path $PWD 'public/css/phase6.css'
if (-not (Test-Path $cssPath)) {
    Write-Host 'MISSING  public/css/phase6.css' -ForegroundColor Red
} elseif ([System.IO.File]::ReadAllText($cssPath).Contains('FAQ 4E help-link')) {
    Write-Host 'skip     public/css/phase6.css (style already there)' -ForegroundColor Yellow
} else {
    $css = [System.IO.File]::ReadAllText($cssPath)
    if (-not $css.EndsWith("`n")) { $css += "`n" }
    [System.IO.File]::WriteAllText($cssPath, $css + "`n" + $cssText.Replace("`r`n", "`n"), $utf8)
    Write-Host 'updated  public/css/phase6.css' -ForegroundColor Green
}

# ---------------------------------------------------------------- 3. the six pages
AddHelpLink 'resources/views/repairs/create.blade.php'            'Request must be made 5 working days in advance.' 'Sending a repair request'
AddHelpLink 'resources/views/tickets/show.blade.php'              'fa-arrow-left'                                    'Following your request' '</p>'
AddHelpLink 'resources/views/admin/pm-schedules/index.blade.php'  '<div class="content p6-wrap">'                     'Preventive maintenance' '' $true
AddHelpLink 'resources/views/maintenance/calendar.blade.php'      '<div class="content p6-wrap">'                     'Preventive maintenance' '' $true
AddHelpLink 'resources/views/pm-reports/index.blade.php'          '<div class="content p6-wrap">'                     'Preventive maintenance' '' $true
AddHelpLink 'resources/views/reports/index.blade.php'             '<div class="content p6-wrap">'                     'Reports and records' '' $true

# ---------------------------------------------------------------- 4. the test
Save 'tests/Feature/HelpLinkTest.php' @'
<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Faq;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Services\TicketWorkflow;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Help & FAQ Phase 4E: the <x-help-link> component and where it is placed.
 * It must show only where the person already may open the page, and only when the topic has something to read.
 */
class HelpLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        $this->seed(DepartmentSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(FacilitySeeder::class);
        $this->seed(UserSeeder::class);
        (new FaqSeeder)->sync();   // the real starter content
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@lcctanauan.edu.ph')->firstOrFail();
    }

    private function link(string $topic): string
    {
        return route('help.index', ['topic' => $topic]);
    }

    private function ticket(): MaintenanceRequest
    {
        $requester = $this->user('requester');

        return TicketWorkflow::open($requester, [
            'department_id' => $requester->department_id,
            'category_id' => Category::query()->firstOrFail()->id,
            'location' => 'Grade 5 Room 204',
            'description' => 'Leaking faucet',
        ]);
    }

    private function rendered(string $blade, ?User $as): string
    {
        if ($as !== null) {
            $this->actingAs($as);
        }

        return trim(Blade::render($blade));
    }

    // ---- the component ---------------------------------------------------------------------------

    public function test_the_link_points_to_the_topic_page(): void
    {
        $html = $this->rendered('<x-help-link topic="Sending a repair request" />', $this->user('requester'));

        $this->assertStringContainsString($this->link('Sending a repair request'), $html);
        $this->assertStringContainsString('Help with this page', $html);
        $this->assertStringContainsString('class="help-link"', $html);
        $this->assertStringNotContainsString('help-link-row', $html);
    }

    public function test_the_label_and_the_row_can_be_set(): void
    {
        $html = $this->rendered('<x-help-link topic="Sending a repair request" label="Need help?" row />', $this->user('requester'));

        $this->assertStringContainsString('Need help?', $html);
        $this->assertStringContainsString('help-link-row', $html);
    }

    public function test_the_topic_page_opens_on_that_topic(): void
    {
        $this->actingAs($this->user('requester'))
            ->get($this->link('Following your request'))
            ->assertOk()
            ->assertSee('What do the statuses mean?');
    }

    public function test_a_visitor_gets_no_link(): void
    {
        $this->assertSame('', $this->rendered('<x-help-link topic="Getting started" />', null));
    }

    public function test_nothing_is_shown_when_the_person_cannot_read_the_topic(): void
    {
        // "Reports and records" is written for the maintenance admin, finance and the president only.
        $this->assertSame('', $this->rendered('<x-help-link topic="Reports and records" />', $this->user('requester')));
        $this->assertNotSame('', $this->rendered('<x-help-link topic="Reports and records" />', $this->user('finance')));

        // The PM report is read by finance too, so finance gets the PM topic, a requester does not.
        $this->assertNotSame('', $this->rendered('<x-help-link topic="Preventive maintenance" />', $this->user('finance')));
        $this->assertSame('', $this->rendered('<x-help-link topic="Preventive maintenance" />', $this->user('requester')));
    }

    public function test_an_unknown_topic_gives_no_link(): void
    {
        $this->assertSame('', $this->rendered('<x-help-link topic="No such topic" />', $this->user('maintenance')));
    }

    public function test_hiding_every_entry_of_a_topic_removes_the_link(): void
    {
        $this->assertNotSame('', $this->rendered('<x-help-link topic="Reports and records" />', $this->user('president')));

        Faq::where('topic', 'Reports and records')->update(['is_published' => false]);

        $this->assertSame('', $this->rendered('<x-help-link topic="Reports and records" />', $this->user('president')));
    }

    // ---- where it is placed ----------------------------------------------------------------------

    public function test_the_repair_request_form_has_the_link(): void
    {
        $this->actingAs($this->user('requester'))
            ->get(route('repairs.create'))
            ->assertOk()
            ->assertSee($this->link('Sending a repair request'), false);
    }

    public function test_the_ticket_page_has_the_link(): void
    {
        $ticket = $this->ticket();

        foreach (['requester', 'maintenance', 'president'] as $who) {
            $this->actingAs($this->user($who))
                ->get(route('tickets.show', $ticket))
                ->assertOk()
                ->assertSee($this->link('Following your request'), false);
        }
    }

    public function test_the_pm_schedules_and_the_pm_calendar_have_the_link_for_the_roles_that_open_them(): void
    {
        foreach (['maintenance', 'president'] as $who) {
            foreach (['admin.pm-schedules.index', 'maintenance-calendar.index'] as $route) {
                $this->actingAs($this->user($who))
                    ->get(route($route))
                    ->assertOk()
                    ->assertSee($this->link('Preventive maintenance'), false);
            }
        }
    }

    public function test_the_pm_report_has_the_link_for_every_role_that_opens_it(): void
    {
        foreach (['maintenance', 'president', 'finance'] as $who) {
            $this->actingAs($this->user($who))
                ->get(route('pm-reports.index'))
                ->assertOk()
                ->assertSee($this->link('Preventive maintenance'), false);
        }
    }

    public function test_the_reports_page_has_the_link_for_every_role_that_opens_it(): void
    {
        foreach (['maintenance', 'president', 'finance'] as $who) {
            $this->actingAs($this->user($who))
                ->get(route('reports.index'))
                ->assertOk()
                ->assertSee($this->link('Reports and records'), false);
        }
    }

    public function test_the_link_is_not_a_way_into_a_page_the_role_cannot_open(): void
    {
        $requester = $this->user('requester');

        foreach (['reports.index', 'pm-reports.index', 'admin.pm-schedules.index', 'maintenance-calendar.index'] as $route) {
            $this->actingAs($requester)->get(route($route))->assertForbidden();
        }
    }
}
'@

Write-Host ''
Write-Host 'Done. Next:' -ForegroundColor Cyan
Write-Host '  php artisan view:clear'
Write-Host '  php artisan test --filter=HelpLinkTest'
Write-Host '  php artisan test --filter=Faq'
