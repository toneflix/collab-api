<?php

use App\Http\Controllers\FreesoundController;
use App\Http\Controllers\SoundController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
    ->group(function () {
        Route::prefix('sounds')->name('sounds.')->group(function () {
            Route::get('status', [SoundController::class, 'status'])->name('status');
            Route::get('library', [SoundController::class, 'library'])->name('library');
            Route::get('mine', [SoundController::class, 'index'])->name('index');
            Route::post('mine', [SoundController::class, 'store'])->name('store');
            Route::put('mine/{sound}', [SoundController::class, 'update'])->name('update');
            Route::delete('mine/{sound}', [SoundController::class, 'destroy'])->name('destroy');

            Route::prefix('freesound')->name('freesound.')->group(function () {
                Route::get('connect', [FreesoundController::class, 'connect'])->name('connect');
                Route::post('callback', [FreesoundController::class, 'callback'])->name('callback');
                Route::delete('connect', [FreesoundController::class, 'disconnect'])->name('disconnect');
                Route::get('search', [FreesoundController::class, 'search'])->middleware('throttle:30,1')->name('search');
                Route::post('import', [FreesoundController::class, 'import'])->middleware('throttle:10,1')->name('import');
            });
        });

        Route::post('creator/projects/{project}/tracks/{track}/sounds', [SoundController::class, 'addToTrack'])
            ->scopeBindings()
            ->name('creator.tracks.sounds');
    });
