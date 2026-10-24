<?php

use App\Http\Controllers\Admin\GenreController;
use App\Http\Controllers\Admin\RealtimeSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('admin')->name('admin.')->group(function () {
    Route::apiResource('genres', GenreController::class)->except(['index']);

    // Realtime driver and keys
    Route::get('realtime', [RealtimeSettingsController::class, 'show'])->name('realtime.show');
    Route::put('realtime', [RealtimeSettingsController::class, 'update'])->name('realtime.update');
    Route::post('realtime/test', [RealtimeSettingsController::class, 'test'])->name('realtime.test');
});
