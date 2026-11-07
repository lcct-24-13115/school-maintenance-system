<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Imports students from the registrar CSV files (BED and College).
 *
 *   php artisan students:import "C:\path\BED.csv" "C:\path\COLLEGE.csv"
 *
 * Username (email) : <student number>@lcctanauan.edu.ph   e.g. 26-13940@lcctanauan.edu.ph
 * Temporary password: the student number                   e.g. 26-13940  (must be changed at first sign-in)
 * Safe to run again: existing students are updated, passwords are NOT reset.
 */
class ImportStudents extends Command
{
    protected $signature = 'students:import {files* : One or more CSV files}';

    protected $description = 'Import students from BED / College CSV files as Student accounts';

    public function handle(): int
    {
        // Lower bcrypt cost keeps a ~2,000 row import fast; students must change this password at first sign-in anyway.
        config(['hashing.bcrypt.rounds' => 8]);

        $roleId = Role::where('slug', Role::REQUESTER)->value('id');
        $domain = config('school.registration.email_domain') ?: 'lcctanauan.edu.ph';

        if (! $roleId) {
            $this->error('Run "php artisan db:seed" first (roles are missing).');

            return self::FAILURE;
        }

        $created = $updated = $skipped = 0;
        $seen = [];

        foreach ($this->argument('files') as $file) {
            if (! is_readable($file)) {
                $this->error("Cannot read: {$file}");

                return self::FAILURE;
            }

            $handle = fopen($file, 'r');
            $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h))), fgetcsv($handle));

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== count($header)) {
                    continue;
                }
                $r = array_combine($header, array_map('trim', $row));
                $studnum = $r['studnum'] ?? '';

                if ($studnum === '' || isset($seen[$studnum]) || ! preg_match('/^[0-9]+(?:-[0-9]+)*$/', $studnum)) {
                    $skipped++;

                    continue;
                }
                $seen[$studnum] = true;

                $mi = ($r['mi'] ?? '') !== '' ? ' '.mb_strtoupper(mb_substr($r['mi'], 0, 1)).'.' : '';
                $name = mb_convert_case(($r['fname'] ?? '').$mi.' '.($r['lname'] ?? ''), MB_CASE_TITLE, 'UTF-8');
                $name = preg_replace('/\s+/', ' ', trim($name));

                $user = User::where('email', "{$studnum}@{$domain}")->first();

                if ($user) {
                    $user->update(['name' => $name, 'employee_no' => $studnum]);
                    $updated++;

                    continue;
                }

                $user = new User([
                    'role_id' => $roleId,
                    'employee_no' => $studnum,
                    'name' => $name,
                    'email' => "{$studnum}@{$domain}",
                    'password' => $studnum,   // hashed by the model cast
                    'is_active' => true,
                ]);
                $user->forceFill(['must_change_password' => true, 'email_verified_at' => now()]);
                $user->save();
                $created++;
            }
            fclose($handle);
        }

        $this->info("Done. Created: {$created}, updated: {$updated}, skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
