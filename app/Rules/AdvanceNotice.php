<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * "Request must be made N (working) days in advance."
 * Usage: new AdvanceNotice('facility' | 'requisition' | 'repair')  — settings live in config/school.php
 * Note: only weekends are skipped for working days; school holidays are not (yet) excluded.
 */
class AdvanceNotice implements ValidationRule
{
    public function __construct(private string $form) {}

    public static function earliest(string $form): Carbon
    {
        $cfg = config("school.advance_notice.{$form}");

        return $cfg['type'] === 'working'
            ? Carbon::today()->addWeekdays($cfg['days'])
            : Carbon::today()->addDays($cfg['days']);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cfg = config("school.advance_notice.{$this->form}");
        $earliest = self::earliest($this->form);

        try {
            $date = Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            $fail('Please pick a valid date.');

            return;
        }

        if ($date->lt($earliest)) {
            $kind = $cfg['type'] === 'working' ? 'working days' : 'days';
            $fail("Requests must be made {$cfg['days']} {$kind} in advance. The earliest date you can choose is {$earliest->format('M j, Y (l)')}.");
        }
    }
}
