<?php

use App\Http\Controllers\RealtimeController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('realtime')->name('realtime.')->group(function () {
    Route::post('auth', [RealtimeController::class, 'auth'])->name('auth');

    // Live editor changes come in bursts while someone drags a clip, so they
    // get their own, higher limit instead of the general API one.
    Route::post('projects/{project}/tracks/{track}/live', [RealtimeController::class, 'live'])
        ->scopeBindings()
        ->withoutMiddleware(ThrottleRequests::class . ':api')
        ->middleware('throttle:realtime-live')
        ->name('live');
});
