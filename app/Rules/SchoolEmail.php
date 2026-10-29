<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Phase 2: the school email rule from LCCT Ticketing.
 * Settings: config/school.php -> registration (email_domain, email_format).
 *
 *   'domain'          name@lcctanauan.edu.ph
 *   'student_number'  24-12803@lcctanauan.edu.ph  (digits, optional single hyphens between digit groups)
 *
 * Use on any form that creates or edits an account email: 'email' => ['required', 'email', new SchoolEmail].
 */
class SchoolEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = strtolower(trim((string) config('school.registration.email_domain')));

        if ($domain === '') {
            return;   // no domain restriction configured
        }

        $email = strtolower(trim((string) $value));

        if (substr_count($email, '@') !== 1 || ! str_ends_with($email, '@'.$domain)) {
            $fail("Use your school email (name@{$domain}).");

            return;
        }

        if (config('school.registration.email_format') === 'student_number') {
            $local = substr($email, 0, -(strlen($domain) + 1));

            if (! preg_match('/^[0-9]+(?:-[0-9]+)*$/', $local)) {
                $fail("Invalid email. Use your school email in this format: studentnumber@{$domain} (example: 24-12803@{$domain}).");
            }
        }
    }
}
