<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 9: close tickets that have waited for the requester's answer for settings.autoclose_waiting_days.
// The server must run the Laravel scheduler (see APPLY-PHASE-9.txt).
Schedule::command('tickets:autoclose')->hourly()->withoutOverlapping();
