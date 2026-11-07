<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class ResetStudentPasswords extends Command
{
    protected $signature = 'students:reset-passwords {--force : Do not ask for confirmation}';

    protected $description = 'Reset every student password to their own ID number (employee_no)';

    public function handle(): int
    {
        config(['hashing.bcrypt.rounds' => 8]);

        $roleId = Role::where('slug', Role::REQUESTER)->value('id');

        if (! $roleId) {
            $this->error('Student role not found. Run "php artisan db:seed" first.');

            return self::FAILURE;
        }

        $query = User::where('role_id', $roleId)->whereNotNull('employee_no')->where('employee_no', '!=', '');
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No student accounts found. Run students:import first.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Reset the password of {$total} students to their ID number? Passwords they already changed will be lost.")) {
            $this->info('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        $done = 0;

        $query->chunkById(200, function ($students) use (&$done) {
            foreach ($students as $user) {
                $user->forceFill([
                    'password' => $user->employee_no,
                    'must_change_password' => true,
                    'password_changed_at' => null,
                    'is_active' => true,
                ])->save();
                $done++;
            }
        });

        AuditLogger::log(AuditLogger::USER_PASSWORD_RESET, "Reset {$done} student passwords to their ID number (artisan students:reset-passwords)");

        $this->info("Done. {$done} student passwords were set to their ID number.");

        return self::SUCCESS;
    }
}