<?php

use App\Http\Controllers\Creator\SongController;
use App\Http\Controllers\Creator\SongSuggestionController;
use App\Http\Controllers\Creator\SongVersionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
    ->prefix('creator')
    ->name('creator.')
    ->group(function () {
        Route::apiResource('songs', SongController::class);
        Route::post('songs/{song}/suggestions', [SongSuggestionController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('songs.suggestions.store');

        Route::scopeBindings()->group(function () {
            Route::get('songs/{song}/versions', [SongVersionController::class, 'index'])->name('songs.versions.index');
            Route::post('songs/{song}/versions', [SongVersionController::class, 'store'])->name('songs.versions.store');
            Route::post('songs/{song}/versions/{version}/restore', [SongVersionController::class, 'restore'])->name('songs.versions.restore');
        });
    });
