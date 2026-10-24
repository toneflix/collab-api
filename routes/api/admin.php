<?php

use App\Http\Controllers\Admin\GenreController;
use App\Http\Controllers\Admin\PayoutController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\RealtimeSettingsController;
use App\Http\Controllers\Admin\SoundSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('admin')->name('admin.')->group(function () {
    Route::apiResource('genres', GenreController::class)->except(['index']);

    // Realtime driver and keys
    Route::get('realtime', [RealtimeSettingsController::class, 'show'])->name('realtime.show');
    Route::put('realtime', [RealtimeSettingsController::class, 'update'])->name('realtime.update');
    Route::post('realtime/test', [RealtimeSettingsController::class, 'test'])->name('realtime.test');

    // Sound library and Freesound
    Route::get('sounds', [SoundSettingsController::class, 'show'])->name('sounds.show');
    Route::put('sounds', [SoundSettingsController::class, 'update'])->name('sounds.update');
    Route::post('sounds/account', [SoundSettingsController::class, 'useAccount'])->name('sounds.account');
    Route::post('sounds/sync', [SoundSettingsController::class, 'sync'])->name('sounds.sync');
    Route::get('sounds/library', [SoundSettingsController::class, 'library'])->name('sounds.library');
    Route::post('sounds/library', [SoundSettingsController::class, 'store'])->name('sounds.store');
    Route::put('sounds/library/{sound}', [SoundSettingsController::class, 'updateSound'])->name('sounds.update-sound');
    Route::delete('sounds/library/{sound}', [SoundSettingsController::class, 'destroy'])->name('sounds.destroy');

    // Subscription plans and the features they can grant or limit
    Route::get('plans/features', [PlanController::class, 'features'])->name('plans.features');
    Route::apiResource('plans', PlanController::class);

    // Creators' withdrawal requests
    Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
    Route::post('payouts/{payout}/approve', [PayoutController::class, 'approve'])->name('payouts.approve');
    Route::post('payouts/{payout}/reject', [PayoutController::class, 'reject'])->name('payouts.reject');
    Route::post('payouts/{payout}/sync', [PayoutController::class, 'sync'])->name('payouts.sync');
});
