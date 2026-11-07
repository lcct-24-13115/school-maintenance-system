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

// Phase 12: escalate open tickets that are past their target date (not the ones waiting for the requester).
Schedule::command('tickets:escalate')->hourly()->withoutOverlapping();

// PM Scheduler (Phase 2): make the preventive maintenance tickets that are due. Needs the Laravel scheduler running (see the steps).
Schedule::command('pm:generate --trigger=schedule')->dailyAt('01:00')->timezone('Asia/Manila')->withoutOverlapping();

// PM integration (Phase C): new-ticket alerts, reminders, overdue marking and escalation to the supervisor.
Schedule::command('pm:notify')->dailyAt('07:00')->timezone('Asia/Manila')->withoutOverlapping();
