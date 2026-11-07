# FAQ Phase 4F: content tests (FaqContentTest) + one small fix in the 4D text.
# Run from the project root (the folder that has "artisan"):
#   powershell -ExecutionPolicy Bypass -File .\apply-4f.ps1

if (-not (Test-Path (Join-Path $PWD 'artisan'))) {
    Write-Host 'Run this from the project root (the folder with the artisan file).' -ForegroundColor Red
    exit 1
}

$utf8 = New-Object System.Text.UTF8Encoding $false

# ---------------------------------------------------------------- 1. fix in part2.php (PM schedule answer)
# The FAQ page trims every line, so the two indented "- Fixed / - Floating" bullets cut the numbered steps in two
# and step 7 would have shown as "1.". They become part of step 6.
$partPath = Join-Path $PWD 'database/data/faq_starter/part2.php'
if (Test-Path $partPath) {
    $part = [System.IO.File]::ReadAllText($partPath).Replace("`r`n", "`n")
    $old = @'
6. Choose **Next due date is based on**:
   - **Fixed**: the dates always follow the original calendar.
   - **Floating**: the next date is counted from the day the work was really completed.
'@
    $new = @'
6. Choose **Next due date is based on**: **Fixed** (the dates always follow the original calendar) or **Floating** (the next date is counted from the day the work was really completed).
'@
    $old = $old.Replace("`r`n", "`n")
    $new = $new.Replace("`r`n", "`n")
    if ($part.Contains($old)) {
        [System.IO.File]::WriteAllText($partPath, $part.Replace($old, $new), $utf8)
        Write-Host 'fixed    database/data/faq_starter/part2.php (PM schedule steps)' -ForegroundColor Green
    } else {
        Write-Host 'skip     part2.php (the PM schedule text is already fixed or was edited)' -ForegroundColor Yellow
    }
} else {
    Write-Host 'MISSING  database/data/faq_starter/part2.php (apply 4D first)' -ForegroundColor Red
}

# ---------------------------------------------------------------- 2. the new tests, added inside FaqContentTest
$chunk = @'
    // =================================================================================================
    // Phase 4F: the CONTENT of the starter file (database/data/faq_starter/part*.php)
    // =================================================================================================

    /** The exact topic names. The help links (4E) use these same words, so a typo here breaks a link. */
    private const TOPICS = [
        'Getting started',
        'Sending a repair request',
        'Following your request',
        'Maintenance staff',
        'Approvers and management',
        'Preventive maintenance',
        'Reports and records',
        'Troubleshooting',
    ];

    /** role slug => the seeded test account (UserSeeder, testing environment) */
    private const ACCOUNTS = [
        'requester' => 'requester',
        'department_head' => 'depthead',
        'treasurer' => 'treasurer',
        'custodian' => 'custodian',
        'maintenance_admin' => 'maintenance',
        'finance' => 'finance',
        'president' => 'president',
    ];

    private function seedPeople(): void
    {
        $this->withoutVite();

        foreach ([
            \Database\Seeders\RoleSeeder::class,
            \Database\Seeders\DepartmentSeeder::class,
            \Database\Seeders\CategorySeeder::class,
            \Database\Seeders\FacilitySeeder::class,
            \Database\Seeders\UserSeeder::class,
        ] as $seeder) {
            $this->seed($seeder);
        }
    }

    private function person(string $slug): \App\Models\User
    {
        return \App\Models\User::where('email', self::ACCOUNTS[$slug].'@lcctanauan.edu.ph')->firstOrFail();
    }

    /** The starter rows a role may read: audience "all", or the role in the list. */
    private function rowsFor(string $slug): array
    {
        return array_values(array_filter(
            $this->rows(),
            fn (array $row) => in_array(Faq::ALL, $row[1], true) || in_array($slug, $row[1], true)
        ));
    }

    // ---- the file itself ---------------------------------------------------------------------------

    public function test_the_starter_has_at_least_35_entries(): void
    {
        $this->assertGreaterThanOrEqual(35, count($this->rows()));
    }

    public function test_the_parts_are_all_joined(): void
    {
        $parts = glob(database_path('data/faq_starter/part*.php')) ?: [];
        $this->assertGreaterThanOrEqual(2, count($parts));

        $total = 0;
        foreach ($parts as $part) {
            $total += count(require $part);
        }

        $this->assertSame($total, count($this->rows()));
    }

    public function test_every_row_is_well_formed(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertIsArray($row);
            $this->assertCount(6, $row, 'a row is [topic, audience, public?, question, answer, sort_order]');
            [$topic, $audience, $public, $question, $answer, $order] = $row;

            $this->assertIsString($topic);
            $this->assertIsString($question);
            $this->assertIsString($answer);
            $this->assertIsBool($public, $question);
            $this->assertIsInt($order, $question);
            $this->assertGreaterThan(0, $order, $question);

            $this->assertNotSame('', trim($topic));
            $this->assertLessThanOrEqual(60, mb_strlen($topic), $question);
            $this->assertNotSame('', trim($question));
            $this->assertLessThanOrEqual(255, mb_strlen($question), $question);
            $this->assertSame(trim($question), $question, "the question has spaces around it: {$question}");
            $this->assertGreaterThanOrEqual(5, mb_strlen(trim($answer)), $question);
            $this->assertLessThanOrEqual(5000, mb_strlen($answer), "answer too long for the FAQ manager: {$question}");
        }
    }

    public function test_no_question_is_used_twice(): void
    {
        $seen = [];

        foreach ($this->rows() as $row) {
            $key = mb_strtolower(trim($row[3]));
            $this->assertArrayNotHasKey($key, $seen, "duplicate question: {$row[3]}");
            $seen[$key] = true;
        }
    }

    public function test_the_order_is_unique_inside_each_topic(): void
    {
        $seen = [];

        foreach ($this->rows() as $row) {
            $key = $row[0].'|'.$row[5];
            $this->assertArrayNotHasKey($key, $seen, "two entries of '{$row[0]}' have the order {$row[5]}");
            $seen[$key] = true;
        }
    }

    public function test_the_topics_are_exactly_the_planned_ones(): void
    {
        $used = array_values(array_unique(array_column($this->rows(), 0)));

        $this->assertEqualsCanonicalizing(self::TOPICS, $used);
    }

    public function test_the_audience_is_everyone_or_real_roles(): void
    {
        $roles = Faq::roleSlugs();
        $this->assertContains('maintenance_admin', $roles);
        $this->assertContains('requester', $roles);

        foreach ($this->rows() as [, $audience, $public, $question]) {
            $this->assertIsArray($audience, $question);
            $this->assertNotSame([], $audience, "empty audience: {$question}");
            $this->assertSame(array_values(array_unique($audience)), $audience, "repeated audience: {$question}");

            foreach ($audience as $a) {
                $this->assertTrue($a === Faq::ALL || in_array($a, $roles, true), "unknown audience '{$a}': {$question}");
            }

            if (in_array(Faq::ALL, $audience, true)) {
                $this->assertSame([Faq::ALL], $audience, "'all' cannot be mixed with roles: {$question}");
            }

            if ($public) {
                $this->assertSame([Faq::ALL], $audience, "a public entry must be for everyone: {$question}");
            }
        }
    }

    public function test_getting_started_forgot_password_and_the_status_meanings_are_public(): void
    {
        foreach ($this->rows() as $row) {
            if ($row[0] === 'Getting started') {
                $this->assertTrue($row[2], "a Getting started entry must be public: {$row[3]}");
            }
        }

        foreach (['I forgot my password. What do I do?', 'What do the statuses mean?'] as $question) {
            $found = array_values(array_filter($this->rows(), fn (array $row) => $row[3] === $question));
            $this->assertCount(1, $found, $question);
            $this->assertTrue($found[0][2], "must be public: {$question}");
        }
    }

    public function test_the_staff_and_management_entries_are_not_for_everyone(): void
    {
        foreach ($this->rows() as $row) {
            if (in_array($row[0], ['Maintenance staff', 'Preventive maintenance', 'Reports and records'], true)) {
                $this->assertFalse($row[2], "must not be public: {$row[3]}");
                $this->assertNotContains(Faq::ALL, $row[1], "a staff topic entry must name its roles: {$row[3]}");
            }
        }
    }

    // ---- the text of the answers ---------------------------------------------------------------------

    public function test_the_answers_use_only_the_marks_the_page_understands(): void
    {
        foreach ($this->rows() as $row) {
            [, , , $question, $answer] = $row;

            $this->assertStringNotContainsString('<', $answer, "no html in an answer: {$question}");
            $this->assertStringNotContainsString('>', $answer, "no html in an answer: {$question}");

            preg_match_all('/\]\(([^)]*)\)/', $answer, $links);
            foreach ($links[1] as $address) {
                $this->assertMatchesRegularExpression('#^/(?!/)[A-Za-z0-9_\-/.?=&;\#%]*$#', $address, "only links to this site: {$question}");
            }

            $html = (string) \App\Support\FaqText::toHtml($answer);
            $this->assertNotSame('', $html, $question);
            $this->assertStringNotContainsString('**', $html, "a bold mark was left open: {$question}");
        }
    }

    public function test_lists_are_not_indented_and_numbers_run_one_two_three(): void
    {
        foreach ($this->rows() as [, , , $question, $answer]) {
            $count = 0;

            foreach (preg_split('/\r\n|\r|\n/', $answer) ?: [] as $line) {
                // The page trims every line, so an indented "- sub item" would break the numbering of the list around it.
                $this->assertDoesNotMatchRegularExpression('/^\s+(-|\d+[.)])\s/', $line, "indented list line in: {$question}");

                if (preg_match('/^(\d+)[.)]\s/', $line, $m)) {
                    $count++;
                    $this->assertSame($count, (int) $m[1], "steps must be numbered 1, 2, 3 without a break: {$question}");
                } else {
                    $count = 0;
                }
            }
        }
    }

    // ---- seeding the full content --------------------------------------------------------------------

    public function test_the_whole_starter_is_seeded_published_and_as_starter(): void
    {
        $this->runSeeder();

        $this->assertSame(count($this->rows()), Faq::count());
        $this->assertSame(count($this->rows()), Faq::where('source', 'starter')->where('is_published', true)->count());
    }

    public function test_an_edited_entry_of_the_second_part_is_kept_and_the_rest_is_not_touched(): void
    {
        $this->runSeeder();

        $troubleshooting = array_values(array_filter($this->rows(), fn (array $row) => $row[0] === 'Troubleshooting'));
        $question = $troubleshooting[0][3];
        Faq::where('question', $question)->firstOrFail()->update(['answer' => 'Rewritten by the admin.']);

        $result = $this->runSeeder();

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['kept']);
        $this->assertSame('Rewritten by the admin.', Faq::where('question', $question)->firstOrFail()->answer);
        $this->assertSame(count($this->rows()), Faq::count());
    }

    // ---- who reads what ------------------------------------------------------------------------------

    public function test_each_role_reads_exactly_its_entries(): void
    {
        $this->seedPeople();
        $this->runSeeder();

        foreach (array_keys(self::ACCOUNTS) as $slug) {
            $seen = Faq::visibleTo($this->person($slug))->pluck('question')->all();

            $this->assertEqualsCanonicalizing(array_column($this->rowsFor($slug), 3), $seen, $slug);
            $this->assertGreaterThanOrEqual(20, count($seen), "{$slug} sees too little");
        }

        $requester = Faq::visibleTo($this->person('requester'))->pluck('question')->all();
        $maintenance = Faq::visibleTo($this->person('maintenance_admin'))->pluck('question')->all();

        $this->assertNotContains('How do I accept a task?', $requester);
        $this->assertContains('How do I accept a task?', $maintenance);
        $this->assertContains('How do I send a repair request?', $requester);
    }

    public function test_the_role_overview_entry_is_for_department_head_finance_and_president_only(): void
    {
        $this->seedPeople();
        $this->runSeeder();

        $question = 'What can I see and do with my role?';

        foreach (['department_head', 'finance', 'president'] as $slug) {
            $this->assertContains($question, Faq::visibleTo($this->person($slug))->pluck('question')->all(), $slug);
        }
        foreach (['requester', 'treasurer', 'custodian', 'maintenance_admin'] as $slug) {
            $this->assertNotContains($question, Faq::visibleTo($this->person($slug))->pluck('question')->all(), $slug);
        }
    }

    public function test_the_public_page_lists_the_getting_started_entries(): void
    {
        $this->seedPeople();
        $this->runSeeder();

        $response = $this->get(route('help.public'))->assertOk();

        foreach ($this->rows() as $row) {
            if ($row[0] === 'Getting started') {
                $response->assertSee($row[3]);
            }
        }

        $response->assertSee('I forgot my password. What do I do?');
        $response->assertSee('What do the statuses mean?');

        // nothing that is not public
        $response->assertDontSee('How do I send a repair request?');
        $response->assertDontSee('How do I accept a task?');
        $response->assertDontSee('What does the Reports page show?');

        $public = array_column(array_filter($this->rows(), fn (array $row) => $row[2]), 3);
        $this->assertEqualsCanonicalizing($public, Faq::visibleTo(null)->pluck('question')->all());
    }

    // ---- the help links (4E) point at topics that exist and that the role can read ----------------------

    public function test_every_help_link_in_the_views_uses_a_real_topic(): void
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            if (preg_match_all('/<x-help-link[^>]*\btopic="([^"]+)"/', (string) file_get_contents($file->getPathname()), $m)) {
                array_push($found, ...$m[1]);
            }
        }

        $this->assertGreaterThanOrEqual(6, count($found));

        foreach ($found as $topic) {
            $this->assertContains($topic, self::TOPICS, "a help link uses a topic that does not exist: {$topic}");
        }
    }

    public function test_each_page_with_a_help_link_has_the_right_topic_for_every_role_that_opens_it(): void
    {
        $everyone = array_keys(self::ACCOUNTS);

        $pages = [
            'repairs/create' => ['Sending a repair request', $everyone],
            'tickets/show' => ['Following your request', $everyone],
            'admin/pm-schedules/index' => ['Preventive maintenance', ['maintenance_admin', 'president']],
            'maintenance/calendar' => ['Preventive maintenance', ['maintenance_admin', 'president']],
            'pm-reports/index' => ['Preventive maintenance', ['maintenance_admin', 'president', 'finance']],
            'reports/index' => ['Reports and records', ['maintenance_admin', 'finance', 'president']],
        ];

        foreach ($pages as $view => [$topic, $roles]) {
            $source = (string) file_get_contents(resource_path('views/'.$view.'.blade.php'));
            $this->assertStringContainsString('<x-help-link topic="'.$topic.'"', $source, "{$view} must link to '{$topic}'");

            foreach ($roles as $slug) {
                $readable = array_filter($this->rowsFor($slug), fn (array $row) => $row[0] === $topic);
                $this->assertNotEmpty($readable, "{$slug} can open {$view} but reads nothing in '{$topic}'");
            }
        }
    }
'@

$testPath = Join-Path $PWD 'tests/Feature/FaqContentTest.php'
if (-not (Test-Path $testPath)) {
    Write-Host 'MISSING  tests/Feature/FaqContentTest.php (apply 4B first)' -ForegroundColor Red
    exit 1
}

$raw = [System.IO.File]::ReadAllText($testPath).Replace("`r`n", "`n")
if ($raw.Contains('test_the_starter_has_at_least_35_entries')) {
    Write-Host 'skip     FaqContentTest.php (the 4F tests are already there)' -ForegroundColor Yellow
} else {
    $end = $raw.LastIndexOf('}')
    if ($end -lt 0) { Write-Host 'NOT DONE FaqContentTest.php: no closing brace found' -ForegroundColor Red; exit 1 }

    $new = $raw.Substring(0, $end).TrimEnd() + "`n`n" + $chunk.Replace("`r`n", "`n").TrimEnd() + "`n}`n"
    $new = $new.Replace('(Phase 4F adds the content checks to this same file.)', 'Phase 4F: the content checks (35+ entries, real roles, public page, help links) are at the end.')

    [System.IO.File]::WriteAllText($testPath, $new, $utf8)
    Write-Host 'updated  tests/Feature/FaqContentTest.php' -ForegroundColor Green
}

Write-Host ''
Write-Host 'Done. Next:' -ForegroundColor Cyan
Write-Host '  php artisan faq:seed          (updates the fixed PM answer: "updated 1")'
Write-Host '  php artisan test --filter=FaqContentTest'
Write-Host '  php artisan test'
