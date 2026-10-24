<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Refresh expired recommendations (up to 1000 users per run).
Schedule::command('app:recommend')->hourly()->withoutOverlapping();

// Firebase keeps every realtime event until it's deleted.
Schedule::command('realtime:prune')->hourly()->withoutOverlapping();

// Top up the shared sound library from Freesound (CC0 only).
Schedule::command('sounds:sync')->dailyAt('03:30')->withoutOverlapping()->runInBackground();

// Settle payouts whose Paystack webhook never arrived.
Schedule::command('payouts:sync')->everyThirtyMinutes()->withoutOverlapping();

// Drop track versions older than each owner's plan keeps.
Schedule::command('studio:prune-history')->dailyAt('04:00')->withoutOverlapping();
