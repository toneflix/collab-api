<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Refresh expired recommendations (up to 1000 users per run).
Schedule::command('app:recommend')
    ->hourly()
    ->withoutOverlapping();

// Firebase keeps every realtime event until it's deleted.
Schedule::command('realtime:prune')
    ->hourly()
    ->withoutOverlapping();

// Top up the shared sound library from Freesound (CC0 only).
Schedule::command('sounds:sync')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();

// Settle payouts whose provider webhook never arrived.
Schedule::command('payouts:sync')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// Drop track versions older than each owner's plan keeps.
Schedule::command('studio:prune-history')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Refund AI jobs that stopped without finishing.
Schedule::command('ai:sweep')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Start queued content agent runs and keep the active one moving.
Schedule::command('agent:tick')
    ->everyMinute()
    ->withoutOverlapping();

// Hold paid streams from listeners who mostly play one creator.
Schedule::command('earnings:check-streams')
    ->dailyAt('02:30')
    ->withoutOverlapping();

// Pay creators last month's stream earnings.
Schedule::command('earnings:settle-streams')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping();

// Delete the files of music videos and track canvases whose rows are gone.
Schedule::command('videos:prune-files')
    ->dailyAt('04:30')
    ->withoutOverlapping();

// Erase deleted accounts whose owners did not sign in during the grace period.
Schedule::command('accounts:purge')
    ->dailyAt('02:30')
    ->withoutOverlapping();
